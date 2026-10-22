/**
 * OneClick Backup — Admin JavaScript
 *
 * Handles:
 *  - Chunked backup creation (AJAX polling)
 *  - Chunked file upload (File API → AJAX chunks)
 *  - Import / Restore AJAX progress
 *  - Confirmation modal
 *  - Backups list actions (delete, restore, download)
 *
 * Requires: jQuery, OCB (localised via wp_localize_script)
 */
/* global OCB, jQuery */
(function ($) {
    'use strict';

    // ── State ─────────────────────────────────────────────────────────────────

    const state = {
        backup: {
            sessionId: null,
            running:   false,
        },
        upload: {
            sessionId:    null,
            file:         null,
            importSid:    null,
        },
        restore: {
            sessionId: null,
            running:   false,
        },
    };

    // ── Helpers ───────────────────────────────────────────────────────────────

    function ajax(action, data, nonce_key) {
        return $.ajax({
            url:    OCB.ajax_url,
            method: 'POST',
            data:   Object.assign({ action, _nonce: OCB.nonces[nonce_key] }, data),
        });
    }

    function setProgress(barId, labelId, pct, msg) {
        $('#' + barId).css('width', pct + '%');
        if (msg !== undefined) $('#' + labelId).text(msg);
    }

    function showAlert(id, msg, type) {
        const el = $('#' + id);
        el.removeClass('ocb-alert-success ocb-alert-error')
          .addClass('ocb-alert-' + type)
          .html(msg)
          .slideDown(200);
    }

    function hideAlert(id) { $('#' + id).slideUp(100).html(''); }

    function formatBytes(bytes) {
        if (bytes < 1024)       return bytes + ' B';
        if (bytes < 1048576)    return (bytes / 1024).toFixed(1) + ' KB';
        if (bytes < 1073741824) return (bytes / 1048576).toFixed(1) + ' MB';
        return (bytes / 1073741824).toFixed(2) + ' GB';
    }

    // ── Modal ─────────────────────────────────────────────────────────────────

    let modalCallback = null;

    function showModal(body, confirmLabel, onConfirm) {
        modalCallback = onConfirm;
        $('#ocb-modal-body').text(body);
        if (confirmLabel) $('#ocb-modal-confirm').text(confirmLabel);
        $('#ocb-modal').fadeIn(200);
        $('#ocb-modal-confirm').focus();
    }

    function closeModal() {
        $('#ocb-modal').fadeOut(150);
        modalCallback = null;
    }

    $(document).on('click', '#ocb-modal-confirm', function () {
        closeModal();
        if (typeof modalCallback === 'function') modalCallback();
    });

    $(document).on('click', '#ocb-modal-cancel, #ocb-modal-close', closeModal);

    $(document).on('click', '.ocb-modal-overlay', function (e) {
        if ($(e.target).is('.ocb-modal-overlay')) closeModal();
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') closeModal();
    });

    // ── ================================================================ ──
    // ── BACKUP TAB                                                       ──
    // ── ================================================================ ──

    $(document).on('click', '#ocb-btn-backup', function () {
        if (state.backup.running) return;

        hideAlert('ocb-backup-success');
        hideAlert('ocb-backup-error');

        const options = {
            include_db:      $('#ocb-include-db').is(':checked')      ? 1 : 0,
            include_uploads: $('#ocb-include-uploads').is(':checked') ? 1 : 0,
            include_themes:  $('#ocb-include-themes').is(':checked')  ? 1 : 0,
            include_plugins: $('#ocb-include-plugins').is(':checked') ? 1 : 0,
        };

        state.backup.running = true;
        $('#ocb-btn-backup').prop('disabled', true).html(
            '<span class="dashicons dashicons-update ocb-spin"></span> ' + OCB.i18n.backup_running
        );
        $('#ocb-backup-progress-wrap').slideDown(200);
        setProgress('ocb-backup-bar', 'ocb-backup-label', 2, OCB.i18n.backup_running);

        // 1. Start backup session.
        ajax('ocb_backup_start', options, 'backup_start')
            .done(function (res) {
                if (!res.success) {
                    return backupError(res.data?.message || 'Start failed.');
                }
                state.backup.sessionId = res.data.session_id;
                runBackupChunk();
            })
            .fail(function () { backupError('AJAX request failed.'); });
    });

    function runBackupChunk() {
        ajax('ocb_backup_chunk', { session_id: state.backup.sessionId }, 'backup_chunk')
            .done(function (res) {
                if (!res.success) {
                    return backupError(res.data?.message || res.data?.error || 'Chunk failed.');
                }
                const d = res.data;
                setProgress('ocb-backup-bar', 'ocb-backup-label', d.progress, d.step);

                if (d.done) {
                    backupDone(d.filename);
                } else {
                    // Schedule next chunk immediately (no delay needed on shared hosting
                    // since each chunk is already chunked to be fast).
                    setTimeout(runBackupChunk, 0);
                }
            })
            .fail(function () { backupError('AJAX request failed.'); });
    }

    function backupDone(filename) {
        state.backup.running = false;
        $('#ocb-btn-backup').prop('disabled', false).html(
            '<span class="dashicons dashicons-backup"></span> Create Backup'
        );
        setProgress('ocb-backup-bar', 'ocb-backup-label', 100, OCB.i18n.done);

        const msg = '✅ ' + OCB.i18n.done + (filename ? ' — ' + filename : '');
        showAlert('ocb-backup-success', msg, 'success');

        // Refresh list tab badge if visible.
        state.backup.sessionId = null;
    }

    function backupError(msg) {
        state.backup.running = false;
        $('#ocb-btn-backup').prop('disabled', false).html(
            '<span class="dashicons dashicons-backup"></span> Create Backup'
        );
        showAlert('ocb-backup-error', OCB.i18n.error_prefix + msg, 'error');
        state.backup.sessionId = null;
    }

    // ── ================================================================ ──
    // ── BACKUPS LIST TAB                                                 ──
    // ── ================================================================ ──

    // Delete.
    $(document).on('click', '.ocb-btn-delete', function () {
        const filename = $(this).data('filename');
        const row      = $(this).closest('tr');

        showModal(OCB.i18n.confirm_delete, 'Delete', function () {
            ajax('ocb_backup_delete', { filename }, 'backup_delete')
                .done(function (res) {
                    if (res.success) {
                        row.fadeOut(300, function () { $(this).remove(); });
                    } else {
                        alert(res.data?.message || 'Delete failed.');
                    }
                });
        });
    });

    // Restore (from list).
    $(document).on('click', '.ocb-btn-restore', function () {
        if (state.restore.running) return;
        const filename = $(this).data('filename');

        showModal(OCB.i18n.confirm_restore, 'Yes, Restore', function () {
            startRestore(filename);
        });
    });

    function startRestore(filename) {
        state.restore.running = true;
        hideAlert('ocb-restore-success');
        hideAlert('ocb-restore-error');
        $('#ocb-restore-progress-wrap').slideDown(200);
        setProgress('ocb-restore-bar', 'ocb-restore-label', 2, 'Starting restore…');

        ajax('ocb_restore_start', { filename }, 'restore_start')
            .done(function (res) {
                if (!res.success) {
                    return restoreError(res.data?.message || 'Restore start failed.', null);
                }
                state.restore.sessionId = res.data.session_id;
                runImportChunk('restore');
            })
            .fail(function () { restoreError('AJAX request failed.', null); });
    }

    function runImportChunk(context) {
        // context is 'restore' (list tab) or 'import' (import tab).
        const sid = context === 'restore' ? state.restore.sessionId : state.upload.importSid;
        const barId   = context === 'restore' ? 'ocb-restore-bar'   : 'ocb-import-bar';
        const labelId = context === 'restore' ? 'ocb-restore-label' : 'ocb-import-label';
        const errFn   = context === 'restore' ? restoreError        : importError;

        ajax('ocb_import_chunk', { session_id: sid }, 'import_chunk')
            .done(function (res) {
                if (!res.success) {
                    const d = res.data || {};
                    return errFn(d.error || d.message || 'Import chunk failed.', sid);
                }
                const d = res.data;
                setProgress(barId, labelId, d.progress, d.step);

                if (d.done) {
                    if (context === 'restore') {
                        restoreDone();
                    } else {
                        importDone();
                    }
                } else {
                    setTimeout(function () { runImportChunk(context); }, 0);
                }
            })
            .fail(function () { errFn('AJAX request failed.', sid); });
    }

    function restoreDone() {
        state.restore.running = false;
        setProgress('ocb-restore-bar', 'ocb-restore-label', 100, OCB.i18n.done);
        showAlert('ocb-restore-success', '✅ Restore complete! Page will reload.', 'success');
        setTimeout(function () { window.location.reload(); }, 3000);
    }

    function restoreError(msg, sid) {
        state.restore.running = false;
        const html = OCB.i18n.error_prefix + msg;
        showAlert('ocb-restore-error', html, 'error');

        if (sid && confirm(OCB.i18n.rollback_confirm)) {
            ajax('ocb_import_rollback', { session_id: sid }, 'import_rollback')
                .done(function (res) {
                    alert(res.success ? res.data.message : res.data.message);
                });
        }
    }

    // ── ================================================================ ──
    // ── IMPORT TAB — Drag & Drop + Chunked Upload                       ──
    // ── ================================================================ ──

    const $dropzone = $('#ocb-dropzone');
    const $fileInput = $('#ocb-file-input');

    // Browse button.
    $(document).on('click', '#ocb-browse-btn', function (e) {
        e.stopPropagation();
        $fileInput.trigger('click');
    });

    // Click dropzone.
    $(document).on('click', '#ocb-dropzone', function () {
        $fileInput.trigger('click');
    });

    $fileInput.on('change', function () {
        if (this.files.length) setSelectedFile(this.files[0]);
    });

    // Drag events.
    $dropzone
        .on('dragover dragenter', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $dropzone.addClass('ocb-dropzone-hover');
        })
        .on('dragleave dragend drop', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $dropzone.removeClass('ocb-dropzone-hover');
        })
        .on('drop', function (e) {
            const dt    = e.originalEvent.dataTransfer;
            const files = dt && dt.files;
            if (files && files.length) setSelectedFile(files[0]);
        });

    function setSelectedFile(file) {
        // Validate extension.
        if (!file.name.toLowerCase().endsWith('.ocb')) {
            alert('Only .ocb backup files are allowed.');
            return;
        }

        state.upload.file      = file;
        state.upload.sessionId = generateId();
        state.upload.importSid = null;

        $('#ocb-selected-filename').text(file.name);
        $('#ocb-selected-filesize').text('(' + formatBytes(file.size) + ')');
        $('#ocb-file-info').slideDown(150);
        $('#ocb-manifest-preview').slideUp(100);
        hideAlert('ocb-import-success');
        hideAlert('ocb-import-error');

        // Auto-start upload.
        startChunkedUpload(file);
    }

    // Clear selection.
    $(document).on('click', '#ocb-clear-file', function (e) {
        e.stopPropagation();
        state.upload.file      = null;
        state.upload.sessionId = null;
        state.upload.importSid = null;
        $fileInput.val('');
        $('#ocb-file-info').slideUp(150);
        $('#ocb-manifest-preview').slideUp(100);
        $('#ocb-upload-progress-wrap').slideUp(100);
        hideAlert('ocb-import-success');
        hideAlert('ocb-import-error');
    });

    function startChunkedUpload(file) {
        const chunkSize  = OCB.chunk_size; // 2 MB
        const totalChunks = Math.ceil(file.size / chunkSize);
        const sessionId  = state.upload.sessionId;

        $('#ocb-upload-progress-wrap').slideDown(200);
        setProgress('ocb-upload-bar', 'ocb-upload-label', 0, OCB.i18n.uploading);

        uploadChunk(file, sessionId, 0, totalChunks, chunkSize);
    }

    function uploadChunk(file, sessionId, chunkIndex, totalChunks, chunkSize) {
        const start  = chunkIndex * chunkSize;
        const end    = Math.min(start + chunkSize, file.size);
        const blob   = file.slice(start, end);

        const formData = new FormData();
        formData.append('action',       'ocb_upload_chunk');
        formData.append('_nonce',       OCB.nonces.upload_chunk);
        formData.append('session_id',   sessionId);
        formData.append('chunk_index',  chunkIndex);
        formData.append('total_chunks', totalChunks);
        formData.append('filename',     file.name);
        formData.append('file',         blob, file.name);

        $.ajax({
            url:         OCB.ajax_url,
            method:      'POST',
            data:        formData,
            processData: false,
            contentType: false,
        })
        .done(function (res) {
            if (!res.success) {
                return uploadError(res.data?.message || 'Upload failed.');
            }

            const pct = Math.round(((chunkIndex + 1) / totalChunks) * 100);
            setProgress('ocb-upload-bar', 'ocb-upload-label', pct,
                OCB.i18n.uploading + ' ' + pct + '%');

            if (res.data.done) {
                // All chunks received — start import session.
                onUploadComplete(sessionId);
            } else {
                uploadChunk(file, sessionId, chunkIndex + 1, totalChunks, chunkSize);
            }
        })
        .fail(function () { uploadError('Upload network error.'); });
    }

    function onUploadComplete(uploadSessionId) {
        setProgress('ocb-upload-bar', 'ocb-upload-label', 100, 'Upload complete. Reading backup…');

        ajax('ocb_import_start', { upload_session_id: uploadSessionId }, 'import_start')
            .done(function (res) {
                if (!res.success) {
                    return uploadError(res.data?.message || 'Import start failed.');
                }
                state.upload.importSid = res.data.session_id;
                showManifestPreview(res.data.manifest);
            })
            .fail(function () { uploadError('AJAX error after upload.'); });
    }

    function showManifestPreview(manifest) {
        $('#mp-site-url').text(manifest.site_url   || '—');
        $('#mp-created').text(manifest.created      || '—');
        $('#mp-wp-ver').text(manifest.wp_version    || '—');
        $('#mp-php-ver').text(manifest.php_version  || '—');
        $('#mp-plugin-ver').text(manifest.plugin_version || '—');
        $('#ocb-manifest-preview').slideDown(200);
    }

    function uploadError(msg) {
        setProgress('ocb-upload-bar', 'ocb-upload-label', 0, '');
        showAlert('ocb-import-error', OCB.i18n.error_prefix + msg, 'error');
    }

    // ── Start import (after manifest confirmed) ────────────────────────────────

    $(document).on('click', '#ocb-btn-import', function () {
        if (!state.upload.importSid) {
            alert('No backup session active. Please upload a file first.');
            return;
        }

        showModal(
            OCB.i18n.confirm_restore,
            'Yes, Start Import',
            function () {
                $('#ocb-manifest-preview').slideUp(150);
                $('#ocb-upload-progress-wrap').slideUp(150);
                $('#ocb-import-progress-wrap').slideDown(200);
                setProgress('ocb-import-bar', 'ocb-import-label', 2, OCB.i18n.import_running);
                hideAlert('ocb-import-success');
                hideAlert('ocb-import-error');
                runImportChunk('import');
            }
        );
    });

    function importDone() {
        setProgress('ocb-import-bar', 'ocb-import-label', 100, OCB.i18n.done);
        showAlert('ocb-import-success',
            '✅ Import complete! Your site has been restored. This page will reload in 5 seconds.',
            'success');
        setTimeout(function () { window.location.reload(); }, 5000);
    }

    function importError(msg, sid) {
        const $errDiv = $('#ocb-import-error');
        $('#ocb-import-error-msg').text(OCB.i18n.error_prefix + msg);
        $errDiv.slideDown(200);

        if (sid) {
            $('#ocb-btn-rollback').show();
            $('#ocb-btn-rollback').data('sid', sid);
        }
    }

    $(document).on('click', '#ocb-btn-rollback', function () {
        const sid = $(this).data('sid');
        $(this).prop('disabled', true).text('Rolling back…');

        ajax('ocb_import_rollback', { session_id: sid }, 'import_rollback')
            .done(function (res) {
                const msg = res.success ? res.data.message : (res.data?.message || 'Rollback failed.');
                alert(msg);
                $('#ocb-btn-rollback').prop('disabled', false).text('Roll Back to Previous Database');
            });
    });

    // ── ================================================================ ──
    // ── Utility                                                          ──
    // ── ================================================================ ──

    function generateId() {
        return Math.random().toString(36).slice(2, 18);
    }

    // Add spin animation for the dashicon on button.
    $('<style>.ocb-spin { animation: ocb-spin 1s linear infinite; } @keyframes ocb-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }</style>')
        .appendTo('head');

}(jQuery));
