/* global mmlAdmin */
(function ($) {
    'use strict';

    var MMLAdmin = {

        init: function () {
            this.bindFlagPicker();
            this.bindDeleteConfirm();
            this.bindAddStringModal();
            this.bindDeleteString();
            this.bindCloneButtons();
            this.bindManualAddString();
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
                        alert(response.data.message || 'Clone failed.');
                        $btn.text('+').css('pointer-events', '');
                    }
                }).fail(function () {
                    alert('Server error.');
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
        _scanResults:       [],   // accumulated items
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
            self._scanResults     = [];

            var parts      = [ 'options' ];
            if (self._includeGettext)  { parts.push('gettext'); }
            if (self._includeUxBlocks) { parts.push('ux_blocks'); }
            var scanTarget = parts.join(',');

            $('#mml-start-scan').prop('disabled', true);
            $('#mml-stop-scan').show().prop('disabled', false);
            $('#mml-scan-progress-wrap').show();
            $('#mml-scan-bar').css('width', '0%');
            $('#mml-scan-status').text('Đang đếm các tùy chọn hệ thống…');
            $('#mml-results-tbody').empty();
            $('#mml-select-all').prop('checked', false);
            $('#mml-results-panel').hide();
            MMLAdmin.updateSelectedCount();

            $.post(mmlAdmin.ajaxurl, {
                action:      'mml_scan_count',
                nonce:       mmlAdmin.nonce,
                scan_target: scanTarget
            }, function (res) {
                if (!res.success) {
                    self.scanDone('Không thể đếm dữ liệu.');
                    return;
                }
                self._optionsTotal  = parseInt(res.data.options_total,   10) || 0;
                self._gettextTotal  = parseInt(res.data.gettext_total,   10) || 0;
                self._uxBlocksTotal = parseInt(res.data.ux_blocks_total, 10) || 0;
                var total = self._optionsTotal + self._gettextTotal + self._uxBlocksTotal;
                if (total === 0) {
                    self.scanDone('Không tìm thấy dữ liệu nào để quét.');
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
            }).fail(function () { self.scanDone('Lỗi server.'); });
        },

        runScanBatch: function () {
            var self = this;
            if (!self._scanRunning) { self.scanDone('Đã dừng.'); return; }

            var phase = self._scanPhase;

            $.post(mmlAdmin.ajaxurl, {
                action:      'mml_scan_batch',
                nonce:       mmlAdmin.nonce,
                offset:      self._scanOffset,
                limit:       self._batchSize,
                scan_target: phase
            }, function (res) {
                if (!res.success) { self.scanDone('Lỗi khi quét.'); return; }

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
                        $('#mml-scan-status').text('Đang quét WooCommerce gettext strings…');
                        self.runScanBatch();
                        return;
                    }
                    // Options/gettext → ux_blocks phase (if enabled)
                    if ((phase === 'options' || phase === 'gettext') && self._includeUxBlocks && self._uxBlocksTotal > 0) {
                        self._scanPhase  = 'ux_blocks';
                        self._scanOffset = 0;
                        $('#mml-scan-status').text('Đang quét UX Blocks…');
                        self.runScanBatch();
                        return;
                    }
                    // All phases done
                    $('#mml-scan-bar').css('width', '100%');
                    self.scanDone('Hoàn tất. Tìm thấy ' + self._scanResults.length + ' chuỗi.');
                    if (self._scanResults.length > 0) {
                        $('#mml-results-panel').show();
                        MMLAdmin.updateSelectedCount();
                    }
                } else {
                    self._scanOffset = data.next_offset;
                    self.runScanBatch();
                }
            }).fail(function () { self.scanDone('Lỗi server.'); });
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

            $('#mml-process-btn, #mml-process-btn-bottom').prop('disabled', true).text('Đang xử lý…');
            $('#mml-process-progress').show();
            $('#mml-process-result').hide();

            $.post(mmlAdmin.ajaxurl, {
                action: 'mml_scan_process',
                nonce:  mmlAdmin.nonce,
                items:  JSON.stringify(items)
            }, function (res) {
                $('#mml-process-btn, #mml-process-btn-bottom').text('Xử lý các mục đã chọn');
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
                $('#mml-process-btn, #mml-process-btn-bottom').text('Xử lý các mục đã chọn').prop('disabled', false);
                $('#mml-process-progress').hide();
                $('#mml-process-result').removeClass('notice-success').addClass('notice-error')
                    .html('<p>Lỗi server.</p>').show();
            });
        },

        bindSessionActions: function () {
            var self = this;

            // Restore session
            $(document).on('click', '.mml-restore-btn', function () {
                var sid = $(this).data('sid');
                if (!window.confirm(mmlAdmin.confirmRestore)) { return; }
                var $btn = $(this);
                $btn.prop('disabled', true).text('Đang khôi phục…');
                $.post(mmlAdmin.ajaxurl, {
                    action:     'mml_scan_restore',
                    nonce:      mmlAdmin.nonce,
                    session_id: sid
                }, function (res) {
                    if (res.success) {
                        $btn.closest('.mml-session-row').fadeOut(300, function () {
                            $(this).remove();
                            if (!$('.mml-session-row').length) { $('#mml-no-sessions').show(); }
                        });
                        $('#mml-restore-result').removeClass('notice-error').addClass('notice-success')
                            .html('<p>' + (res.data.message || 'Đã khôi phục.') + '</p>').show();
                    } else {
                        $btn.prop('disabled', false).text('Khôi phục');
                        $('#mml-restore-result').removeClass('notice-success').addClass('notice-error')
                            .html('<p>' + (res.data.message || 'Lỗi khôi phục.') + '</p>').show();
                    }
                }).fail(function () {
                    $btn.prop('disabled', false).text('Khôi phục');
                    $('#mml-restore-result').removeClass('notice-success').addClass('notice-error')
                        .html('<p>Lỗi server.</p>').show();
                });
            });

            // Discard session
            $(document).on('click', '.mml-discard-btn', function () {
                var sid = $(this).data('sid');
                if (!window.confirm(mmlAdmin.confirmDiscard)) { return; }
                var $btn = $(this);
                $btn.prop('disabled', true).text('Đang xóa…');
                $.post(mmlAdmin.ajaxurl, {
                    action:     'mml_scan_delete_session',
                    nonce:      mmlAdmin.nonce,
                    session_id: sid
                }, function (res) {
                    if (res.success) {
                        $btn.closest('.mml-session-row').fadeOut(300, function () {
                            $(this).remove();
                            if (!$('.mml-session-row').length) { $('#mml-no-sessions').show(); }
                        });
                    } else {
                        $btn.prop('disabled', false).text('Bỏ qua');
                        alert(res.data.message || 'Xóa thất bại.');
                    }
                }).fail(function () {
                    $btn.prop('disabled', false).text('Bỏ qua');
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
                $btn.prop('disabled', true).text('Đang xử lý…');
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
                    $('#mml-manual-add-status').text('Lỗi server.').css('color', '#d63638');
                });
            });
        },

        reloadSessions: function () {            $.post(mmlAdmin.ajaxurl, {
                action: 'mml_scan_get_sessions',
                nonce:  mmlAdmin.nonce
            }, function (res) {
                if (!res.success) { return; }
                var sessions = res.data || [];
                var $list    = $('#mml-sessions-list');
                $list.find('.mml-session-row').remove();
                if (!sessions.length) {
                    $('#mml-no-sessions').show();
                    return;
                }
                $('#mml-no-sessions').hide();
                $.each(sessions, function (i, s) {
                    var label = s.created_at + ' — ' +
                        (s.key_count > 0 ? s.key_count + ' chuỗi' : s.post_count + ' bài');
                    var row = '<div class="mml-session-row">' +
                        '<span class="mml-session-label">' + label + '</span>' +
                        '<button type="button" class="button mml-restore-btn" data-sid="' + s.session_id + '">Khôi phục</button>' +
                        '<button type="button" class="button mml-discard-btn" data-sid="' + s.session_id + '">Bỏ qua</button>' +
                        '</div>';
                    $list.append(row);
                });
            });
        },

        // ── Rescue Scanner (Phase D) ─────────────────────────────────────
        //
        // Two-step workflow:
        //   Step 1 — "Scan" batches through all posts to find old-format
        //             [my_trans key="X"] shortcodes without `original=`.
        //             Results split into upgradeable / unresolvable tables.
        //   Step 2 — "Upgrade All" sends a single AJAX call that rewrites
        //             every upgradeable shortcode in-place.

        _rescueUpgradeable:  [],
        _rescueUnresolvable: [],

        bindRescueScanner: function () {
            if (!$('#mml-rescue-scan-btn').length) { return; }

            $('#mml-rescue-scan-btn').on('click', $.proxy(this.startRescueScan, this));
            $('#mml-rescue-upgrade-btn').on('click', $.proxy(this.runRescueUpgrade, this));
        },

        startRescueScan: function () {
            var self = this;
            self._rescueUpgradeable  = [];
            self._rescueUnresolvable = [];

            $('#mml-rescue-scan-btn').prop('disabled', true).text('Đang quét…');
            $('#mml-rescue-upgrade-btn').hide();
            $('#mml-rescue-status').text('');
            $('#mml-rescue-progress-wrap').show();
            $('#mml-rescue-bar').css('width', '5%');
            $('#mml-rescue-results').hide();
            $('#mml-rescue-upgradeable-tbody').empty();
            $('#mml-rescue-unresolvable-tbody').empty();
            $('#mml-rescue-upgrade-result').hide();

            self._runRescueScanBatch(0);
        },

        _runRescueScanBatch: function (offset) {
            var self = this;

            $.post(mmlAdmin.ajaxurl, {
                action: 'mml_scan_rescue_scan',
                nonce:  mmlAdmin.nonce,
                offset: offset,
                limit:  10
            }, function (res) {
                if (!res.success) {
                    self._rescueScanDone('Lỗi khi quét: ' + (res.data || ''));
                    return;
                }
                var data = res.data;

                // Accumulate results
                if (data.upgradeable && data.upgradeable.length) {
                    $.each(data.upgradeable, function (i, item) {
                        // Deduplicate by key
                        var already = false;
                        for (var j = 0; j < self._rescueUpgradeable.length; j++) {
                            if (self._rescueUpgradeable[j].key === item.key) { already = true; break; }
                        }
                        if (!already) {
                            self._rescueUpgradeable.push(item);
                            var row = '<tr><td><code>' + $('<span>').text(item.key).html() + '</code></td>' +
                                      '<td>' + $('<span>').text(item.vi_text).html() + '</td>' +
                                      '<td>' + $('<span>').text(item.post_title || 'Post #' + item.post_id).html() + '</td></tr>';
                            $('#mml-rescue-upgradeable-tbody').append(row);
                        }
                    });
                }
                if (data.unresolvable && data.unresolvable.length) {
                    $.each(data.unresolvable, function (i, item) {
                        var already = false;
                        for (var j = 0; j < self._rescueUnresolvable.length; j++) {
                            if (self._rescueUnresolvable[j].key === item.key) { already = true; break; }
                        }
                        if (!already) {
                            self._rescueUnresolvable.push(item);
                            var row = '<tr><td><code>' + $('<span>').text(item.key).html() + '</code></td>' +
                                      '<td>' + $('<span>').text(item.post_title || 'Post #' + item.post_id).html() + '</td></tr>';
                            $('#mml-rescue-unresolvable-tbody').append(row);
                        }
                    });
                }

                // Update progress bar proportionally
                var pct = data.done ? 100 : Math.min(90, 5 + (offset / Math.max(offset + 10, 1)) * 85);
                $('#mml-rescue-bar').css('width', pct + '%');

                if (!data.done) {
                    self._runRescueScanBatch(data.next_offset);
                } else {
                    self._rescueScanDone(null);
                }
            }).fail(function () {
                self._rescueScanDone('Lỗi server.');
            });
        },

        _rescueScanDone: function (errorMsg) {
            var u = this._rescueUpgradeable.length;
            var r = this._rescueUnresolvable.length;

            $('#mml-rescue-bar').css('width', '100%');
            $('#mml-rescue-scan-btn').prop('disabled', false).text('🔍 Step 1 — Scan for Old Shortcodes');

            if (errorMsg) {
                $('#mml-rescue-status').text(errorMsg).css('color', '#d63638');
                return;
            }

            if (u === 0 && r === 0) {
                $('#mml-rescue-status').text('✅ Tất cả shortcode đã có thuộc tính original — không cần nâng cấp.').css('color', '#00a32a');
                return;
            }

            $('#mml-rescue-status').text('Tìm thấy ' + u + ' có thể nâng cấp, ' + r + ' không thể khôi phục.').css('color', '');
            $('#mml-rescue-upgradeable-count').text(u);
            $('#mml-rescue-unresolvable-count').text(r);

            if (u > 0) { $('#mml-rescue-upgradeable-panel').show(); }
            if (r > 0) { $('#mml-rescue-unresolvable-panel').show(); }
            $('#mml-rescue-results').show();

            if (u > 0) {
                $('#mml-rescue-upgrade-btn').show();
            }
        },

        runRescueUpgrade: function () {
            var self = this;
            if (!window.confirm('Nâng cấp ' + self._rescueUpgradeable.length + ' shortcode? Thao tác này sẽ chỉnh sửa post_content.')) {
                return;
            }

            $('#mml-rescue-upgrade-btn').prop('disabled', true).text('Đang nâng cấp…');
            $('#mml-rescue-status').text('').css('color', '');
            $('#mml-rescue-upgrade-result').hide();

            $.post(mmlAdmin.ajaxurl, {
                action: 'mml_scan_rescue_upgrade',
                nonce:  mmlAdmin.nonce
            }, function (res) {
                $('#mml-rescue-upgrade-btn').prop('disabled', false).text('⬆ Step 2 — Upgrade All Upgradeable');
                if (res.success) {
                    $('#mml-rescue-upgrade-result')
                        .removeClass('notice-error').addClass('notice-success')
                        .html('<p>' + (res.data.message || 'Hoàn tất!') + '</p>').show();
                    // Hide upgrade button — upgrade is done
                    $('#mml-rescue-upgrade-btn').hide();
                } else {
                    $('#mml-rescue-upgrade-result')
                        .removeClass('notice-success').addClass('notice-error')
                        .html('<p>' + (res.data || 'Có lỗi xảy ra.') + '</p>').show();
                }
            }).fail(function () {
                $('#mml-rescue-upgrade-btn').prop('disabled', false).text('⬆ Step 2 — Upgrade All Upgradeable');
                $('#mml-rescue-upgrade-result')
                    .removeClass('notice-success').addClass('notice-error')
                    .html('<p>Lỗi server.</p>').show();
            });
        }
    };

    $(document).ready(function () {
        MMLAdmin.init();
        MMLAdmin.bindScanner();
        if (typeof MMLAdmin.bindRescueScanner === 'function') {
            MMLAdmin.bindRescueScanner();
        }
    });

}(jQuery));
