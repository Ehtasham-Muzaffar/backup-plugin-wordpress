=== OneClick Site Backup & Migrate ===
Contributors:       oneclickbackup
Tags:               backup, migrate, restore, database, wp-content
Requires at least:  5.6
Tested up to:       6.7
Requires PHP:       7.4
Stable tag:         1.0.0
License:            GPLv2 or later
License URI:        https://www.gnu.org/licenses/gpl-2.0.html

Back up your entire WordPress site into a single .ocb file and restore it on any site with one click. Works on shared hosting.

== Description ==

**OneClick Site Backup & Migrate** is a production-ready plugin that lets you:

* Create a **complete backup** (database + themes + plugins + uploads) into a single `.ocb` file.
* **Restore** any backup on the same site or **migrate** it to a completely different WordPress installation.
* Everything runs in **small AJAX chunks**, so it never times out on shared hosting environments.

= Core Features =

**One-Click Full Backup**
- Exports the entire database using pure PHP ($wpdb) — no shell access required.
- Packages wp-content (uploads, themes, plugins, mu-plugins) into the archive.
- Includes a manifest.json with WordPress version, PHP version, site URL, active plugins, and more.
- Excludes cache directories and backup-plugin folders automatically.
- Granular options: include/exclude database, uploads, themes, or plugins individually.

**Backups List**
- Sortable table showing filename, date, size, WP version, and source URL.
- Per-row Download, Restore, and Delete actions.
- Live progress bar during backup creation.

**One-Click Import / Restore**
- Upload a `.ocb` file from another site (drag-and-drop supported).
- File uploads work in 2 MB chunks — works even with small `upload_max_filesize`.
- Automatically creates a **safety snapshot** before overwriting anything, so you can roll back if the import fails.
- Performs **safe serialized-data URL replacement** (un-serializes, replaces, re-serializes with correct byte lengths).
- Handles different table prefixes between source and destination.
- Flushes rewrite rules and clears transients/caches after import.

= Security =
- Only users with `manage_options` capability can access the plugin.
- Nonces on every AJAX request.
- Backup directory protected with `.htaccess`, `web.config`, and `index.php`.
- Downloads served only through an authenticated PHP handler.
- Path-traversal and zip-slip protection on extract.
- All inputs sanitized; no prepared-statement skipping.

== Installation ==

1. Upload the `oneclick-backup` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **OneClick Backup** in the left admin menu.

== Frequently Asked Questions ==

= Does it work on shared hosting? =
Yes. Backup and import are done in small AJAX chunks so they never hit PHP's max_execution_time.

= What is a .ocb file? =
It's a standard ZIP archive with a `.ocb` extension containing your database SQL dump, wp-content files, and a manifest.

= Can I restore a backup on a different domain? =
Yes. The plugin automatically searches and replaces the old site URL (including http/https and www variants) in the database, safely handling serialized PHP data.

= What if the import fails? =
A safety snapshot of your current database is taken before the import starts. You'll see a "Roll Back" button to restore the previous state.

= Does it require mysqldump or shell access? =
No. Database export and import are performed entirely in PHP using $wpdb.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
