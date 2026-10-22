<?php
/**
 * Plugin Name:       OneClick Site Backup & Migrate
 * Plugin URI:        https://example.com/oneclick-backup
 * Description:       Back up your entire WordPress site into a single .ocb file and restore it anywhere with one click. Supports chunked processing for shared hosting.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            OneClick Backup
 * Author URI:        https://example.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       oneclick-backup
 * Domain Path:       /languages
 *
 * @package OneClickBackup
 */

defined( 'ABSPATH' ) || exit;

// ── Constants ──────────────────────────────────────────────────────────────────
define( 'OCB_VERSION',       '1.0.0' );
define( 'OCB_PLUGIN_FILE',   __FILE__ );
define( 'OCB_PLUGIN_DIR',    plugin_dir_path( __FILE__ ) );
define( 'OCB_PLUGIN_URL',    plugin_dir_url( __FILE__ ) );
define( 'OCB_PLUGIN_SLUG',   'oneclick-backup' );
define( 'OCB_BACKUP_SUBDIR', 'ocb-backups' );
define( 'OCB_CHUNK_DB_ROWS', 500 );    // DB rows per AJAX chunk
define( 'OCB_CHUNK_FILES',   40 );     // Files added to ZIP per AJAX chunk
define( 'OCB_CHUNK_UPLOAD',  2097152 ); // 2 MB upload chunk
define( 'OCB_CHUNK_SQL',     200 );    // SQL statements per import chunk
define( 'OCB_MANIFEST_FILE', 'manifest.json' );
define( 'OCB_DB_DUMP_FILE',  'database.sql' );
define( 'OCB_EXTENSION',     'ocb' );

// ── Autoloader ────────────────────────────────────────────────────────────────
spl_autoload_register( function ( $class ) {
    if ( strpos( $class, 'OCB_' ) !== 0 ) {
        return;
    }
    $file = OCB_PLUGIN_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

// ── Boot ──────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'ocb_boot' );

function ocb_boot(): void {
    load_plugin_textdomain( 'oneclick-backup', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    OCB_Security::init();
    if ( is_admin() ) {
        OCB_Admin::init();
    }
    OCB_Ajax::init();
}

// ── Activation / Deactivation ─────────────────────────────────────────────────
register_activation_hook( __FILE__, 'ocb_activate' );
register_deactivation_hook( __FILE__, 'ocb_deactivate' );

function ocb_activate(): void {
    OCB_File_Handler::ensure_backup_dir();
}

function ocb_deactivate(): void {
    // Intentionally leave backups in place.
}
