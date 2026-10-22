# OneClick Site Backup & Migrate

A production-ready WordPress plugin that backs up your entire site into a single `.ocb` file and restores it anywhere — with one click. Built for shared hosting with chunked AJAX processing.

![WordPress](https://img.shields.io/badge/WordPress-5.6%2B-blue?logo=wordpress)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple?logo=php)
![License](https://img.shields.io/badge/License-GPLv2-green)
![Version](https://img.shields.io/badge/Version-1.0.0-orange)

---

## Features

### 🗄️ One-Click Full Backup
- Exports the **entire database** in pure PHP via `$wpdb` — no `mysqldump` or shell access needed
- Packages `wp-content` (uploads, themes, plugins, mu-plugins) into one `.ocb` archive (ZIP internally)
- Includes a `manifest.json` with WP version, PHP version, site URL, active plugins, charset, and more
- Automatically **excludes** cache directories, this plugin's own backup folder, and other backup plugin folders
- Granular options: include/exclude **database**, **uploads**, **themes**, **plugins** individually

### 📋 Backups List
- Table view showing filename, date, size, WP version, and source site URL
- Per-row actions: **Download**, **Restore**, **Delete**
- Live animated progress bar during backup creation

### 🔄 One-Click Import / Restore
- **Drag-and-drop** or browse to upload any `.ocb` file (even from a different site)
- File uploads work in **2 MB chunks** via the JS File API — bypasses `upload_max_filesize` limits
- Reads and displays the backup manifest before confirming
- Automatically creates a **safety snapshot** of the current DB before overwriting
- One-click **rollback** if the import fails
- Performs **safe serialized-data URL replacement** (unserialize → replace → reserialize with correct byte lengths)
- Handles **different table prefixes** between source and destination
- Handles `http`/`https` and `www`/non-`www` URL variants automatically
- Flushes rewrite rules and clears all transients/caches after import

---

## How It Works on Shared Hosting

| Problem | Solution |
|---|---|
| `max_execution_time` | Each AJAX call processes one small chunk; JS fires the next call immediately |
| `memory_limit` | DB exported row-by-row; files streamed 64 KB at a time |
| `upload_max_filesize` | JS splits the file into 2 MB chunks using the File API |
| No shell / exec | Everything via `$wpdb` and PHP's `ZipArchive` |
| No `ZipArchive` | Graceful fallback to `PclZip` (bundled with WordPress core) |

---

## Security

- Only users with `manage_options` capability can access anything
- **Nonces** on every AJAX action and form
- Backup directory protected with `.htaccess`, `web.config`, and `index.php`
- Directory name has a **random unguessable suffix** stored in `wp_options`
- Downloads served only through an **authenticated PHP handler** — never direct URL
- **Path-traversal** and **zip-slip** protection on extract
- All inputs sanitized; prepared statements throughout
- Only `.ocb` files accepted on upload

---

## File Structure

```
oneclick-backup/
├── oneclick-backup.php              ← Main bootstrap (constants, autoloader, hooks)
├── uninstall.php                    ← Clean removal of options/transients
├── readme.txt                       ← WordPress.org readme
├── includes/
│   ├── class-ocb-security.php       ← Capability checks, nonces, path/zip-slip guard
│   ├── class-ocb-file-handler.php   ← Backup dir, listing, download streaming, upload assembly
│   ├── class-ocb-db-export.php      ← Chunked SQL export via $wpdb
│   ├── class-ocb-db-import.php      ← Chunked SQL import + safety snapshot + rollback
│   ├── class-ocb-url-replacer.php   ← Safe serialized-data URL search-replace
│   ├── class-ocb-backup-engine.php  ← Multi-phase backup orchestrator
│   ├── class-ocb-import-engine.php  ← Multi-phase import orchestrator
│   ├── class-ocb-admin.php          ← Admin menu, 3 tabs, modal, asset enqueueing
│   └── class-ocb-ajax.php           ← All wp_ajax_ocb_* action handlers
└── assets/
    ├── css/admin.css                ← Modern admin UI styles
    └── js/admin.js                  ← Chunked AJAX, drag-drop upload, import progress
```

---

## Backup & Import Phases

### Backup
`init` → `export_db` → `collect_files` → `zip_files` → `finalize` → `done`

### Import
`validate` → `snapshot` → `extract_db` → `import_db` → `extract_files` → `url_replace` → `finalize` → `done`

---

## AJAX Actions

| Action | Description |
|---|---|
| `ocb_backup_start` | Starts a new backup session |
| `ocb_backup_chunk` | Processes one backup chunk |
| `ocb_backup_delete` | Deletes a backup file |
| `ocb_upload_chunk` | Receives one 2 MB upload chunk |
| `ocb_import_start` | Validates assembled `.ocb`, starts import session |
| `ocb_import_chunk` | Processes one import chunk |
| `ocb_import_rollback` | Restores safety DB snapshot |
| `ocb_restore_start` | Starts restore from an existing backup |
| `ocb_get_manifest` | Returns manifest JSON for a backup file |

---

## Installation

1. Clone or download this repository
2. Copy the `oneclick-backup/` folder to `wp-content/plugins/`
3. Activate via **Plugins → Installed Plugins** in your WordPress admin
4. Navigate to **OneClick Backup** in the admin sidebar

### Requirements
- WordPress 5.6+
- PHP 7.4+
- `ZipArchive` PHP extension (or `PclZip` fallback via WP core)
- `manage_options` capability (administrator role)

---

## License

GPL v2 or later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html)
