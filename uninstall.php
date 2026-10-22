<?php
/**
 * Runs when the plugin is deleted (not just deactivated).
 * Only runs if the user ticked "Delete" in the WordPress admin.
 *
 * @package OneClickBackup
 */

// WordPress must define this constant before including this file.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Remove plugin options.
delete_option( 'ocb_backup_dir_name' );

// Delete any lingering transients.
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ocb_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_ocb_%'" );

// NOTE: Backup files are intentionally NOT deleted on uninstall so the user
// does not accidentally lose their backups. They can remove the ocb-backups-*
// directory manually if desired.
