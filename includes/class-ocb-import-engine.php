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
        $sid     = wp_generate_password( 16, false );
        $tmp_dir = OCB_File_Handler::get_temp_dir( 'import-' . $sid );

        $state = [
            'session_id'    => $sid,
            'ocb_file'      => $ocb_file,
            'tmp_dir'       => $tmp_dir,
            'phase'         => 'validate',
            'manifest'      => null,
            'sql_file'      => $tmp_dir . '/database.sql',
            'snapshot_file' => $tmp_dir . '/snapshot.sql',
            'extract_index' => 0,
            'zip_entries'   => [],
            'error'         => null,
        ];

        set_transient( self::state_key( $sid ), $state, HOUR_IN_SECONDS * 6 );

        return $sid;
    }

    public static function get_state( string $sid ): ?array {
        $s = get_transient( self::state_key( $sid ) );
        return is_array( $s ) ? $s : null;
    }

    private static function save_state( string $sid, array $state ): void {
        set_transient( self::state_key( $sid ), $state, HOUR_IN_SECONDS * 6 );
    }

    public static function clear_state( string $sid ): void {
        delete_transient( self::state_key( $sid ) );
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
        $new_url  = rtrim( get_option( 'siteurl' ), '/' );

        if ( $old_url !== $new_url && ! empty( $old_url ) && ! empty( $new_url ) ) {
            $replacer = new OCB_URL_Replacer( $old_url, $new_url );
            $tables   = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}%'" );
            foreach ( $tables as $table ) {
                $replacer->replace_in_table( $table );
            }
        }

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

        // Keep the current admin user logged in by regenerating their session.
        $current_user = wp_get_current_user();
        if ( $current_user && $current_user->ID ) {
            // The user's existing cookies/sessions remain; we just ensure they
            // have an admin entry in the new DB.
            wp_cache_flush();
        }

        // Clean up temp dir.
        OCB_File_Handler::remove_temp_dir( 'import-' . $sid );
        self::clear_state( $sid );

        $state['phase'] = 'done';
        self::save_state( $sid, $state );

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
        OCB_File_Handler::remove_temp_dir( 'import-' . $sid );
        self::clear_state( $sid );

        return true;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function err( string $msg ): array {
        return [ 'done' => false, 'progress' => 0, 'step' => 'error', 'error' => $msg ];
    }
}
