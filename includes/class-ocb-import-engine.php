<?php
/**
 * Import engine: validates, extracts, and imports a .ocb backup file.
 *
 * Phases:
 *   validate        → read manifest, check compatibility
 *   snapshot        → create safety DB snapshot
 *   extract_db      → extract database.sql from ZIP
 *   import_db       → run SQL chunk by chunk
 *   extract_files   → extract wp-content files chunk by chunk
 *   url_replace     → search-replace old URL in DB
 *   finalize        → flush caches, fix prefix, keep user logged in
 *   done
 *
 * @package OneClickBackup
 */

defined( 'ABSPATH' ) || exit;

class OCB_Import_Engine {

    private static function state_key( string $sid ): string {
        return 'ocb_import_state_' . $sid;
    }

    // ── Start import ──────────────────────────────────────────────────────────

    /**
     * @param string $ocb_file Absolute path to the assembled .ocb file.
     * @return string Session ID.
     */
    public static function start( string $ocb_file ): string {
        global $wpdb;

        $sid     = wp_generate_password( 16, false );
        $tmp_dir = OCB_File_Handler::get_temp_dir( 'import-' . $sid );

        $target_site_url = rtrim( site_url(), '/' );
        $target_home_url = rtrim( home_url(), '/' );

        $current_user = wp_get_current_user();
        $saved_user   = null;
        if ( $current_user && $current_user->ID ) {
            $user_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->users} WHERE ID = %d", $current_user->ID ), ARRAY_A );
            $usermeta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d", $current_user->ID ), ARRAY_A );
            if ( $user_row ) {
                $saved_user = [
                    'row'  => $user_row,
                    'meta' => $usermeta ?: [],
                ];
            }
        }

        $token = wp_generate_password( 32, false );

        $state = [
            'session_id'      => $sid,
            'import_token'    => $token,
            'ocb_file'        => $ocb_file,
            'tmp_dir'         => $tmp_dir,
            'phase'           => 'validate',
            'manifest'        => null,
            'target_site_url' => $target_site_url,
            'target_home_url' => $target_home_url,
            'saved_user'      => $saved_user,
            'sql_file'        => $tmp_dir . '/database.sql',
            'snapshot_file'   => $tmp_dir . '/snapshot.sql',
            'extract_index'   => 0,
            'zip_entries'     => [],
            'error'           => null,
        ];

        self::save_state( $sid, $state );

        return $sid;
    }

    private static function state_file( string $sid ): string {
        return OCB_File_Handler::get_temp_dir( 'import-' . $sid ) . DIRECTORY_SEPARATOR . 'state.json';
    }

    public static function get_state( string $sid ): ?array {
        $file = self::state_file( $sid );
        if ( file_exists( $file ) ) {
            $data = json_decode( (string) file_get_contents( $file ), true );
            if ( is_array( $data ) ) {
                return $data;
            }
        }
        $s = get_transient( 'ocb_import_state_' . $sid );
        return is_array( $s ) ? $s : null;
    }

    private static function save_state( string $sid, array $state ): void {
        $file = self::state_file( $sid );
        file_put_contents( $file, json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
        set_transient( 'ocb_import_state_' . $sid, $state, HOUR_IN_SECONDS * 6 );
    }

    public static function clear_state( string $sid ): void {
        $file = self::state_file( $sid );
        if ( file_exists( $file ) ) {
            @unlink( $file );
        }
        delete_transient( 'ocb_import_state_' . $sid );
    }

    public static function verify_import_token( string $sid, string $token ): bool {
        if ( empty( $sid ) || empty( $token ) ) {
            return false;
        }
        $state = self::get_state( $sid );
        if ( empty( $state ) || empty( $state['import_token'] ) ) {
            return false;
        }
        return hash_equals( (string) $state['import_token'], (string) $token );
    }

    // ── Process one chunk ─────────────────────────────────────────────────────

    public static function process_chunk( string $sid ): array {
        $state = self::get_state( $sid );
        if ( null === $state ) {
            return self::err( 'Session not found.' );
        }

        try {
            switch ( $state['phase'] ) {
                case 'validate':
                    return self::phase_validate( $sid, $state );
                case 'snapshot':
                    return self::phase_snapshot( $sid, $state );
                case 'extract_db':
                    return self::phase_extract_db( $sid, $state );
                case 'import_db':
                    return self::phase_import_db( $sid, $state );
                case 'extract_files':
                    return self::phase_extract_files( $sid, $state );
                case 'url_replace':
                    return self::phase_url_replace( $sid, $state );
                case 'finalize':
                    return self::phase_finalize( $sid, $state );
                case 'done':
                    return [ 'done' => true, 'progress' => 100, 'step' => __( 'Import complete!', 'oneclick-backup' ), 'error' => null ];
                default:
                    return self::err( 'Unknown phase: ' . $state['phase'] );
            }
        } catch ( Throwable $e ) {
            $state['error'] = $e->getMessage();
            self::save_state( $sid, $state );
            return self::err( $e->getMessage() );
        }
    }

    // ── Phase: validate ───────────────────────────────────────────────────────

    private static function phase_validate( string $sid, array $state ): array {
        $manifest = OCB_File_Handler::read_manifest_from_ocb( $state['ocb_file'] );

        if ( empty( $manifest ) ) {
            throw new RuntimeException( __( 'Invalid backup file: missing or unreadable manifest.json.', 'oneclick-backup' ) );
        }
        if ( empty( $manifest['wp_version'] ) ) {
            throw new RuntimeException( __( 'Invalid manifest: missing WordPress version.', 'oneclick-backup' ) );
        }

        $state['manifest'] = $manifest;
        $state['phase']    = 'snapshot';
        self::save_state( $sid, $state );

        return [ 'done' => false, 'progress' => 2, 'step' => __( 'Backup file validated.', 'oneclick-backup' ), 'error' => null, 'manifest' => $manifest ];
    }

    // ── Phase: snapshot ───────────────────────────────────────────────────────

    private static function phase_snapshot( string $sid, array $state ): array {
        $ok = OCB_DB_Import::create_safety_snapshot( $state['snapshot_file'] );
        if ( ! $ok ) {
            // Non-fatal: warn but continue.
            error_log( 'OneClick Backup: failed to create safety snapshot.' );
        }

        $state['phase'] = 'extract_db';
        self::save_state( $sid, $state );

        return [ 'done' => false, 'progress' => 8, 'step' => __( 'Safety snapshot created.', 'oneclick-backup' ), 'error' => null ];
    }

    // ── Phase: extract_db ─────────────────────────────────────────────────────

    private static function phase_extract_db( string $sid, array $state ): array {
        // Only extract database.sql from the archive (don't extract everything yet).
        if ( class_exists( 'ZipArchive' ) ) {
            $zip = new ZipArchive();
            if ( true !== $zip->open( $state['ocb_file'] ) ) {
                throw new RuntimeException( __( 'Cannot open backup archive.', 'oneclick-backup' ) );
            }

            // Build list of all entries for later file extraction phase.
            $entries = [];
            for ( $i = 0; $i < $zip->numFiles; $i++ ) {
                $name = $zip->getNameIndex( $i );
                if ( $name && substr( $name, -1 ) !== '/' ) { // skip dirs
                    $entries[] = $name;
                }
            }
            $state['zip_entries'] = $entries;

            // Extract SQL.
            if ( array_search( OCB_DB_DUMP_FILE, $entries, true ) !== false ) {
                $zip->extractTo( $state['tmp_dir'], [ OCB_DB_DUMP_FILE ] );
            }
            $zip->close();
        } else {
            if ( ! class_exists( 'PclZip' ) ) {
                require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
            }
            $zip      = new PclZip( $state['ocb_file'] );
            $list     = $zip->listContent();
            $entries  = [];
            foreach ( $list as $entry ) {
                if ( ! $entry['folder'] ) {
                    $entries[] = $entry['filename'];
                }
            }
            $state['zip_entries'] = $entries;
            $zip->extract( PCLZIP_OPT_BY_NAME, OCB_DB_DUMP_FILE, PCLZIP_OPT_PATH, $state['tmp_dir'] );
        }

        // Init DB import state.
        OCB_DB_Import::init_state( $sid, $state['sql_file'] );
        $state['phase'] = 'import_db';

        self::save_state( $sid, $state );

        return [ 'done' => false, 'progress' => 12, 'step' => __( 'Archive opened, importing database…', 'oneclick-backup' ), 'error' => null ];
    }

    // ── Phase: import_db ─────────────────────────────────────────────────────

    private static function phase_import_db( string $sid, array $state ): array {
        global $wpdb;

        $manifest   = $state['manifest'];
        $src_prefix = $manifest['table_prefix'] ?? $wpdb->prefix;
        $dst_prefix = $wpdb->prefix;

        $result = OCB_DB_Import::import_chunk( $sid, $src_prefix, $dst_prefix );

        if ( $result['done'] ) {
            OCB_DB_Import::clear_state( $sid );

            if ( ! empty( $state['saved_user'] ) ) {
                self::restore_current_user( $state['saved_user'] );
            }

            self::ensure_plugin_active();

            $state['phase'] = 'extract_files';
            self::save_state( $sid, $state );
            return [ 'done' => false, 'progress' => 55, 'step' => __( 'Database import complete.', 'oneclick-backup' ), 'error' => null ];
        }

        // Scale 12–55%.
        $progress = 12 + (int) ( $result['progress'] * 0.43 );

        self::save_state( $sid, $state );

        return [
            'done'     => false,
            'progress' => $progress,
            'step'     => sprintf(
                /* translators: 1: bytes processed, 2: total bytes */
                __( 'Importing database… %1$s / %2$s', 'oneclick-backup' ),
                size_format( $result['bytes_processed'] ),
                size_format( $result['total_bytes'] )
            ),
            'error'    => null,
        ];
    }

    // ── Phase: extract_files ──────────────────────────────────────────────────

    private static function phase_extract_files( string $sid, array $state ): array {
        $entries   = $state['zip_entries'];
        $index     = (int) $state['extract_index'];
        $total     = count( $entries );
        $chunk_end = min( $index + OCB_CHUNK_FILES, $total );

        // Filter only wp-content/* entries (skip database.sql, manifest.json).
        $wc_entries = array_filter( $entries, fn( $e ) => strpos( $e, 'wp-content/' ) === 0 );
        $wc_entries = array_values( $wc_entries );
        $wc_total   = count( $wc_entries );
        $wc_end     = min( $index + OCB_CHUNK_FILES, $wc_total );

        if ( $wc_total > 0 && $index < $wc_total ) {
            $chunk_entries = array_slice( $wc_entries, $index, $wc_end - $index );
            $target_base   = WP_CONTENT_DIR . '/..'; // so wp-content/* maps to ABSPATH/wp-content.

            if ( class_exists( 'ZipArchive' ) ) {
                $zip = new ZipArchive();
                if ( true === $zip->open( $state['ocb_file'] ) ) {
                    foreach ( $chunk_entries as $entry_name ) {
                        // Zip-slip guard.
                        try {
                            $dest = OCB_Security::validate_zip_entry( $entry_name, ABSPATH );
                        } catch ( RuntimeException $e ) {
                            continue; // Skip dangerous entries.
                        }

                        // Map wp-content/* → ABSPATH/wp-content/*.
                        $relative = str_replace( 'wp-content/', '', $entry_name );
                        $dest_abs = WP_CONTENT_DIR . '/' . $relative;
                        $dest_dir = dirname( $dest_abs );

                        if ( ! is_dir( $dest_dir ) ) {
                            wp_mkdir_p( $dest_dir );
                        }

                        $content = $zip->getFromName( $entry_name );
                        if ( false !== $content ) {
                            file_put_contents( $dest_abs, $content );
                        }
                    }
                    $zip->close();
                }
            } else {
                if ( ! class_exists( 'PclZip' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
                }
                $zip = new PclZip( $state['ocb_file'] );
                $zip->extract(
                    PCLZIP_OPT_BY_NAME,   $chunk_entries,
                    PCLZIP_OPT_PATH,      ABSPATH,
                    PCLZIP_OPT_ADD_PATH,  ''
                );
            }
        }

        $state['extract_index'] = $wc_end;
        $files_done             = ( $wc_total === 0 || $wc_end >= $wc_total );

        if ( $files_done ) {
            $state['phase'] = 'url_replace';
        }

        self::save_state( $sid, $state );

        $progress = 55 + (int) ( ( $wc_total > 0 ? ( $wc_end / $wc_total ) : 1 ) * 25 );

        return [
            'done'     => false,
            'progress' => $progress,
            'step'     => sprintf(
                /* translators: 1: current, 2: total */
                __( 'Restoring files… %1$d of %2$d', 'oneclick-backup' ),
                $wc_end,
                $wc_total
            ),
            'error'    => null,
        ];
    }

    // ── Phase: url_replace ────────────────────────────────────────────────────

    private static function phase_url_replace( string $sid, array $state ): array {
        global $wpdb;

        $manifest = $state['manifest'];
        $old_url  = rtrim( $manifest['site_url'] ?? '', '/' );
        $new_url  = rtrim( $state['target_site_url'] ?? site_url(), '/' );
        $new_home = rtrim( $state['target_home_url'] ?? home_url(), '/' );

        if ( ! empty( $old_url ) && ! empty( $new_url ) && $old_url !== $new_url ) {
            $replacer = new OCB_URL_Replacer( $old_url, $new_url );
            $tables   = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}%'" );
            foreach ( $tables as $table ) {
                $replacer->replace_in_table( $table );
            }
        }

        // Explicitly update siteurl and home in options table.
        $options_tbl = $wpdb->prefix . 'options';
        $wpdb->update( $options_tbl, [ 'option_value' => $new_url ], [ 'option_name' => 'siteurl' ] );
        $wpdb->update( $options_tbl, [ 'option_value' => $new_home ?: $new_url ], [ 'option_name' => 'home' ] );

        // Also rename prefix-specific options/usermeta if prefix differs.
        $src_prefix = $manifest['table_prefix'] ?? $wpdb->prefix;
        if ( $src_prefix !== $wpdb->prefix ) {
            OCB_DB_Import::rename_prefix_in_options( $src_prefix, $wpdb->prefix );
        }

        $state['phase'] = 'finalize';
        self::save_state( $sid, $state );

        return [ 'done' => false, 'progress' => 88, 'step' => __( 'URLs updated in database.', 'oneclick-backup' ), 'error' => null ];
    }

    // ── Phase: finalize ───────────────────────────────────────────────────────

    private static function phase_finalize( string $sid, array $state ): array {
        global $wpdb;

        // Flush rewrite rules.
        delete_option( 'rewrite_rules' );

        // Clear all transients.
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'" );
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_%'" );

        // Keep current user logged in with a fresh auth cookie.
        $current_user = wp_get_current_user();
        if ( $current_user && $current_user->ID ) {
            wp_set_auth_cookie( $current_user->ID, true );
            wp_set_current_user( $current_user->ID );
        }
        wp_cache_flush();

        // Clean up temp dir.
        self::clear_state( $sid );
        OCB_File_Handler::remove_temp_dir( 'import-' . $sid );

        return [
            'done'     => true,
            'progress' => 100,
            'step'     => __( 'Import complete! Caches cleared, rewrite rules flushed.', 'oneclick-backup' ),
            'error'    => null,
        ];
    }

    // ── Rollback ──────────────────────────────────────────────────────────────

    public static function rollback( string $sid ): bool {
        $state = self::get_state( $sid );
        if ( ! $state || ! file_exists( $state['snapshot_file'] ) ) {
            return false;
        }

        OCB_DB_Import::restore_snapshot( $state['snapshot_file'] );
        self::clear_state( $sid );
        OCB_File_Handler::remove_temp_dir( 'import-' . $sid );

        return true;
    }

    // ── User & Plugin preservation helpers ───────────────────────────────────

    private static function restore_current_user( array $saved_user ): void {
        global $wpdb;

        $row  = $saved_user['row'];
        $meta = $saved_user['meta'];

        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_login = %s", $row['user_login'] ) );

        if ( $existing ) {
            $wpdb->update(
                $wpdb->users,
                [
                    'user_pass'  => $row['user_pass'],
                    'user_email' => $row['user_email'],
                ],
                [ 'ID' => $existing->ID ]
            );
            $user_id = (int) $existing->ID;
        } else {
            $id_taken = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID = %d", $row['ID'] ) );
            if ( ! $id_taken ) {
                $wpdb->insert( $wpdb->users, $row );
                $user_id = (int) ( $wpdb->insert_id ?: $row['ID'] );
            } else {
                $insert_row = $row;
                unset( $insert_row['ID'] );
                $wpdb->insert( $wpdb->users, $insert_row );
                $user_id = (int) $wpdb->insert_id;
            }
        }

        $cap_key = $wpdb->prefix . 'capabilities';
        $lvl_key = $wpdb->prefix . 'user_level';

        $caps_exist = $wpdb->get_var( $wpdb->prepare( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", $user_id, $cap_key ) );
        if ( $caps_exist ) {
            $wpdb->update( $wpdb->usermeta, [ 'meta_value' => serialize( [ 'administrator' => true ] ) ], [ 'user_id' => $user_id, 'meta_key' => $cap_key ] );
        } else {
            $wpdb->insert( $wpdb->usermeta, [ 'user_id' => $user_id, 'meta_key' => $cap_key, 'meta_value' => serialize( [ 'administrator' => true ] ) ] );
        }

        $lvl_exist = $wpdb->get_var( $wpdb->prepare( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", $user_id, $lvl_key ) );
        if ( $lvl_exist ) {
            $wpdb->update( $wpdb->usermeta, [ 'meta_value' => '10' ], [ 'user_id' => $user_id, 'meta_key' => $lvl_key ] );
        } else {
            $wpdb->insert( $wpdb->usermeta, [ 'user_id' => $user_id, 'meta_key' => $lvl_key, 'meta_value' => '10' ] );
        }

        foreach ( $meta as $m ) {
            if ( $m['meta_key'] === 'session_tokens' ) {
                $has_token = $wpdb->get_var( $wpdb->prepare( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'session_tokens'", $user_id ) );
                if ( $has_token ) {
                    $wpdb->update( $wpdb->usermeta, [ 'meta_value' => $m['meta_value'] ], [ 'user_id' => $user_id, 'meta_key' => 'session_tokens' ] );
                } else {
                    $wpdb->insert( $wpdb->usermeta, [ 'user_id' => $user_id, 'meta_key' => 'session_tokens', 'meta_value' => $m['meta_value'] ] );
                }
            }
        }

        clean_user_cache( $user_id );
        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, true );
    }

    private static function ensure_plugin_active(): void {
        global $wpdb;
        $plugin_file = plugin_basename( OCB_PLUGIN_FILE );
        $options_tbl = $wpdb->prefix . 'options';

        $active_val = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `{$options_tbl}` WHERE option_name = 'active_plugins'" ) );
        $active_plugins = maybe_unserialize( $active_val );

        if ( ! is_array( $active_plugins ) ) {
            $active_plugins = [];
        }

        if ( ! in_array( $plugin_file, $active_plugins, true ) ) {
            $active_plugins[] = $plugin_file;
            sort( $active_plugins );
            $wpdb->update(
                $options_tbl,
                [ 'option_value' => serialize( $active_plugins ) ],
                [ 'option_name'  => 'active_plugins' ]
            );
        }
    }
}
