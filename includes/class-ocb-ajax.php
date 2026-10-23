<?php
/**
 * AJAX handler: all wp_ajax_ocb_* actions.
 *
 * @package OneClickBackup
 */

defined( 'ABSPATH' ) || exit;

class OCB_Ajax {

    public static function init(): void {
        $actions = [
            'ocb_backup_start',
            'ocb_backup_chunk',
            'ocb_backup_delete',
            'ocb_backup_download',
            'ocb_upload_chunk',
            'ocb_import_start',
            'ocb_import_chunk',
            'ocb_import_rollback',
            'ocb_restore_start',
            'ocb_get_manifest',
        ];

        foreach ( $actions as $action ) {
            add_action( 'wp_ajax_' . $action, [ __CLASS__, 'handle_' . $action ] );
        }

        // Allow continuing chunks & rollback even if database tables (users/options) are swapped during import.
        add_action( 'wp_ajax_nopriv_ocb_import_chunk', [ __CLASS__, 'handle_ocb_import_chunk' ] );
        add_action( 'wp_ajax_nopriv_ocb_import_rollback', [ __CLASS__, 'handle_ocb_import_rollback' ] );

        // Authenticated download (non-AJAX, tab request).
        add_action( 'admin_init', [ __CLASS__, 'maybe_handle_download' ] );
    }

    // ── Backup: start ─────────────────────────────────────────────────────────

    public static function handle_ocb_backup_start(): void {
        OCB_Security::check_ajax_nonce( 'backup_start' );

        $options = [
            'include_db'      => (bool) ( $_POST['include_db']      ?? true ),
            'include_uploads' => (bool) ( $_POST['include_uploads']  ?? true ),
            'include_themes'  => (bool) ( $_POST['include_themes']   ?? true ),
            'include_plugins' => (bool) ( $_POST['include_plugins']  ?? true ),
        ];

        $sid = OCB_Backup_Engine::start( $options );

        wp_send_json_success( [ 'session_id' => $sid ] );
    }

    // ── Backup: chunk ─────────────────────────────────────────────────────────

    public static function handle_ocb_backup_chunk(): void {
        OCB_Security::check_ajax_nonce( 'backup_chunk' );

        $sid = sanitize_text_field( $_POST['session_id'] ?? '' );
        if ( ! $sid ) {
            wp_send_json_error( [ 'message' => 'Missing session_id.' ] );
        }

        $result = OCB_Backup_Engine::process_chunk( $sid );

        if ( isset( $result['error'] ) && $result['error'] ) {
            wp_send_json_error( $result );
        }
        wp_send_json_success( $result );
    }

    // ── Backup: delete ────────────────────────────────────────────────────────

    public static function handle_ocb_backup_delete(): void {
        OCB_Security::check_ajax_nonce( 'backup_delete' );

        $filename = sanitize_file_name( $_POST['filename'] ?? '' );
        if ( ! $filename ) {
            wp_send_json_error( [ 'message' => 'Missing filename.' ] );
        }

        $ok = OCB_File_Handler::delete_backup( $filename );
        if ( $ok ) {
            wp_send_json_success( [ 'message' => __( 'Backup deleted.', 'oneclick-backup' ) ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'Could not delete backup.', 'oneclick-backup' ) ] );
        }
    }

    // ── Backup: download (via admin-ajax with nonce) ───────────────────────────

    public static function handle_ocb_backup_download(): void {
        OCB_Security::check_ajax_nonce( 'backup_download' );

        $filename = sanitize_file_name( $_GET['filename'] ?? $_POST['filename'] ?? '' );
        if ( ! $filename ) {
            wp_die( 'Missing filename.' );
        }

        OCB_File_Handler::stream_backup_download( $filename );
    }

    // ── Non-AJAX direct download ──────────────────────────────────────────────

    public static function maybe_handle_download(): void {
        if ( ! isset( $_GET['ocb_action'] ) || $_GET['ocb_action'] !== 'download' ) {
            return;
        }
        OCB_Security::check_capability();
        $nonce    = sanitize_text_field( $_GET['_wpnonce'] ?? '' );
        $filename = sanitize_file_name( $_GET['filename'] ?? '' );
        if ( ! wp_verify_nonce( $nonce, 'ocb_download_' . $filename ) ) {
            wp_die( 'Security check failed.' );
        }
        OCB_File_Handler::stream_backup_download( $filename );
    }

    // ── Upload chunk ──────────────────────────────────────────────────────────

    public static function handle_ocb_upload_chunk(): void {
        OCB_Security::check_ajax_nonce( 'upload_chunk' );

        $session_id   = sanitize_text_field( $_POST['session_id'] ?? '' );
        $chunk_index  = (int) ( $_POST['chunk_index']  ?? 0 );
        $total_chunks = (int) ( $_POST['total_chunks']  ?? 1 );
        $filename     = sanitize_file_name( $_POST['filename'] ?? '' );

        if ( ! $session_id || ! $filename ) {
            wp_send_json_error( [ 'message' => 'Missing upload parameters.' ] );
        }

        if ( ! OCB_Security::is_allowed_upload_extension( $filename ) ) {
            wp_send_json_error( [ 'message' => __( 'Only .ocb files are allowed.', 'oneclick-backup' ) ] );
        }

        if ( empty( $_FILES['file']['tmp_name'] ) ) {
            wp_send_json_error( [ 'message' => 'No file data received.' ] );
        }

        $result = OCB_File_Handler::save_upload_chunk( $session_id, $chunk_index, $total_chunks, $_FILES['file']['tmp_name'] );

        wp_send_json_success( $result );
    }

    // ── Import: start (after all chunks uploaded) ─────────────────────────────

    public static function handle_ocb_import_start(): void {
        OCB_Security::check_ajax_nonce( 'import_start' );

        $upload_session = sanitize_text_field( $_POST['upload_session_id'] ?? '' );
        if ( ! $upload_session ) {
            wp_send_json_error( [ 'message' => 'Missing upload session ID.' ] );
        }

        $tmp_dir     = OCB_File_Handler::get_temp_dir( 'upload-' . $upload_session );
        $assembled   = $tmp_dir . '/assembled.' . OCB_EXTENSION;

        if ( ! file_exists( $assembled ) ) {
            wp_send_json_error( [ 'message' => __( 'Assembled file not found. Upload may be incomplete.', 'oneclick-backup' ) ] );
        }

        // Validate it has a manifest.
        $manifest = OCB_File_Handler::read_manifest_from_ocb( $assembled );
        if ( empty( $manifest ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid .ocb file: no manifest found.', 'oneclick-backup' ) ] );
        }

        $sid   = OCB_Import_Engine::start( $assembled );
        $state = OCB_Import_Engine::get_state( $sid );

        wp_send_json_success( [
            'session_id'   => $sid,
            'import_token' => $state['import_token'] ?? '',
            'manifest'     => $manifest,
        ] );
    }

    // ── Import: chunk ─────────────────────────────────────────────────────────

    public static function handle_ocb_import_chunk(): void {
        $sid   = sanitize_text_field( $_POST['session_id'] ?? '' );
        $token = sanitize_text_field( $_POST['import_token'] ?? '' );
        if ( ! $sid ) {
            wp_send_json_error( [ 'message' => 'Missing session_id.' ] );
        }

        $authorized = false;
        if ( ! empty( $token ) && OCB_Import_Engine::verify_import_token( $sid, $token ) ) {
            $authorized = true;
        } elseif ( is_user_logged_in() && current_user_can( OCB_Security::CAP ) && OCB_Security::verify_nonce( $_REQUEST['_nonce'] ?? '', 'import_chunk' ) ) {
            $authorized = true;
        }

        if ( ! $authorized ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'oneclick-backup' ) ], 403 );
        }

        $result = OCB_Import_Engine::process_chunk( $sid );

        if ( isset( $result['error'] ) && $result['error'] ) {
            wp_send_json_error( $result );
        }
        wp_send_json_success( $result );
    }

    // ── Import: rollback ──────────────────────────────────────────────────────

    public static function handle_ocb_import_rollback(): void {
        $sid   = sanitize_text_field( $_POST['session_id'] ?? '' );
        $token = sanitize_text_field( $_POST['import_token'] ?? '' );
        if ( ! $sid ) {
            wp_send_json_error( [ 'message' => 'Missing session_id.' ] );
        }

        $authorized = false;
        if ( ! empty( $token ) && OCB_Import_Engine::verify_import_token( $sid, $token ) ) {
            $authorized = true;
        } elseif ( is_user_logged_in() && current_user_can( OCB_Security::CAP ) && OCB_Security::verify_nonce( $_REQUEST['_nonce'] ?? '', 'import_rollback' ) ) {
            $authorized = true;
        }

        if ( ! $authorized ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'oneclick-backup' ) ], 403 );
        }

        $ok = OCB_Import_Engine::rollback( $sid );

        if ( $ok ) {
            wp_send_json_success( [ 'message' => __( 'Rollback successful. Previous database restored.', 'oneclick-backup' ) ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'Rollback failed or snapshot not found.', 'oneclick-backup' ) ] );
        }
    }

    // ── Restore from existing backup (same site) ──────────────────────────────

    public static function handle_ocb_restore_start(): void {
        OCB_Security::check_ajax_nonce( 'restore_start' );

        $filename = sanitize_file_name( $_POST['filename'] ?? '' );
        if ( ! $filename ) {
            wp_send_json_error( [ 'message' => 'Missing filename.' ] );
        }

        $dir  = OCB_File_Handler::get_backup_dir();
        $path = $dir . '/' . $filename;

        if ( ! file_exists( $path ) || strpos( realpath( $path ), realpath( $dir ) ) !== 0 ) {
            wp_send_json_error( [ 'message' => __( 'Backup file not found.', 'oneclick-backup' ) ] );
        }

        $manifest = OCB_File_Handler::read_manifest_from_ocb( $path );
        $sid      = OCB_Import_Engine::start( $path );
        $state    = OCB_Import_Engine::get_state( $sid );

        wp_send_json_success( [
            'session_id'   => $sid,
            'import_token' => $state['import_token'] ?? '',
            'manifest'     => $manifest,
        ] );
    }

    // ── Get manifest from an existing backup ──────────────────────────────────

    public static function handle_ocb_get_manifest(): void {
        OCB_Security::check_ajax_nonce( 'get_manifest' );

        $filename = sanitize_file_name( $_POST['filename'] ?? '' );
        $dir      = OCB_File_Handler::get_backup_dir();
        $path     = $dir . '/' . $filename;

        $manifest = OCB_File_Handler::read_manifest_from_ocb( $path );

        if ( $manifest ) {
            wp_send_json_success( [ 'manifest' => $manifest ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'Could not read manifest.', 'oneclick-backup' ) ] );
        }
    }
}
