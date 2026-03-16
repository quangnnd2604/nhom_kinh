/* global mmlAdmin */
(function ($) {
    'use strict';

    var MMLAdmin = {

        init: function () {
            this.bindFlagPicker();
            this.bindLangPresetPicker();
            this.bindDeleteConfirm();
            this.bindAddStringModal();
            this.bindDeleteString();
            this.bindCloneButtons();
            this.bindManualAddString();
            this.bindAutoTranslate();
        },

        // ── Language Preset Picker ────────────────────────────────────────

        bindLangPresetPicker: function () {
            var $search  = $('#mml-lang-search');
            var $select  = $('#mml-lang-preset');

            if (!$search.length || !$select.length) return;

            var registry = (mmlAdmin && mmlAdmin.langRegistry) ? mmlAdmin.langRegistry : [];
            if (!registry.length) return;

            // Separator label
            var priorityCodes = ['en', 'zh-cn', 'ko', 'ru'];

            // Populate select once
            var allOpts = [];
            var separatorInserted = false;
            registry.forEach(function (lang, idx) {
                if (!separatorInserted && idx > 0 && priorityCodes.indexOf(lang.code) === -1) {
                    // First non-priority entry → insert visual divider
                    allOpts.push({ text: '── Các ngôn ngữ khác ──', code: '', disabled: true });
                    separatorInserted = true;
                }
                allOpts.push({ text: lang.name + ' (' + lang.code + ')', code: lang.code });
            });

            function renderOptions(filter) {
                $select.empty();
                allOpts.forEach(function (opt) {
                    if (opt.disabled) {
                        if (!filter) {
                            $select.append($('<option>').val('').text(opt.text).prop('disabled', true));
                        }
                        return;
                    }
                    if (filter && opt.text.toLowerCase().indexOf(filter) === -1 && opt.code.toLowerCase().indexOf(filter) === -1) {
                        return;
                    }
                    $select.append($('<option>').val(opt.code).text(opt.text));
                });
            }

            renderOptions('');

            // Pre-select if editing an existing language
            var currentCode = $('#lang_code').val();
            if (currentCode) {
                $select.val(currentCode);
            }

            $search.on('input', function () {
                renderOptions(this.value.trim().toLowerCase());
                // Re-apply the selection if still visible
                if ($select.find('option[value="' + currentCode + '"]').length) {
                    $select.val(currentCode);
                }
            });

            $select.on('change', function () {
                var code = this.value;
                if (!code) return;
                var lang = registry.find(function (l) { return l.code === code; });
                if (lang) {
                    currentCode = code;
                    $('#lang_name').val(lang.name);
                    $('#lang_code').val(lang.code);
                    $('#lang_ai_name').val(lang.ai_name);
                }
            });
        },

        // ── Flag Image Picker ─────────────────────────────────────────────

        bindFlagPicker: function () {
            var frame;

            $('#mml-select-flag').on('click', function (e) {
                e.preventDefault();

                if (frame) {
                    frame.open();
                    return;
                }

                frame = wp.media({
                    title: 'Select Flag Image',
                    button: { text: 'Use this image' },
                    multiple: false,
                    library: { type: 'image' }
                });

                frame.on('select', function () {
                    var attachment = frame.state().get('selection').first().toJSON();
                    $('#flag_id').val(attachment.id);
                    $('#mml-flag-preview').html('<img src="' + attachment.url + '" style="height:28px;border-radius:2px;border:1px solid #ddd;">');
                    $('#mml-remove-flag').show();
                });

                frame.open();
            });

            $('#mml-remove-flag').on('click', function (e) {
                e.preventDefault();
                $('#flag_id').val(0);
                $('#mml-flag-preview').html('');
                $(this).hide();
            });
        },

        // ── Delete language link confirmation ─────────────────────────────

        bindDeleteConfirm: function () {
            $(document).on('click', '.mml-delete-link', function (e) {
                if (!window.confirm(mmlAdmin.confirmDelete)) {
                    e.preventDefault();
                }
            });
        },

        // ── Add String Key Modal ──────────────────────────────────────────

        bindAddStringModal: function () {
            var $modal = $('#mml-add-key-modal');

            $('#mml-add-string-btn').on('click', function () {
                $modal.show();
                $('#mml-new-key').val('').focus();
                $('#mml-add-key-error').hide();
            });

            $('#mml-cancel-modal').on('click', function () {
                $modal.hide();
            });

            // Close on overlay click
            $modal.on('click', function (e) {
                if ($(e.target).hasClass('mml-modal-overlay')) {
                    $modal.hide();
                }
            });

            $('#mml-confirm-add-key').on('click', function () {
                var key = $('#mml-new-key').val().trim();
                if (!key) {
                    MMLAdmin.showModalError('Key cannot be empty.');
                    return;
                }
                if (!/^[a-z0-9_]+$/.test(key)) {
                    MMLAdmin.showModalError('Only lowercase letters, numbers, and underscores allowed.');
                    return;
                }

                $.post(mmlAdmin.ajaxurl, {
                    action: 'mml_add_string',
                    nonce:  mmlAdmin.nonce,
                    key:    key
                }, function (response) {
                    if (response.success) {
                        MMLAdmin.prependStringRow(response.data.id, response.data.key);
                        $modal.hide();
                        // Remove "no strings" placeholder if present
                        $('#mml-no-strings').remove();
                    } else {
                        MMLAdmin.showModalError(response.data.message || 'Unknown error.');
                    }
                }).fail(function () {
                    MMLAdmin.showModalError('Server error. Please try again.');
                });
            });
        },

        showModalError: function (msg) {
            $('#mml-add-key-error').text(msg).show();
        },

        prependStringRow: function (id, key) {
            var template = $('#mml-row-template').html();
            var html = template.replace(/{{id}}/g, id).replace(/{{key}}/g, key);
            $('#mml-strings-tbody').prepend(html);
        },

        // ── Delete String Row ─────────────────────────────────────────────

        bindDeleteString: function () {
            $(document).on('click', '.mml-delete-string-btn', function () {
                if (!window.confirm(mmlAdmin.confirmDeleteStr)) {
                    return;
                }
                var $btn = $(this);
                var id   = $btn.data('id');
                var $row = $btn.closest('tr');

                $.post(mmlAdmin.ajaxurl, {
                    action: 'mml_delete_string',
                    nonce:  mmlAdmin.nonce,
                    id:     id
                }, function (response) {
                    if (response.success) {
                        $row.fadeOut(250, function () { $(this).remove(); });
                    } else {
                        alert(response.data.message || 'Delete failed.');
                    }
                });
            });
        },

        // ── Clone Post / Term Buttons ─────────────────────────────────────

        bindCloneButtons: function () {
            $(document).on('click', '.mml-clone-btn', function (e) {
                e.preventDefault();
                if (!window.confirm(mmlAdmin.confirmDelete)) {
                    return;
                }

                var $btn     = $(this);
                var type     = $btn.data('type');
                var id       = $btn.data('id');
                var lang     = $btn.data('lang');
                var taxonomy = $btn.data('taxonomy') || '';

                $btn.text('…').css('pointer-events', 'none');

                $.post(mmlAdmin.ajaxurl, {
                    action:      'mml_clone_object',
                    nonce:       mmlAdmin.nonce,
                    object_type: type,
                    object_id:   id,
                    lang:        lang,
                    taxonomy:    taxonomy
                }, function (response) {
                    if (response.success && response.data.edit_url) {
                        window.location.href = response.data.edit_url;
                    } else {
                        alert(response.data.message || mmlAdmin.i18n.cloneFailed);
                        $btn.text('+').css('pointer-events', '');
                    }
                }).fail(function () {
                    alert(mmlAdmin.i18n.serverError);
                    $btn.text('+').css('pointer-events', '');
                });
            });
        },

        // ── Smart Scan & Refactor ─────────────────────────────────────────

        _scanRunning:       false,
        _scanPhase:         'options',  // 'options' | 'gettext' | 'ux_blocks'
        _scanOffset:        0,
        _optionsTotal:      0,
        _gettextTotal:      0,
        _uxBlocksTotal:     0,
        _includeUxBlocks:   false,
        _includeGettext:    false,
        _includeOrphaned:   false,
        _scanResults:       [],   // accumulated items
        _orphanedResults:   [],   // accumulated orphaned items
        _batchSize:         8,

        bindScanner: function () {
            if (!$('#mml-start-scan').length) { return; }

            $('#mml-start-scan').on('click', $.proxy(this.startScan, this));
            $('#mml-stop-scan').on('click',  $.proxy(this.stopScan,  this));

            // Select-all checkbox
            $(document).on('change', '#mml-select-all', function () {
                var checked = $(this).is(':checked');
                $('.mml-result-check').prop('checked', checked);
                MMLAdmin.updateSelectedCount();
            });

            // Individual checkbox → update counter
            $(document).on('change', '.mml-result-check', function () {
                MMLAdmin.updateSelectedCount();
            });

            // Process buttons
            $(document).on('click', '#mml-process-btn, #mml-process-btn-bottom',
                $.proxy(this.processApproved, this));

            // Orphaned select-all
            $(document).on('change', '#mml-orphaned-select-all', function () {
                var checked = $(this).is(':checked');
                $('.mml-orphaned-check').prop('checked', checked);
                MMLAdmin.updateOrphanedCount();
            });

            // Orphaned individual checkbox
            $(document).on('change', '.mml-orphaned-check', function () {
                MMLAdmin.updateOrphanedCount();
            });

            // Recover button
            $(document).on('click', '#mml-orphaned-recover-btn',
                $.proxy(this.recoverOrphaned, this));

            // Session actions
            this.bindSessionActions();
        },

        startScan: function () {
            var self = this;
            self._scanRunning     = true;
            self._scanPhase       = 'options';
            self._scanOffset      = 0;
            self._optionsTotal    = 0;
            self._gettextTotal    = 0;
            self._uxBlocksTotal   = 0;
            self._includeUxBlocks = $('#mml-target-ux-blocks').is(':checked');
            self._includeGettext  = $('#mml-target-gettext').is(':checked');
            self._includeOrphaned = $('#mml-target-orphaned').is(':checked');
            self._scanResults     = [];
            self._orphanedResults = [];

            var parts      = [ 'options' ];
            if (self._includeGettext)  { parts.push('gettext'); }
            if (self._includeUxBlocks) { parts.push('ux_blocks'); }
            var scanTarget = parts.join(',');

            $('#mml-start-scan').prop('disabled', true);
            $('#mml-stop-scan').show().prop('disabled', false);
            $('#mml-scan-progress-wrap').show();
            $('#mml-scan-bar').css('width', '0%');
            $('#mml-scan-status').text(mmlAdmin.i18n.scanCounting);
            $('#mml-results-tbody').empty();
            $('#mml-select-all').prop('checked', false);
            $('#mml-results-panel').hide();
            $('#mml-orphaned-tbody').empty();
            $('#mml-orphaned-panel').hide();
            $('#mml-orphaned-result').hide();
            MMLAdmin.updateSelectedCount();

            $.post(mmlAdmin.ajaxurl, {
                action:      'mml_scan_count',
                nonce:       mmlAdmin.nonce,
                scan_target: scanTarget
            }, function (res) {
                if (!res.success) {
                    self.scanDone(mmlAdmin.i18n.scanCountError);
                    return;
                }
                self._optionsTotal  = parseInt(res.data.options_total,   10) || 0;
                self._gettextTotal  = parseInt(res.data.gettext_total,   10) || 0;
                self._uxBlocksTotal = parseInt(res.data.ux_blocks_total, 10) || 0;
                var total = self._optionsTotal + self._gettextTotal + self._uxBlocksTotal;
                if (total === 0) {
                    // No system strings to scan — still run orphaned scan if enabled.
                    if (self._includeOrphaned) {
                        $('#mml-scan-status').text(mmlAdmin.i18n.scanOrphaned);
                        self.runOrphanedScan(0);
                    } else {
                        self.scanDone(mmlAdmin.i18n.scanNoData);
                    }
                    return;
                }
                // Start with options phase (skip if 0)
                if (self._optionsTotal > 0) {
                    self._scanPhase  = 'options';
                    self._scanOffset = 0;
                } else if (self._gettextTotal > 0) {
                    self._scanPhase  = 'gettext';
                    self._scanOffset = 0;
                } else {
                    self._scanPhase  = 'ux_blocks';
                    self._scanOffset = 0;
                }
                self.runScanBatch();
            }).fail(function () { self.scanDone(mmlAdmin.i18n.serverError); });
        },

        runScanBatch: function () {
            var self = this;
            if (!self._scanRunning) { self.scanDone(mmlAdmin.i18n.scanStopped); return; }

            var phase = self._scanPhase;

            $.post(mmlAdmin.ajaxurl, {
                action:      'mml_scan_batch',
                nonce:       mmlAdmin.nonce,
                offset:      self._scanOffset,
                limit:       self._batchSize,
                scan_target: phase
            }, function (res) {
                if (!res.success) { self.scanDone(mmlAdmin.i18n.scanError); return; }

                var data = res.data;

                if (data.items && data.items.length) {
                    $.each(data.items, function (i, item) {
                        self._scanResults.push(item);
                        self.appendResultRow(item, self._scanResults.length - 1);
                    });
                }

                // Update progress — combined across all active phases
                var optionsTotal  = self._optionsTotal;
                var gettextTotal  = self._includeGettext  ? self._gettextTotal  : 0;
                var uxTotal       = self._includeUxBlocks ? self._uxBlocksTotal : 0;
                var grandTotal    = (optionsTotal + gettextTotal + uxTotal) || 1;
                var optDone       = (phase === 'options')   ? (self._scanOffset + (data.batch_count || 0)) : optionsTotal;
                var gettextDone   = (phase === 'gettext')   ? (self._scanOffset + (data.batch_count || 0)) : (self._includeGettext && phase !== 'options' ? gettextTotal : 0);
                var uxDone        = (phase === 'ux_blocks') ? (self._scanOffset + (data.batch_count || 0)) : 0;
                var combinedDone  = optDone + gettextDone + uxDone;
                var pct  = Math.min(100, Math.round(combinedDone / grandTotal * 100));
                $('#mml-scan-bar').css('width', pct + '%');

                var phaseLabel = (phase === 'ux_blocks') ? 'UX Block' : (phase === 'gettext') ? 'gettext string' : 'option key';
                $('#mml-scan-status').text(
                    'Đã quét ' + Math.min(combinedDone, grandTotal) + ' / ' + grandTotal +
                    ' ' + phaseLabel + ' — tìm thấy ' + self._scanResults.length + ' chuỗi'
                );

                if (data.done) {
                    // Options → gettext phase (if enabled and has results)
                    if (phase === 'options' && self._includeGettext && self._gettextTotal > 0) {
                        self._scanPhase  = 'gettext';
                        self._scanOffset = 0;
                        $('#mml-scan-status').text(mmlAdmin.i18n.scanWcGettext);
                        self.runScanBatch();
                        return;
                    }
                    // Options/gettext → ux_blocks phase (if enabled)
                    if ((phase === 'options' || phase === 'gettext') && self._includeUxBlocks && self._uxBlocksTotal > 0) {
                        self._scanPhase  = 'ux_blocks';
                        self._scanOffset = 0;
                        $('#mml-scan-status').text(mmlAdmin.i18n.scanUxBlocks);
                        self.runScanBatch();
                        return;
                    }
                    // All phases done
                    $('#mml-scan-bar').css('width', '100%');
                    if (self._scanResults.length > 0) {
                        $('#mml-results-panel').show();
                        MMLAdmin.updateSelectedCount();
                    }
                    if (self._includeOrphaned) {
                        $('#mml-scan-status').text(mmlAdmin.i18n.scanOrphaned);
                        self.runOrphanedScan(0);
                    } else {
                        self.scanDone('Hoàn tất. Tìm thấy ' + self._scanResults.length + ' chuỗi.');
                    }
                } else {
                    self._scanOffset = data.next_offset;
                    self.runScanBatch();
                }
            }).fail(function () { self.scanDone(mmlAdmin.i18n.serverError); });
        },

        appendResultRow: function (item, index) {
            var sourceBadge;
            if (item.source_type === 'ux_block') {
                sourceBadge = '<code class="mml-ux-block-badge" title="UX Block: ' +
                    $('<span>').text(item.post_title || '').html() +
                    '">UX Block #' + (item.post_id || '') + '</code>';
            } else if (item.source_type === 'gettext') {
                sourceBadge = '<code class="mml-gettext-badge">' +
                    $('<span>').text(item.option_name || '').html() + '</code>';
            } else {
                sourceBadge = '<code class="mml-option-badge">' +
                    $('<span>').text(item.option_name || '').html() + '</code>';
            }
            var row = '<tr id="mml-row-' + index + '">' +
                '<td><input type="checkbox" class="mml-result-check" data-index="' + index + '" checked></td>' +
                '<td class="mml-col-original">' + $('<span>').text(item.text).html() + '</td>' +
                '<td><input type="text" class="mml-key-input" data-index="' + index + '" value="' + $('<span>').text(item.key).html() + '"></td>' +
                '<td>' + sourceBadge + '</td>' +
                '</tr>';
            $('#mml-results-tbody').append(row);
        },

        stopScan: function () {
            this._scanRunning = false;
            $('#mml-stop-scan').hide().prop('disabled', true);
        },

        scanDone: function (msg) {
            this._scanRunning = false;
            $('#mml-start-scan').prop('disabled', false);
            $('#mml-stop-scan').hide().prop('disabled', true);
            $('#mml-scan-status').text(msg);
        },

        updateSelectedCount: function () {
            var count = $('.mml-result-check:checked').length;
            $('#mml-selected-num').text(count);
            var disabled = (count === 0);
            $('#mml-process-btn, #mml-process-btn-bottom').prop('disabled', disabled);
        },

        processApproved: function () {
            var self = this;
            var items = [];

            $('.mml-result-check:checked').each(function () {
                var index = $(this).data('index');
                var item  = self._scanResults[index];
                var key   = $('#mml-row-' + index + ' .mml-key-input').val().trim();
                if (item && key) {
                    items.push({
                        text:        item.text,
                        key:         key,
                        post_id:     item.post_id     || 0,
                        source_type: item.source_type || 'options'
                    });
                }
            });

            if (!items.length) { return; }

            var confirmMsg = 'Xử lý ' + items.length + ' chuỗi đã chọn?\nCác chuỗi sẽ được đăng ký trong My Strings và tự động dịch. Tên trên frontend sẽ được đổi thông qua gettext filter. Bạn có thể hoàn tác từ phần Restore Sessions.';
            if (!window.confirm(confirmMsg)) { return; }

            $('#mml-process-btn, #mml-process-btn-bottom').prop('disabled', true).text(mmlAdmin.i18n.processing);
            $('#mml-process-progress').show();
            $('#mml-process-result').hide();

            $.post(mmlAdmin.ajaxurl, {
                action: 'mml_scan_process',
                nonce:  mmlAdmin.nonce,
                items:  JSON.stringify(items)
            }, function (res) {
                $('#mml-process-btn, #mml-process-btn-bottom').text(mmlAdmin.i18n.processSelected);
                $('#mml-process-progress').hide();
                if (res.success) {
                    $('#mml-process-result').removeClass('notice-error').addClass('notice-success')
                        .html('<p>' + (res.data.message || 'Hoàn tất!') + '</p>').show();
                    // Reload session list
                    self.reloadSessions();
                    // Remove processed rows from table
                    $('.mml-result-check:checked').closest('tr').remove();
                    MMLAdmin.updateSelectedCount();
                } else {
                    $('#mml-process-result').removeClass('notice-success').addClass('notice-error')
                        .html('<p>' + (res.data.message || 'Có lỗi xảy ra.') + '</p>').show();
                    $('#mml-process-btn, #mml-process-btn-bottom').prop('disabled', false);
                }
            }).fail(function () {
                $('#mml-process-btn, #mml-process-btn-bottom').text(mmlAdmin.i18n.processSelected).prop('disabled', false);
                $('#mml-process-progress').hide();
                $('#mml-process-result').removeClass('notice-success').addClass('notice-error')
                    .html('<p>' + mmlAdmin.i18n.serverError + '</p>').show();
            });
        },

        bindSessionActions: function () {
            var self = this;

            // ── Global restore all ─────────────────────────────────────────
            $(document).on('click', '#mml-global-restore-btn', function () {
                if (!window.confirm(
                    'Khôi phục TẤT CẢ bài viết về nội dung gốc?\n\n' +
                    'Thao tác này sẽ:\n' +
                    '• Ghi đè post_content bằng bản sao lưu gốc\n' +
                    '• GIỮ NGUYÊN tất cả chuỗi đã đăng ký trong wp_my_strings\n' +
                    '• Xóa toàn bộ bảng backup sau khi hoàn tất\n\n' +
                    'Tiếp tục?'
                )) { return; }

                var $btn = $(this);
                $btn.prop('disabled', true).find('.dashicons').addClass('dashicons-update-alt').removeClass('dashicons-undo');

                $.post(mmlAdmin.ajaxurl, {
                    action: 'mml_global_restore',
                    nonce:  mmlAdmin.nonce
                }, function (res) {
                    $btn.prop('disabled', false).find('.dashicons').addClass('dashicons-undo').removeClass('dashicons-update-alt');
                    if (res.success) {
                        $('#mml-sessions-table').hide();
                        $('#mml-no-sessions').show();
                        $('#mml-restore-result').removeClass('notice-error').addClass('notice-success')
                            .html('<p>' + (res.data.message || 'Đã khôi phục.') + '</p>').show();
                    } else {
                        $('#mml-restore-result').removeClass('notice-success').addClass('notice-error')
                            .html('<p>' + (res.data || mmlAdmin.i18n.restoreError) + '</p>').show();
                    }
                }).fail(function () {
                    $btn.prop('disabled', false);
                    $('#mml-restore-result').removeClass('notice-success').addClass('notice-error')
                        .html('<p>' + mmlAdmin.i18n.serverError + '</p>').show();
                });
            });

            // ── Preview toggle ─────────────────────────────────────────────
            $(document).on('click', '.mml-preview-btn', function () {
                var sid        = $(this).data('sid');
                var $panel     = $('.mml-preview-panel[data-sid="' + sid + '"]');
                var $btn       = $(this);

                // Toggle off if already open
                if ($panel.is(':visible')) {
                    $panel.slideUp(200);
                    $btn.find('.dashicons').removeClass('dashicons-hidden').addClass('dashicons-visibility');
                    return;
                }

                // Already loaded — just show
                if ($panel.data('loaded')) {
                    $panel.slideDown(200);
                    $btn.find('.dashicons').removeClass('dashicons-visibility').addClass('dashicons-hidden');
                    return;
                }

                $btn.prop('disabled', true);

                $.post(mmlAdmin.ajaxurl, {
                    action:     'mml_scan_session_preview',
                    nonce:      mmlAdmin.nonce,
                    session_id: sid
                }, function (res) {
                    $btn.prop('disabled', false);
                    if (!res.success) {
                        $panel.html('<p style="color:#d63638;padding:6px 0;">' + mmlAdmin.i18n.previewError + '</p>').slideDown(200);
                        $panel.data('loaded', true);
                        return;
                    }

                    var d       = res.data;
                    var html    = '<div class="mml-preview-content">';

                    // Orphaned keys notice
                    if (d.orphaned_keys && d.orphaned_keys.length) {
                        html += '<div class="notice notice-warning inline" style="margin:0 0 10px;padding:6px 12px;">' +
                            '<strong>Dữ liệu gốc khả dụng, nhưng chuỗi dịch đã bị xóa:</strong> ' +
                            d.orphaned_keys.map(function(k) { return '<code>' + $('<span>').text(k).html() + '</code>'; }).join(', ') +
                            '</div>';
                    }

                    // UX Block posts
                    if (d.posts && d.posts.length) {
                        $.each(d.posts, function (i, p) {
                            html += '<div class="mml-preview-post">';
                            html += '<strong><code class="mml-type-badge">' + $('<span>').text(p.post_type).html() + '</code> ' + $('<span>').text(p.post_title).html() + '</strong>';
                            if (p.keys && p.keys.length) {
                                html += '<ul class="mml-preview-keys">';
                                $.each(p.keys, function (j, k) {
                                    var orig = (d.key_texts && d.key_texts[k]) ? d.key_texts[k] : '';
                                    html += '<li><code>' + $('<span>').text(k).html() + '</code>';
                                    if (orig) { html += ' &rarr; <em>' + $('<span>').text(orig).html() + '</em>'; }
                                    html += '</li>';
                                });
                                html += '</ul>';
                            }
                            html += '</div>';
                        });
                    }

                    // Options/gettext keys
                    if (d.options_keys && d.options_keys.length) {
                        html += '<div class="mml-preview-post">';
                        html += '<strong>Options / gettext keys</strong>';
                        html += '<ul class="mml-preview-keys">';
                        $.each(d.options_keys, function (j, k) {
                            var orig = (d.key_texts && d.key_texts[k]) ? d.key_texts[k] : '';
                            html += '<li><code>' + $('<span>').text(k).html() + '</code>';
                            if (orig) { html += ' &rarr; <em>' + $('<span>').text(orig).html() + '</em>'; }
                            html += '</li>';
                        });
                        html += '</ul></div>';
                    }

                    html += '</div>';

                    $panel.html(html).data('loaded', true).slideDown(200);
                    $btn.find('.dashicons').removeClass('dashicons-visibility').addClass('dashicons-hidden');
                }).fail(function () {
                    $btn.prop('disabled', false);
                    $panel.html('<p style="color:#d63638;padding:6px 0;">' + mmlAdmin.i18n.serverError + '</p>').slideDown(200);
                    $panel.data('loaded', true);
                });
            });

            // ── Restore session ────────────────────────────────────────────
            $(document).on('click', '.mml-restore-btn', function () {
                var sid = $(this).data('sid');
                if (!window.confirm(mmlAdmin.confirmRestore)) { return; }
                var $btn = $(this);
                $btn.prop('disabled', true).html('<span class="dashicons dashicons-update-alt"></span> ' + mmlAdmin.i18n.restoring);
                $.post(mmlAdmin.ajaxurl, {
                    action:     'mml_scan_restore',
                    nonce:      mmlAdmin.nonce,
                    session_id: sid
                }, function (res) {
                    if (res.success) {
                        $btn.closest('tr.mml-session-row').fadeOut(300, function () {
                            $(this).remove();
                            if (!$('.mml-session-row').length) {
                                $('#mml-sessions-table').hide();
                                $('#mml-no-sessions').show();
                            }
                        });
                        $('#mml-restore-result').removeClass('notice-error').addClass('notice-success')
                            .html('<p>' + (res.data.message || 'Đã khôi phục.') + '</p>').show();
                    } else {
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-backup"></span> ' + mmlAdmin.i18n.restore);
                        $('#mml-restore-result').removeClass('notice-success').addClass('notice-error')
                            .html('<p>' + (res.data.message || mmlAdmin.i18n.restoreError) + '</p>').show();
                    }
                }).fail(function () {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-backup"></span> ' + mmlAdmin.i18n.restore);
                    $('#mml-restore-result').removeClass('notice-success').addClass('notice-error')
                        .html('<p>' + mmlAdmin.i18n.serverError + '</p>').show();
                });
            });

            // ── Discard session ────────────────────────────────────────────
            $(document).on('click', '.mml-discard-btn', function () {
                var sid = $(this).data('sid');
                if (!window.confirm(mmlAdmin.confirmDiscard)) { return; }
                var $btn = $(this);
                $btn.prop('disabled', true).html('<span class="dashicons dashicons-update-alt"></span> ' + mmlAdmin.i18n.discarding);
                $.post(mmlAdmin.ajaxurl, {
                    action:     'mml_scan_delete_session',
                    nonce:      mmlAdmin.nonce,
                    session_id: sid
                }, function (res) {
                    if (res.success) {
                        $btn.closest('tr.mml-session-row').fadeOut(300, function () {
                            $(this).remove();
                            if (!$('.mml-session-row').length) {
                                $('#mml-sessions-table').hide();
                                $('#mml-no-sessions').show();
                            }
                        });
                    } else {
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> ' + mmlAdmin.i18n.discard);
                        alert(res.data.message || 'Xóa thất bại.');
                    }
                }).fail(function () {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> ' + mmlAdmin.i18n.discard);
                });
            });
        },

        // ── Manual Add String (Feature B) ────────────────────────────────

        bindManualAddString: function () {
            if (!$('#mml-manual-add-btn').length) { return; }

            $('#mml-manual-add-btn').on('click', function () {
                var text = $('#mml-manual-text').val().trim();
                var key  = $('#mml-manual-key').val().trim().toLowerCase().replace(/[^a-z0-9_]/g, '');

                if (!text) {
                    $('#mml-manual-add-status').text('Vui lòng nhập văn bản.').css('color', '#d63638');
                    return;
                }

                var $btn = $('#mml-manual-add-btn');
                $btn.prop('disabled', true).text(mmlAdmin.i18n.processing);
                $('#mml-manual-add-status').text('').css('color', '');

                $.post(mmlAdmin.ajaxurl, {
                    action: 'mml_scan_add_manual_string',
                    nonce:  mmlAdmin.nonce,
                    text:   text,
                    key:    key
                }, function (res) {
                    $btn.prop('disabled', false).text('Add & Translate');
                    if (res.success) {
                        $('#mml-manual-text').val('');
                        $('#mml-manual-key').val('');
                        $('#mml-manual-add-status')
                            .text(res.data.message || 'Đã thêm thành công.')
                            .css('color', '#00a32a');
                    } else {
                        $('#mml-manual-add-status')
                            .text(res.data || 'Có lỗi xảy ra.')
                            .css('color', '#d63638');
                    }
                }).fail(function () {
                    $btn.prop('disabled', false).text('Add & Translate');
                    $('#mml-manual-add-status').text(mmlAdmin.i18n.serverError).css('color', '#d63638');
                });
            });
        },

        // ── Auto-Translate Missing Strings ────────────────────────────────

        bindAutoTranslate: function () {
            if (!$('#mml-autotrans-btn').length) { return; }

            $('#mml-autotrans-btn').on('click', $.proxy(this._runAutoTranslate, this));
        },

        _runAutoTranslate: function () {
            var self        = this;
            var $btn        = $('#mml-autotrans-btn');
            var $langSel    = $('#mml-autotrans-lang');
            var langCode    = $langSel.val();
            var langName    = $langSel.find('option:selected').data('name') || langCode;

            if (!langCode) {
                alert(mmlAdmin.i18n.autoTranslateSelect);
                return;
            }

            $btn.prop('disabled', true)
                .html('<span class="dashicons dashicons-update-alt" style="margin-top:3px;"></span> ' + mmlAdmin.i18n.autoTranslating);
            $langSel.prop('disabled', true);
            $('#mml-autotrans-progress-wrap').show();
            $('#mml-autotrans-bar').css('width', '0%');
            $('#mml-autotrans-status').text(mmlAdmin.i18n.autoTranslating);
            $('#mml-autotrans-result').hide();

            var totalTranslated = 0;
            var totalMissing    = 0; // set on first response

            function runBatch() {
                $.post(mmlAdmin.ajaxurl, {
                    action:    'mml_auto_translate_strings',
                    nonce:     mmlAdmin.nonce,
                    lang_code: langCode
                }, function (res) {
                    if (!res.success) {
                        self._autoTranslateDone($btn, $langSel, false,
                            res.data && res.data.message ? res.data.message : mmlAdmin.i18n.serverError);
                        return;
                    }

                    var d = res.data;

                    // Capture total_missing on first call.
                    if (totalMissing === 0 && d.total_missing > 0) {
                        totalMissing = d.total_missing;
                    }

                    totalTranslated += d.translated || 0;

                    // Update progress bar.
                    if (totalMissing > 0) {
                        var pct = Math.min(100, Math.round(totalTranslated / totalMissing * 100));
                        $('#mml-autotrans-bar').css('width', pct + '%');
                        $('#mml-autotrans-status').text(totalTranslated + ' / ' + totalMissing);
                    }

                    if (d.done) {
                        $('#mml-autotrans-bar').css('width', '100%');
                        if (totalTranslated === 0) {
                            self._autoTranslateDone($btn, $langSel, true,
                                mmlAdmin.i18n.autoTranslateNone, true);
                        } else {
                            var msg = mmlAdmin.i18n.autoTranslateDone
                                .replace('%d', totalTranslated)
                                .replace('%s', langName);
                            self._autoTranslateDone($btn, $langSel, true, msg, false);
                            // Reload page so textareas reflect new values.
                            setTimeout(function () { window.location.reload(); }, 1800);
                        }
                    } else {
                        // Schedule next batch (small delay to avoid hammering server).
                        setTimeout(runBatch, 300);
                    }
                }).fail(function () {
                    self._autoTranslateDone($btn, $langSel, false, mmlAdmin.i18n.serverError);
                });
            }

            runBatch();
        },

        _autoTranslateDone: function ($btn, $langSel, success, message, isInfo) {
            $btn.prop('disabled', false)
                .html('<span class="dashicons dashicons-translation" style="margin-top:3px;"></span> ' + mmlAdmin.i18n.autoTranslateBtn);
            $langSel.prop('disabled', false);

            var cssClass = isInfo ? 'notice-info' : (success ? 'notice-success' : 'notice-error');
            $('#mml-autotrans-result')
                .removeClass('notice-info notice-success notice-error')
                .addClass('notice ' + cssClass)
                .html('<p>' + message + '</p>')
                .show();
        },

        reloadSessions: function () {
            $.post(mmlAdmin.ajaxurl, {
                action: 'mml_scan_get_sessions',
                nonce:  mmlAdmin.nonce
            }, function (res) {
                if (!res.success) { return; }
                var sessions = res.data || [];
                var $tbody   = $('#mml-sessions-list');
                $tbody.empty();

                if (!sessions.length) {
                    $('#mml-sessions-table').hide();
                    $('#mml-no-sessions').show();
                    return;
                }

                $('#mml-no-sessions').hide();
                $('#mml-sessions-table').show();

                $.each(sessions, function (i, s) {
                    var count = (s.key_count > 0) ? s.key_count + ' keys' : s.post_count + ' entries';
                    var contentHtml = '';

                    if (s.posts && s.posts.length) {
                        $.each(s.posts, function (j, p) {
                            contentHtml += '<div class="mml-session-post-line">' +
                                '<code class="mml-type-badge">' + $('<span>').text(p.post_type).html() + '</code> ' +
                                $('<span>').text(p.post_title).html() +
                                '</div>';
                        });
                    } else {
                        contentHtml = '<em class="mml-no-posts-label">Options / gettext strings only</em>';
                    }

                    var row = '<tr class="mml-session-row" data-sid="' + s.session_id + '">' +
                        '<td><strong>' + $('<span>').text(s.created_at).html() + '</strong><br>' +
                        '<span class="mml-session-meta">' + $('<span>').text(count).html() + '</span></td>' +
                        '<td>' +
                            '<div class="mml-session-content-summary">' + contentHtml + '</div>' +
                            '<div class="mml-preview-panel" data-sid="' + s.session_id + '" style="display:none;"></div>' +
                        '</td>' +
                        '<td class="mml-session-actions">' +
                            '<button type="button" class="button mml-preview-btn" data-sid="' + s.session_id + '">' +
                                '<span class="dashicons dashicons-visibility"></span> Preview</button> ' +
                            '<button type="button" class="button mml-restore-btn" data-sid="' + s.session_id + '">' +
                                '<span class="dashicons dashicons-backup"></span> Restore</button> ' +
                            '<button type="button" class="button mml-discard-btn" data-sid="' + s.session_id + '">' +
                                '<span class="dashicons dashicons-trash"></span> Discard</button>' +
                        '</td>' +
                        '</tr>';
                    $tbody.append(row);
                });
            });
        },

        // ── Orphaned Shortcode Scanner ────────────────────────────────────
        //
        // Runs after the main scan phases complete (when #mml-target-orphaned is checked).
        // Calls mml_scan_orphaned in batches; results appear in the dedicated
        // "Chuỗi mồ côi" panel with individual or bulk Recover buttons.

        runOrphanedScan: function (offset) {
            var self = this;

            $.post(mmlAdmin.ajaxurl, {
                action: 'mml_scan_orphaned',
                nonce:  mmlAdmin.nonce,
                offset: offset,
                limit:  10
            }, function (res) {
                if (!res.success) {
                    self.scanDone('Hoàn tất. Quét chuỗi mồ côi thất bại.');
                    return;
                }
                var data = res.data;

                if (data.items && data.items.length) {
                    $.each(data.items, function (i, item) {
                        self._orphanedResults.push(item);
                        self.appendOrphanedRow(item, self._orphanedResults.length - 1);
                    });
                    $('#mml-orphaned-num').text(self._orphanedResults.length);
                    MMLAdmin.updateOrphanedCount();
                }

                if (!data.done) {
                    self.runOrphanedScan(data.next_offset);
                } else {
                    var msg = 'Hoàn tất. Tìm thấy ' + self._scanResults.length + ' chuỗi hệ thống';
                    if (self._orphanedResults.length > 0) {
                        msg += ' và ' + self._orphanedResults.length + ' chuỗi mồ côi.';
                        $('#mml-orphaned-panel').show();
                    } else {
                        msg += '. Không có chuỗi mồ côi.';
                    }
                    self.scanDone(msg);
                }
            }).fail(function () {
                self.scanDone('Hoàn tất. Lỗi server khi quét chuỗi mồ côi.');
            });
        },

        appendOrphanedRow: function (item, index) {
            var sourceBadge = '<code class="mml-option-badge">' +
                $('<span>').text(item.option_name || '').html() + '</code>';

            var textDisplay = item.text && item.text !== item.key
                ? $('<span>').text(item.text).html()
                : '<em style="color:#999;">Không có văn bản gốc</em>';

            var row = '<tr id="mml-orphaned-row-' + index + '">' +
                '<td><input type="checkbox" class="mml-orphaned-check" data-index="' + index + '" checked></td>' +
                '<td><code>' + $('<span>').text(item.key).html() + '</code></td>' +
                '<td class="mml-col-original">' + textDisplay + '</td>' +
                '<td>' + sourceBadge + '</td>' +
                '</tr>';
            $('#mml-orphaned-tbody').append(row);
        },

        updateOrphanedCount: function () {
            var count = $('.mml-orphaned-check:checked').length;
            $('#mml-orphaned-recover-btn').prop('disabled', count === 0);
        },

        recoverOrphaned: function () {
            var self = this;
            var items = [];

            $('.mml-orphaned-check:checked').each(function () {
                var index = parseInt($(this).data('index'), 10);
                var item  = self._orphanedResults[index];
                if (item) {
                    items.push({
                        text:        item.text  || item.key,
                        key:         item.key,
                        post_id:     0,
                        source_type: 'orphaned'
                    });
                }
            });

            if (!items.length) { return; }

            var confirmMsg = 'Khôi phục ' + items.length + ' chuỗi mồ côi?\n' +
                'Các key sẽ được đăng ký lại vào wp_my_strings và tự động dịch.';
            if (!window.confirm(confirmMsg)) { return; }

            $('#mml-orphaned-recover-btn').prop('disabled', true).text('Đang khôi phục…');
            $('#mml-orphaned-progress').show();
            $('#mml-orphaned-result').hide();

            $.post(mmlAdmin.ajaxurl, {
                action: 'mml_scan_process',
                nonce:  mmlAdmin.nonce,
                items:  JSON.stringify(items)
            }, function (res) {
                $('#mml-orphaned-recover-btn').text('↩ Khôi phục Đã Chọn');
                $('#mml-orphaned-progress').hide();
                if (res.success) {
                    $('#mml-orphaned-result')
                        .removeClass('notice-error').addClass('notice-success')
                        .html('<p>' + (res.data.message || 'Đã khôi phục thành công!') + '</p>')
                        .show();
                    // Remove recovered rows
                    $('.mml-orphaned-check:checked').closest('tr').remove();
                    MMLAdmin.updateOrphanedCount();
                    self.reloadSessions();
                } else {
                    $('#mml-orphaned-result')
                        .removeClass('notice-success').addClass('notice-error')
                        .html('<p>' + (res.data || 'Có lỗi xảy ra.') + '</p>')
                        .show();
                    $('#mml-orphaned-recover-btn').prop('disabled', false);
                }
            }).fail(function () {
                $('#mml-orphaned-recover-btn').text('↩ Khôi phục Đã Chọn').prop('disabled', false);
                $('#mml-orphaned-progress').hide();
                $('#mml-orphaned-result')
                    .removeClass('notice-success').addClass('notice-error')
                    .html('<p>Lỗi server.</p>').show();
            });
        },

    };

    $(document).ready(function () {
        MMLAdmin.init();
        MMLAdmin.bindScanner();
    });

}(jQuery));
