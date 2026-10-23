<?php
/**
 * Security helpers: capability checks, nonce helpers, path validation.
 *
 * @package OneClickBackup
 */

defined( 'ABSPATH' ) || exit;

class OCB_Security {

    /** Required capability for all plugin actions. */
    const CAP = 'manage_options';

    public static function init(): void {
        // Nothing needed at boot; methods called on-demand.
    }

    // ── Capability ────────────────────────────────────────────────────────────

    public static function current_user_can(): bool {
        return current_user_can( self::CAP );
    }

    public static function check_capability(): void {
        if ( ! self::current_user_can() ) {
            wp_die( esc_html__( 'You do not have permission to do this.', 'oneclick-backup' ), 403 );
        }
    }

    // ── Nonces ────────────────────────────────────────────────────────────────

    public static function nonce_action( string $action ): string {
        return 'ocb_' . $action;
    }

    public static function create_nonce( string $action ): string {
        return wp_create_nonce( self::nonce_action( $action ) );
    }

    public static function verify_nonce( string $nonce, string $action ): bool {
        return (bool) wp_verify_nonce( $nonce, self::nonce_action( $action ) );
    }

    /**
     * Checks nonce from $_REQUEST['_nonce'] for a given action.
     * Dies on failure.
     */
    public static function check_ajax_nonce( string $action ): void {
        self::check_capability();
        $nonce = isset( $_REQUEST['_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_nonce'] ) ) : '';
        if ( ! self::verify_nonce( $nonce, $action ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'oneclick-backup' ) ], 403 );
        }
    }

    // ── Path validation ───────────────────────────────────────────────────────

    /**
     * Ensure a resolved path is inside an allowed base directory.
     * Returns the real path or throws a RuntimeException on traversal.
     */
    public static function validate_path( string $path, string $base ): string {
        $real_base = realpath( $base );
        $real_path = realpath( $path );

        if ( false === $real_base ) {
            $real_base = self::normalize_path( $base );
        }
        if ( false === $real_path ) {
            // Path doesn't exist yet; normalise manually.
            $real_path = self::normalize_path( $path );
        }

        $norm_base = rtrim( wp_normalize_path( $real_base ), '/' ) . '/';
        $norm_path = wp_normalize_path( $real_path );

        $is_windows = ( DIRECTORY_SEPARATOR === '\\' );
        $match = $is_windows
            ? ( stripos( $norm_path, $norm_base ) === 0 || stripos( $norm_path . '/', $norm_base ) === 0 )
            : ( strpos( $norm_path, $norm_base ) === 0 || strpos( $norm_path . '/', $norm_base ) === 0 );

        if ( ! $match ) {
            throw new RuntimeException( "Path traversal detected: {$path}" );
        }

        return $norm_path;
    }

    private static function normalize_path( string $path ): string {
        $path  = str_replace( '\\', '/', $path );
        $parts = [];
        foreach ( explode( '/', $path ) as $part ) {
            if ( '.' === $part || '' === $part ) {
                continue;
            }
            if ( '..' === $part ) {
                array_pop( $parts );
            } else {
                $parts[] = $part;
            }
        }
        return ( substr( $path, 0, 1 ) === '/' ? '/' : '' ) . implode( '/', $parts );
    }

    // ── Zip-slip guard ────────────────────────────────────────────────────────

    /**
     * Validate that an extracted entry path won't escape the target directory.
     */
    public static function validate_zip_entry( string $entry_name, string $target_dir ): string {
        // Strip leading slashes / drive letters.
        $entry_name = ltrim( str_replace( '\\', '/', $entry_name ), '/' );
        $entry_name = preg_replace( '#^[a-zA-Z]:/#', '', $entry_name );

        $full = $target_dir . DIRECTORY_SEPARATOR . $entry_name;
        return self::validate_path( $full, $target_dir );
    }

    // ── File extension whitelist ──────────────────────────────────────────────

    public static function is_allowed_upload_extension( string $filename ): bool {
        return strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) === OCB_EXTENSION;
    }
}
