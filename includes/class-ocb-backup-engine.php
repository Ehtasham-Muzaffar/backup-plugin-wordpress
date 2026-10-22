<?php
/**
 * Backup engine: orchestrates chunked creation of a .ocb file.
 * State is stored in a WP transient so it survives across AJAX requests.
 *
 * Phases (stored in 'phase'):
 *   init         → write manifest + init DB export state
 *   export_db    → stream SQL chunk by chunk
 *   collect_files → build file list
 *   zip_files    → add files to ZIP chunk by chunk
 *   finalize     → move ZIP to final destination
 *   done
 *
 * @package OneClickBackup
 */

defined( 'ABSPATH' ) || exit;

class OCB_Backup_Engine {

    private static function state_key( string $sid ): string {
        return 'ocb_backup_state_' . $sid;
    }

    // ── Start a new backup ────────────────────────────────────────────────────

    /**
     * @param array $options {
     *   include_db      bool
     *   include_uploads bool
     *   include_themes  bool
     *   include_plugins bool
     * }
     * @return string Session ID.
     */
    public static function start( array $options = [] ): string {
        $defaults = [
            'include_db'      => true,
            'include_uploads' => true,
            'include_themes'  => true,
            'include_plugins' => true,
        ];
        $opts = wp_parse_args( $options, $defaults );

        $sid        = wp_generate_password( 16, false );
        $backup_dir = OCB_File_Handler::get_backup_dir();
        $tmp_dir    = OCB_File_Handler::get_temp_dir( 'backup-' . $sid );
        $zip_tmp    = $tmp_dir . '/backup.zip';
        $sql_file   = $tmp_dir . '/database.sql';
        $final_path = OCB_File_Handler::get_new_backup_path();

        $state = [
            'session_id'  => $sid,
            'options'     => $opts,
            'tmp_dir'     => $tmp_dir,
            'zip_tmp'     => $zip_tmp,
            'sql_file'    => $sql_file,
            'final_path'  => $final_path,
            'phase'       => 'init',
            'file_list'   => [],
            'file_index'  => 0,
            'error'       => null,
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

    /**
     * Advance the backup by one chunk.
     * Returns [ 'done' => bool, 'progress' => int, 'step' => string, 'error' => string|null ].
     */
    public static function process_chunk( string $sid ): array {
        $state = self::get_state( $sid );
        if ( null === $state ) {
            return [ 'done' => false, 'progress' => 0, 'step' => 'error', 'error' => 'Session not found.' ];
        }

        try {
            switch ( $state['phase'] ) {

                case 'init':
                    return self::phase_init( $sid, $state );

                case 'export_db':
                    return self::phase_export_db( $sid, $state );

                case 'collect_files':
                    return self::phase_collect_files( $sid, $state );

                case 'zip_files':
                    return self::phase_zip_files( $sid, $state );

                case 'finalize':
                    return self::phase_finalize( $sid, $state );

                case 'done':
                    return [ 'done' => true, 'progress' => 100, 'step' => 'done', 'error' => null, 'filename' => basename( $state['final_path'] ) ];

                default:
                    return [ 'done' => false, 'progress' => 0, 'step' => 'error', 'error' => 'Unknown phase: ' . $state['phase'] ];
            }
        } catch ( Throwable $e ) {
            $state['error'] = $e->getMessage();
            self::save_state( $sid, $state );
            OCB_File_Handler::remove_temp_dir( 'backup-' . $sid );
            return [ 'done' => false, 'progress' => 0, 'step' => 'error', 'error' => $e->getMessage() ];
        }
    }

    // ── Phase: init ───────────────────────────────────────────────────────────

    private static function phase_init( string $sid, array $state ): array {
        // Write the SQL header (creates the file).
        if ( $state['options']['include_db'] ) {
            OCB_DB_Export::write_file_header( $state['sql_file'] );
            OCB_DB_Export::init_state( $sid, $state['sql_file'] );
            $state['phase'] = 'export_db';
        } else {
            $state['phase'] = 'collect_files';
        }

        self::save_state( $sid, $state );

        return [ 'done' => false, 'progress' => 2, 'step' => __( 'Initializing backup…', 'oneclick-backup' ), 'error' => null ];
    }

    // ── Phase: export_db ─────────────────────────────────────────────────────

    private static function phase_export_db( string $sid, array $state ): array {
        $result = OCB_DB_Export::export_chunk( $sid, $state['sql_file'] );

        if ( $result['done'] ) {
            OCB_DB_Export::write_file_footer( $state['sql_file'] );
            OCB_DB_Export::clear_state( $sid );
            $state['phase'] = 'collect_files';
            self::save_state( $sid, $state );
            return [ 'done' => false, 'progress' => 30, 'step' => __( 'Database exported.', 'oneclick-backup' ), 'error' => null ];
        }

        // Scale DB export progress to 5–30%.
        $progress = 5 + (int) ( $result['progress'] * 0.25 );

        self::save_state( $sid, $state );

        return [
            'done'     => false,
            'progress' => $progress,
            /* translators: 1: table index, 2: total tables */
            'step'     => sprintf( __( 'Exporting database table %1$d of %2$d…', 'oneclick-backup' ), $result['table_index'], $result['total_tables'] ),
            'error'    => null,
        ];
    }

    // ── Phase: collect_files ──────────────────────────────────────────────────

    private static function phase_collect_files( string $sid, array $state ): array {
        $opts     = $state['options'];
        $excluded = OCB_File_Handler::get_excluded_paths();
        $files    = [];

        // DB SQL file.
        if ( $state['options']['include_db'] && file_exists( $state['sql_file'] ) ) {
            $files[] = [ 'src' => $state['sql_file'], 'dst' => OCB_DB_DUMP_FILE ];
        }

        $wp_content = WP_CONTENT_DIR;

        // Uploads.
        if ( $opts['include_uploads'] ) {
            $upload_dir = wp_upload_dir()['basedir'];
            if ( is_dir( $upload_dir ) ) {
                $files = array_merge( $files, self::collect_dir( $upload_dir, 'wp-content/uploads', $excluded ) );
            }
        }

        // Themes.
        if ( $opts['include_themes'] ) {
            $themes_dir = get_theme_root();
            if ( is_dir( $themes_dir ) ) {
                $files = array_merge( $files, self::collect_dir( $themes_dir, 'wp-content/themes', $excluded ) );
            }
        }

        // Plugins.
        if ( $opts['include_plugins'] ) {
            $plugins_dir = WP_PLUGIN_DIR;
            if ( is_dir( $plugins_dir ) ) {
                $files = array_merge( $files, self::collect_dir( $plugins_dir, 'wp-content/plugins', $excluded ) );
            }
        }

        // mu-plugins.
        $mu_dir = WPMU_PLUGIN_DIR;
        if ( is_dir( $mu_dir ) ) {
            $files = array_merge( $files, self::collect_dir( $mu_dir, 'wp-content/mu-plugins', $excluded ) );
        }

        // Build and write manifest.
        $manifest = self::build_manifest( $state['options'], count( $files ) );
        $manifest_path = $state['tmp_dir'] . '/manifest.json';
        file_put_contents( $manifest_path, wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );
        $files[] = [ 'src' => $manifest_path, 'dst' => OCB_MANIFEST_FILE ];

        // Open/create the ZIP.
        self::zip_open( $state['zip_tmp'] );

        $state['file_list']  = $files;
        $state['file_index'] = 0;
        $state['phase']      = 'zip_files';

        self::save_state( $sid, $state );

        return [ 'done' => false, 'progress' => 32, 'step' => __( 'Collecting files…', 'oneclick-backup' ), 'error' => null ];
    }

    private static function collect_dir( string $base_dir, string $zip_base, array $excluded ): array {
        $files    = [];
        $real_base = realpath( $base_dir );
        if ( ! $real_base ) {
            return $files;
        }

        try {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $base_dir, FilesystemIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
        } catch ( Exception $e ) {
            return $files;
        }

        foreach ( $iter as $file ) {
            if ( ! $file->isFile() ) {
                continue;
            }
            $real = realpath( $file->getPathname() );
            if ( ! $real ) {
                continue;
            }

            // Check excluded paths.
            $skip = false;
            foreach ( $excluded as $excl ) {
                if ( $excl && strpos( $real, $excl ) === 0 ) {
                    $skip = true;
                    break;
                }
            }
            if ( $skip ) {
                continue;
            }

            $relative    = str_replace( $real_base, '', $real );
            $relative    = ltrim( str_replace( '\\', '/', $relative ), '/' );
            $files[]     = [ 'src' => $real, 'dst' => $zip_base . '/' . $relative ];
        }

        return $files;
    }

    // ── Phase: zip_files ─────────────────────────────────────────────────────

    private static function phase_zip_files( string $sid, array $state ): array {
        $files      = $state['file_list'];
        $index      = (int) $state['file_index'];
        $total      = count( $files );
        $chunk_end  = min( $index + OCB_CHUNK_FILES, $total );

        $use_zip_archive = class_exists( 'ZipArchive' );
        $zip             = null;

        if ( $use_zip_archive ) {
            $zip = new ZipArchive();
            if ( $zip->open( $state['zip_tmp'], ZipArchive::CREATE ) !== true ) {
                throw new RuntimeException( 'Cannot open ZIP for writing.' );
            }
        }

        for ( $i = $index; $i < $chunk_end; $i++ ) {
            $entry = $files[ $i ];
            if ( ! file_exists( $entry['src'] ) ) {
                continue;
            }

            if ( $use_zip_archive ) {
                $zip->addFile( $entry['src'], $entry['dst'] );
            } else {
                // PclZip — must add each file explicitly.
                if ( ! isset( $pcl_zip ) ) {
                    if ( ! class_exists( 'PclZip' ) ) {
                        require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
                    }
                    $pcl_zip = new PclZip( $state['zip_tmp'] );
                }
                $pcl_zip->add(
                    $entry['src'],
                    PCLZIP_OPT_REMOVE_PATH, dirname( $entry['src'] ),
                    PCLZIP_OPT_ADD_PATH,    dirname( $entry['dst'] )
                );
            }
        }

        if ( $use_zip_archive && $zip ) {
            $zip->close();
        }

        $state['file_index'] = $chunk_end;
        $done_zipping        = ( $chunk_end >= $total );

        if ( $done_zipping ) {
            $state['phase'] = 'finalize';
        }

        self::save_state( $sid, $state );

        // Scale zip progress 33–90%.
        $progress = $total > 0 ? 33 + (int) ( ( $chunk_end / $total ) * 57 ) : 90;

        return [
            'done'     => false,
            'progress' => $progress,
            /* translators: 1: current file, 2: total files */
            'step'     => sprintf( __( 'Compressing file %1$d of %2$d…', 'oneclick-backup' ), $chunk_end, $total ),
            'error'    => null,
        ];
    }

    // ── Phase: finalize ───────────────────────────────────────────────────────

    private static function phase_finalize( string $sid, array $state ): array {
        // Move ZIP to final destination.
        if ( ! rename( $state['zip_tmp'], $state['final_path'] ) ) {
            // Fallback: copy + delete.
            copy( $state['zip_tmp'], $state['final_path'] );
            @unlink( $state['zip_tmp'] );
        }

        // Clean up temp dir.
        OCB_File_Handler::remove_temp_dir( 'backup-' . $sid );

        $state['phase'] = 'done';
        self::save_state( $sid, $state );

        return [
            'done'     => true,
            'progress' => 100,
            'step'     => __( 'Backup complete!', 'oneclick-backup' ),
            'error'    => null,
            'filename' => basename( $state['final_path'] ),
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function zip_open( string $path ): void {
        // Just ensure an empty file exists for ZipArchive to CREATE mode.
        if ( ! file_exists( $path ) ) {
            file_put_contents( $path, '' );
        }
    }

    private static function build_manifest( array $options, int $file_count ): array {
        global $wpdb;

        $upload_dir   = wp_upload_dir();
        $active_theme = wp_get_theme();

        return [
            'plugin_version' => OCB_VERSION,
            'wp_version'     => get_bloginfo( 'version' ),
            'php_version'    => PHP_VERSION,
            'site_url'       => get_option( 'siteurl' ),
            'home_url'       => get_option( 'home' ),
            'table_prefix'   => $wpdb->prefix,
            'db_charset'     => defined( 'DB_CHARSET' ) ? DB_CHARSET : 'utf8mb4',
            'db_collation'   => defined( 'DB_COLLATE' ) ? DB_COLLATE : '',
            'active_theme'   => $active_theme->get_stylesheet(),
            'active_plugins' => get_option( 'active_plugins', [] ),
            'created'        => gmdate( 'c' ),
            'options'        => $options,
            'file_count'     => $file_count,
        ];
    }
}
