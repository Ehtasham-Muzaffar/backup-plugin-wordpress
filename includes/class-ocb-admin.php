<?php
/**
 * Admin UI: menus, tabs, asset enqueueing, and template rendering.
 *
 * @package OneClickBackup
 */

defined( 'ABSPATH' ) || exit;

class OCB_Admin {

    public static function init(): void {
        add_action( 'admin_menu',            [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
    }

    // ── Menu ──────────────────────────────────────────────────────────────────

    public static function register_menu(): void {
        add_menu_page(
            __( 'OneClick Backup', 'oneclick-backup' ),
            __( 'OneClick Backup', 'oneclick-backup' ),
            OCB_Security::CAP,
            OCB_PLUGIN_SLUG,
            [ __CLASS__, 'render_page' ],
            'dashicons-backup',
            80
        );
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public static function enqueue_assets( string $hook ): void {
        if ( 'toplevel_page_' . OCB_PLUGIN_SLUG !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'ocb-admin',
            OCB_PLUGIN_URL . 'assets/css/admin.css',
            [],
            OCB_VERSION
        );

        wp_enqueue_script(
            'ocb-admin',
            OCB_PLUGIN_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            OCB_VERSION,
            true
        );

        wp_localize_script( 'ocb-admin', 'OCB', [
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'nonces'     => [
                'backup_start'    => OCB_Security::create_nonce( 'backup_start' ),
                'backup_chunk'    => OCB_Security::create_nonce( 'backup_chunk' ),
                'backup_delete'   => OCB_Security::create_nonce( 'backup_delete' ),
                'backup_download' => OCB_Security::create_nonce( 'backup_download' ),
                'upload_chunk'    => OCB_Security::create_nonce( 'upload_chunk' ),
                'import_start'    => OCB_Security::create_nonce( 'import_start' ),
                'import_chunk'    => OCB_Security::create_nonce( 'import_chunk' ),
                'import_rollback' => OCB_Security::create_nonce( 'import_rollback' ),
                'restore_start'   => OCB_Security::create_nonce( 'restore_start' ),
                'get_manifest'    => OCB_Security::create_nonce( 'get_manifest' ),
            ],
            'chunk_size' => OCB_CHUNK_UPLOAD,
            'i18n'       => [
                'confirm_delete'   => __( 'Are you sure you want to delete this backup?', 'oneclick-backup' ),
                'confirm_restore'  => __( 'This will overwrite your current site. Are you sure?', 'oneclick-backup' ),
                'backup_running'   => __( 'Backup in progress…', 'oneclick-backup' ),
                'import_running'   => __( 'Import in progress…', 'oneclick-backup' ),
                'error_prefix'     => __( 'Error: ', 'oneclick-backup' ),
                'done'             => __( 'Done!', 'oneclick-backup' ),
                'uploading'        => __( 'Uploading…', 'oneclick-backup' ),
                'rollback_confirm' => __( 'Import failed. Roll back to the previous database?', 'oneclick-backup' ),
            ],
        ] );
    }

    // ── Page render ───────────────────────────────────────────────────────────

    public static function render_page(): void {
        if ( ! OCB_Security::current_user_can() ) {
            wp_die( esc_html__( 'Access denied.', 'oneclick-backup' ) );
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'backup';
        $allowed_tabs = [ 'backup', 'list', 'import' ];
        if ( ! in_array( $tab, $allowed_tabs, true ) ) {
            $tab = 'backup';
        }

        echo '<div class="wrap ocb-wrap">';
        echo '<h1 class="ocb-page-title"><span class="dashicons dashicons-backup"></span> ' . esc_html__( 'OneClick Site Backup & Migrate', 'oneclick-backup' ) . '</h1>';

        // Tabs navigation.
        $tabs = [
            'backup' => __( 'Create Backup', 'oneclick-backup' ),
            'list'   => __( 'Backups List', 'oneclick-backup' ),
            'import' => __( 'Import / Restore', 'oneclick-backup' ),
        ];

        echo '<nav class="ocb-tabs-nav">';
        foreach ( $tabs as $slug => $label ) {
            $url     = add_query_arg( [ 'page' => OCB_PLUGIN_SLUG, 'tab' => $slug ], admin_url( 'admin.php' ) );
            $active  = $tab === $slug ? ' class="ocb-tab-active"' : '';
            echo '<a href="' . esc_url( $url ) . '"' . $active . '>' . esc_html( $label ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        echo '</nav>';

        echo '<div class="ocb-tab-content">';
        switch ( $tab ) {
            case 'backup':
                self::render_backup_tab();
                break;
            case 'list':
                self::render_list_tab();
                break;
            case 'import':
                self::render_import_tab();
                break;
        }
        echo '</div>';
        echo '</div>'; // .ocb-wrap

        // Confirmation modal (shared).
        self::render_modal();
    }

    // ── Tab: Create Backup ────────────────────────────────────────────────────

    private static function render_backup_tab(): void {
        ?>
        <div class="ocb-card" id="ocb-backup-card">
            <h2><?php esc_html_e( 'Create a New Backup', 'oneclick-backup' ); ?></h2>
            <p class="ocb-desc"><?php esc_html_e( 'Creates a complete .ocb archive of your database and wp-content folder. Works in small chunks so it won\'t time out on shared hosting.', 'oneclick-backup' ); ?></p>

            <div class="ocb-options">
                <h3><?php esc_html_e( 'What to include', 'oneclick-backup' ); ?></h3>
                <label class="ocb-checkbox">
                    <input type="checkbox" id="ocb-include-db" checked> <?php esc_html_e( 'Database', 'oneclick-backup' ); ?>
                </label>
                <label class="ocb-checkbox">
                    <input type="checkbox" id="ocb-include-uploads" checked> <?php esc_html_e( 'Uploads', 'oneclick-backup' ); ?>
                </label>
                <label class="ocb-checkbox">
                    <input type="checkbox" id="ocb-include-themes" checked> <?php esc_html_e( 'Themes', 'oneclick-backup' ); ?>
                </label>
                <label class="ocb-checkbox">
                    <input type="checkbox" id="ocb-include-plugins" checked> <?php esc_html_e( 'Plugins', 'oneclick-backup' ); ?>
                </label>
            </div>

            <button id="ocb-btn-backup" class="ocb-btn ocb-btn-primary">
                <span class="dashicons dashicons-backup"></span>
                <?php esc_html_e( 'Create Backup', 'oneclick-backup' ); ?>
            </button>

            <div class="ocb-progress-wrap" id="ocb-backup-progress-wrap" style="display:none">
                <div class="ocb-progress-bar-outer">
                    <div class="ocb-progress-bar-inner" id="ocb-backup-bar" style="width:0%"></div>
                </div>
                <p class="ocb-progress-label" id="ocb-backup-label"><?php esc_html_e( 'Starting…', 'oneclick-backup' ); ?></p>
            </div>

            <div class="ocb-alert ocb-alert-success" id="ocb-backup-success" style="display:none"></div>
            <div class="ocb-alert ocb-alert-error" id="ocb-backup-error" style="display:none"></div>
        </div>
        <?php
    }

    // ── Tab: Backups List ─────────────────────────────────────────────────────

    private static function render_list_tab(): void {
        $backups = OCB_File_Handler::list_backups();
        ?>
        <div class="ocb-card" id="ocb-list-card">
            <h2><?php esc_html_e( 'All Backups', 'oneclick-backup' ); ?></h2>

            <?php if ( empty( $backups ) ) : ?>
                <div class="ocb-empty-state">
                    <span class="dashicons dashicons-backup ocb-empty-icon"></span>
                    <p><?php esc_html_e( 'No backups found. Create your first backup from the "Create Backup" tab.', 'oneclick-backup' ); ?></p>
                </div>
            <?php else : ?>
            <table class="widefat ocb-table" id="ocb-backups-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Filename', 'oneclick-backup' ); ?></th>
                        <th><?php esc_html_e( 'Date', 'oneclick-backup' ); ?></th>
                        <th><?php esc_html_e( 'Size', 'oneclick-backup' ); ?></th>
                        <th><?php esc_html_e( 'WP Version', 'oneclick-backup' ); ?></th>
                        <th><?php esc_html_e( 'Site URL', 'oneclick-backup' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'oneclick-backup' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $backups as $b ) :
                    $dl_nonce = wp_create_nonce( 'ocb_download_' . $b['filename'] );
                    $dl_url   = add_query_arg( [
                        'ocb_action' => 'download',
                        'filename'   => $b['filename'],
                        '_wpnonce'   => $dl_nonce,
                    ], admin_url( 'admin.php' ) );
                    ?>
                    <tr data-filename="<?php echo esc_attr( $b['filename'] ); ?>">
                        <td class="ocb-filename"><?php echo esc_html( $b['filename'] ); ?></td>
                        <td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $b['modified'] ) ); ?></td>
                        <td><?php echo esc_html( $b['size_human'] ); ?></td>
                        <td><?php echo esc_html( $b['wp_version'] ); ?></td>
                        <td class="ocb-url-cell"><?php echo esc_html( $b['site_url'] ); ?></td>
                        <td class="ocb-actions-cell">
                            <a href="<?php echo esc_url( $dl_url ); ?>" class="ocb-btn ocb-btn-sm ocb-btn-secondary">
                                <span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Download', 'oneclick-backup' ); ?>
                            </a>
                            <button class="ocb-btn ocb-btn-sm ocb-btn-warning ocb-btn-restore"
                                    data-filename="<?php echo esc_attr( $b['filename'] ); ?>">
                                <span class="dashicons dashicons-image-rotate"></span> <?php esc_html_e( 'Restore', 'oneclick-backup' ); ?>
                            </button>
                            <button class="ocb-btn ocb-btn-sm ocb-btn-danger ocb-btn-delete"
                                    data-filename="<?php echo esc_attr( $b['filename'] ); ?>">
                                <span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Delete', 'oneclick-backup' ); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <!-- Per-row progress (restore) -->
            <div class="ocb-progress-wrap" id="ocb-restore-progress-wrap" style="display:none">
                <div class="ocb-progress-bar-outer">
                    <div class="ocb-progress-bar-inner" id="ocb-restore-bar" style="width:0%"></div>
                </div>
                <p class="ocb-progress-label" id="ocb-restore-label"><?php esc_html_e( 'Starting restore…', 'oneclick-backup' ); ?></p>
            </div>
            <div class="ocb-alert ocb-alert-success" id="ocb-restore-success" style="display:none"></div>
            <div class="ocb-alert ocb-alert-error" id="ocb-restore-error" style="display:none"></div>
        </div>
        <?php
    }

    // ── Tab: Import ───────────────────────────────────────────────────────────

    private static function render_import_tab(): void {
        ?>
        <div class="ocb-card" id="ocb-import-card">
            <h2><?php esc_html_e( 'Import / Restore from File', 'oneclick-backup' ); ?></h2>
            <p class="ocb-desc"><?php esc_html_e( 'Upload a .ocb backup file (even from a different site) and restore it here. Large files are uploaded in chunks automatically.', 'oneclick-backup' ); ?></p>

            <input type="file" id="ocb-file-input" accept=".ocb" style="display:none">
            <div class="ocb-dropzone" id="ocb-dropzone">
                <span class="dashicons dashicons-upload ocb-dropzone-icon"></span>
                <p class="ocb-dropzone-text"><?php esc_html_e( 'Drag & drop your .ocb file here, or click to browse', 'oneclick-backup' ); ?></p>
                <button type="button" class="ocb-btn ocb-btn-secondary" id="ocb-browse-btn"><?php esc_html_e( 'Browse File', 'oneclick-backup' ); ?></button>
            </div>

            <div id="ocb-file-info" style="display:none">
                <div class="ocb-selected-file">
                    <span class="dashicons dashicons-media-archive"></span>
                    <span id="ocb-selected-filename"></span>
                    <span class="ocb-file-size" id="ocb-selected-filesize"></span>
                    <button class="ocb-btn-icon" id="ocb-clear-file" title="<?php esc_attr_e( 'Remove', 'oneclick-backup' ); ?>">&#x2715;</button>
                </div>
            </div>

            <!-- Upload progress -->
            <div class="ocb-progress-wrap" id="ocb-upload-progress-wrap" style="display:none">
                <div class="ocb-progress-bar-outer">
                    <div class="ocb-progress-bar-inner ocb-bar-upload" id="ocb-upload-bar" style="width:0%"></div>
                </div>
                <p class="ocb-progress-label" id="ocb-upload-label"><?php esc_html_e( 'Uploading…', 'oneclick-backup' ); ?></p>
            </div>

            <!-- Manifest preview (shown after upload) -->
            <div class="ocb-manifest-preview" id="ocb-manifest-preview" style="display:none">
                <h3><?php esc_html_e( 'Backup Information', 'oneclick-backup' ); ?></h3>
                <table class="ocb-manifest-table">
                    <tr><th><?php esc_html_e( 'Source Site', 'oneclick-backup' ); ?></th><td id="mp-site-url"></td></tr>
                    <tr><th><?php esc_html_e( 'Created', 'oneclick-backup' ); ?></th><td id="mp-created"></td></tr>
                    <tr><th><?php esc_html_e( 'WordPress', 'oneclick-backup' ); ?></th><td id="mp-wp-ver"></td></tr>
                    <tr><th><?php esc_html_e( 'PHP', 'oneclick-backup' ); ?></th><td id="mp-php-ver"></td></tr>
                    <tr><th><?php esc_html_e( 'Plugin Version', 'oneclick-backup' ); ?></th><td id="mp-plugin-ver"></td></tr>
                </table>

                <div class="ocb-warning-box">
                    <span class="dashicons dashicons-warning"></span>
                    <?php esc_html_e( 'Importing will overwrite your current WordPress database and files. A safety snapshot will be created automatically so you can roll back if needed.', 'oneclick-backup' ); ?>
                </div>

                <button class="ocb-btn ocb-btn-primary" id="ocb-btn-import">
                    <span class="dashicons dashicons-image-rotate"></span>
                    <?php esc_html_e( 'Start Import', 'oneclick-backup' ); ?>
                </button>
            </div>

            <!-- Import progress -->
            <div class="ocb-progress-wrap" id="ocb-import-progress-wrap" style="display:none">
                <div class="ocb-progress-bar-outer">
                    <div class="ocb-progress-bar-inner" id="ocb-import-bar" style="width:0%"></div>
                </div>
                <p class="ocb-progress-label" id="ocb-import-label"><?php esc_html_e( 'Importing…', 'oneclick-backup' ); ?></p>
            </div>

            <div class="ocb-alert ocb-alert-success" id="ocb-import-success" style="display:none"></div>
            <div class="ocb-alert ocb-alert-error" id="ocb-import-error" style="display:none">
                <p id="ocb-import-error-msg"></p>
                <button class="ocb-btn ocb-btn-danger" id="ocb-btn-rollback" style="display:none">
                    <span class="dashicons dashicons-undo"></span>
                    <?php esc_html_e( 'Roll Back to Previous Database', 'oneclick-backup' ); ?>
                </button>
            </div>
        </div>
        <?php
    }

    // ── Shared: Confirmation modal ────────────────────────────────────────────

    private static function render_modal(): void {
        ?>
        <div class="ocb-modal-overlay" id="ocb-modal" style="display:none" role="dialog" aria-modal="true" aria-labelledby="ocb-modal-title">
            <div class="ocb-modal-box">
                <button class="ocb-modal-close" id="ocb-modal-close" aria-label="<?php esc_attr_e( 'Close', 'oneclick-backup' ); ?>">&#x2715;</button>
                <div class="ocb-modal-icon"><span class="dashicons dashicons-warning"></span></div>
                <h2 id="ocb-modal-title"><?php esc_html_e( 'Are you sure?', 'oneclick-backup' ); ?></h2>
                <p id="ocb-modal-body"></p>
                <div class="ocb-modal-footer">
                    <button class="ocb-btn ocb-btn-secondary" id="ocb-modal-cancel"><?php esc_html_e( 'Cancel', 'oneclick-backup' ); ?></button>
                    <button class="ocb-btn ocb-btn-danger" id="ocb-modal-confirm"><?php esc_html_e( 'Yes, Proceed', 'oneclick-backup' ); ?></button>
                </div>
            </div>
        </div>
        <?php
    }
}
