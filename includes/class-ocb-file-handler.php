<?php
/**
 * File and directory management for the backup storage location.
 *
 * @package OneClickBackup
 */

defined( 'ABSPATH' ) || exit;

class OCB_File_Handler {

    // ── Backup directory ──────────────────────────────────────────────────────

    /**
     * Returns the absolute path to the private backup directory.
     * Creates it (with protection files) if it doesn't exist.
     */
    public static function get_backup_dir(): string {
        $option = get_option( 'ocb_backup_dir_name', '' );
        if ( empty( $option ) ) {
            $option = OCB_BACKUP_SUBDIR . '-' . wp_generate_password( 12, false );
            update_option( 'ocb_backup_dir_name', $option );
        }
        $dir = WP_CONTENT_DIR . DIRECTORY_SEPARATOR . $option;
        self::ensure_dir_protected( $dir );
        return $dir;
    }

    /**
     * Called on activation to create the backup directory.
     */
    public static function ensure_backup_dir(): void {
        self::get_backup_dir();
    }

    private static function ensure_dir_protected( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        // index.php
        $index = $dir . DIRECTORY_SEPARATOR . 'index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, "<?php // Silence is golden.\n" );
        }

        // .htaccess
        $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Deny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" );
        }

        // web.config (IIS)
        $webconfig = $dir . DIRECTORY_SEPARATOR . 'web.config';
        if ( ! file_exists( $webconfig ) ) {
            $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <security>
      <requestFiltering>
        <denyUrlSequences><add sequence="." /></denyUrlSequences>
      </requestFiltering>
    </security>
    <httpErrors errorMode="Custom">
      <remove statusCode="403" subStatusCode="-1" />
      <error statusCode="403" prefixLanguageFilePath="" path="/403.htm" responseMode="Redirect" />
    </httpErrors>
    <staticContent><mimeMap fileExtension=".ocb" mimeType="application/octet-stream" /></staticContent>
  </system.webServer>
</configuration>
XML;
            file_put_contents( $webconfig, $xml );
        }
    }

    // ── Backup file naming ────────────────────────────────────────────────────

    /**
     * Generate a new backup filename (not on disk yet).
     */
    public static function generate_backup_filename(): string {
        return 'backup-' . gmdate( 'Y-m-d-His' ) . '-' . wp_generate_password( 8, false ) . '.' . OCB_EXTENSION;
    }

    /**
     * Full path for a new backup file.
     */
    public static function get_new_backup_path(): string {
        return self::get_backup_dir() . DIRECTORY_SEPARATOR . self::generate_backup_filename();
    }

    // ── Listing backups ───────────────────────────────────────────────────────

    /**
     * Returns an array of backup file info arrays, sorted by date desc.
     */
    public static function list_backups(): array {
        $dir   = self::get_backup_dir();
        $files = glob( $dir . DIRECTORY_SEPARATOR . '*.' . OCB_EXTENSION );
        if ( ! $files ) {
            return [];
        }

        $backups = [];
        foreach ( $files as $file ) {
            $manifest = self::read_manifest_from_ocb( $file );
            $backups[] = [
                'filename'   => basename( $file ),
                'path'       => $file,
                'size'       => filesize( $file ),
                'size_human' => size_format( filesize( $file ) ),
                'modified'   => filemtime( $file ),
                'wp_version' => $manifest['wp_version'] ?? '',
                'site_url'   => $manifest['site_url']   ?? '',
                'created'    => $manifest['created']     ?? '',
            ];
        }

        usort( $backups, fn( $a, $b ) => $b['modified'] - $a['modified'] );

        return $backups;
    }

    /**
     * Read manifest.json from an .ocb file without extracting the whole archive.
     */
    public static function read_manifest_from_ocb( string $ocb_path ): array {
        if ( class_exists( 'ZipArchive' ) ) {
            $zip = new ZipArchive();
            if ( $zip->open( $ocb_path ) === true ) {
                $json = $zip->getFromName( OCB_MANIFEST_FILE );
                $zip->close();
                if ( $json ) {
                    $data = json_decode( $json, true );
                    return is_array( $data ) ? $data : [];
                }
            }
        } else {
            // PclZip fallback.
            if ( ! class_exists( 'PclZip' ) ) {
                require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
            }
            $zip  = new PclZip( $ocb_path );
            $list = $zip->listContent();
            if ( is_array( $list ) ) {
                foreach ( $list as $entry ) {
                    if ( $entry['filename'] === OCB_MANIFEST_FILE ) {
                        $extracted = $zip->extract( PCLZIP_OPT_BY_NAME, OCB_MANIFEST_FILE, PCLZIP_OPT_EXTRACT_AS_STRING );
                        if ( is_array( $extracted ) && ! empty( $extracted[0]['content'] ) ) {
                            $data = json_decode( $extracted[0]['content'], true );
                            return is_array( $data ) ? $data : [];
                        }
                    }
                }
            }
        }
        return [];
    }

    // ── Delete backup ─────────────────────────────────────────────────────────

    public static function delete_backup( string $filename ): bool {
        $dir  = self::get_backup_dir();
        $path = $dir . DIRECTORY_SEPARATOR . sanitize_file_name( $filename );
        if ( file_exists( $path ) && strpos( realpath( $path ), realpath( $dir ) ) === 0 ) {
            return @unlink( $path );
        }
        return false;
    }

    // ── Authenticated download ────────────────────────────────────────────────

    public static function stream_backup_download( string $filename ): void {
        $dir  = self::get_backup_dir();
        $path = $dir . DIRECTORY_SEPARATOR . sanitize_file_name( $filename );

        if ( ! file_exists( $path ) || strpos( realpath( $path ), realpath( $dir ) ) !== 0 ) {
            wp_die( esc_html__( 'File not found.', 'oneclick-backup' ) );
        }

        $size = filesize( $path );
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
        header( 'Content-Length: ' . $size );
        header( 'Cache-Control: no-store' );
        header( 'Pragma: no-cache' );

        if ( ob_get_level() ) {
            ob_end_clean();
        }

        $fh = fopen( $path, 'rb' );
        if ( $fh ) {
            while ( ! feof( $fh ) ) {
                echo fread( $fh, 65536 ); // 64 KB chunks
                flush();
            }
            fclose( $fh );
        }
        exit;
    }

    // ── Temp directory ────────────────────────────────────────────────────────

    public static function get_temp_dir( string $session_id ): string {
        $dir = self::get_backup_dir() . DIRECTORY_SEPARATOR . 'tmp-' . preg_replace( '/[^a-z0-9]/', '', $session_id );
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        return $dir;
    }

    public static function remove_temp_dir( string $session_id ): void {
        $dir = self::get_backup_dir() . DIRECTORY_SEPARATOR . 'tmp-' . preg_replace( '/[^a-z0-9]/', '', $session_id );
        self::rmdir_recursive( $dir );
    }

    public static function rmdir_recursive( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $items as $item ) {
            if ( $item->isDir() ) {
                @rmdir( $item->getRealPath() );
            } else {
                @unlink( $item->getRealPath() );
            }
        }
        @rmdir( $dir );
    }

    // ── Chunked upload assembly ───────────────────────────────────────────────

    /**
     * Save an uploaded chunk into the temp directory.
     * Returns [ 'done' => bool, 'tmp_file' => string|null ].
     */
    public static function save_upload_chunk( string $session_id, int $chunk_index, int $total_chunks, string $tmp_uploaded_file ): array {
        $temp_dir   = self::get_temp_dir( 'upload-' . $session_id );
        $chunk_file = $temp_dir . DIRECTORY_SEPARATOR . 'chunk-' . $chunk_index;

        if ( ! move_uploaded_file( $tmp_uploaded_file, $chunk_file ) ) {
            throw new RuntimeException( 'Failed to save upload chunk.' );
        }

        // Check if all chunks received.
        $received = count( glob( $temp_dir . DIRECTORY_SEPARATOR . 'chunk-*' ) );
        if ( $received < $total_chunks ) {
            return [ 'done' => false, 'tmp_file' => null ];
        }

        // Assemble all chunks into a single file.
        $final_file = $temp_dir . DIRECTORY_SEPARATOR . 'assembled.' . OCB_EXTENSION;
        $out        = fopen( $final_file, 'wb' );
        for ( $i = 0; $i < $total_chunks; $i++ ) {
            $chunk = $temp_dir . DIRECTORY_SEPARATOR . 'chunk-' . $i;
            $in    = fopen( $chunk, 'rb' );
            while ( ! feof( $in ) ) {
                fwrite( $out, fread( $in, 65536 ) );
            }
            fclose( $in );
            @unlink( $chunk );
        }
        fclose( $out );

        return [ 'done' => true, 'tmp_file' => $final_file ];
    }

    // ── Exclude list for backup ───────────────────────────────────────────────

    /**
     * Returns absolute paths that should be excluded from wp-content backups.
     */
    public static function get_excluded_paths(): array {
        $backup_dir = self::get_backup_dir();
        $excluded   = [ $backup_dir ];

        // Common cache directories.
        $cache_dirs = [
            WP_CONTENT_DIR . '/cache',
            WP_CONTENT_DIR . '/w3tc-config',
            WP_CONTENT_DIR . '/et-cache',
            WP_CONTENT_DIR . '/wpo-cache',
            WP_CONTENT_DIR . '/wp-rocket-config',
        ];
        foreach ( $cache_dirs as $d ) {
            if ( is_dir( $d ) ) {
                $excluded[] = realpath( $d );
            }
        }

        // Other backup plugin directories.
        $other_backup_dirs = [
            WP_CONTENT_DIR . '/updraft',
            WP_CONTENT_DIR . '/ai1wm-backups',
            WP_CONTENT_DIR . '/backwpup',
            WP_CONTENT_DIR . '/backupwordpress',
            WP_CONTENT_DIR . '/backups',
        ];
        foreach ( $other_backup_dirs as $d ) {
            if ( is_dir( $d ) ) {
                $excluded[] = realpath( $d );
            }
        }

        return array_filter( array_unique( $excluded ) );
    }
}
