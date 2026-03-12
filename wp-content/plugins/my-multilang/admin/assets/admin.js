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
        }
    };

    $(document).ready(function () {
        MMLAdmin.init();
    });

}(jQuery));
