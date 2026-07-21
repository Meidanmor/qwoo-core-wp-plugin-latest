(function ($) {
    'use strict';

    $(function () {

        /* ── Localhost toggle ── */
        $('#localhost_enabled').on('change', function () {
            if ($(this).is(':checked')) {
                $('.qwoo-localhost-port').removeClass('qwoo-hidden');
            } else {
                $('.qwoo-localhost-port').addClass('qwoo-hidden');
            }
        });

        /* ── Reset API key ── */
        $(document).on('click', '.qwoo-reset-key', function () {
            const $btn     = $(this);
            const keyName  = $btn.data('key');
            const $field   = $btn.closest('.qwoo-key-field');

            if (!confirm('Reset this key? You will need to re-enter it.')) return;

            $btn.prop('disabled', true).text('Resetting…');

            $.post(qwooTech.ajax_url, {
                action:   'qwoo_reset_api_key',
                nonce:    qwooTech.nonce,
                key_name: keyName,
            }, function (res) {
                if (res.success) {
                    // Replace masked row with a fresh input
                    $field.find('.qwoo-masked-row').replaceWith(
                        '<input type="password"' +
                        ' name="qwoo_api_keys[' + keyName + ']"' +
                        ' class="qwoo-input qwoo-input--key"' +
                        ' placeholder="Enter new value"' +
                        ' autocomplete="new-password" />'
                    );
                    // Update badge
                    $field.find('.qwoo-badge')
                        .removeClass('qwoo-badge--set')
                        .addClass('qwoo-badge--unset')
                        .text('Not set');
                } else {
                    alert('Could not reset key: ' + (res.data || 'Unknown error'));
                    $btn.prop('disabled', false).text('Reset');
                }
            });
        });

        /* ── Save settings ── */
        $('#qwoo-save-technical').on('click', function () {
            const $btn    = $(this);
            const $text   = $btn.find('.qwoo-btn__text');
            const $loader = $btn.find('.qwoo-btn__loader');

            $text.hide();
            $loader.show();
            $btn.prop('disabled', true);

            // Collect all named inputs from the page
            const data = {
                action: 'qwoo_save_technical_settings',
                nonce:  qwooTech.nonce,
            };

            // CORS fields
            data['qwoo_technical[frontend_domain]']   = $('#frontend_domain').val();
            data['qwoo_technical[localhost_enabled]'] = $('#localhost_enabled').is(':checked') ? 1 : '';
            data['qwoo_technical[localhost_port]']    = $('#localhost_port').val();
            data['qwoo_technical[push_email]']        = $('#push_email').val();

            // API key fields (only non-empty, non-masked ones)
            $('input.qwoo-input--key[name^="qwoo_api_keys"]').each(function () {
                const val = $(this).val().trim();
                if (val && val !== '••••••••••••••••') {
                    data[$(this).attr('name')] = val;
                }
            });

            $.post(qwooTech.ajax_url, data, function (res) {
                $text.show();
                $loader.hide();
                $btn.prop('disabled', false);

                showNotice(
                    res.success ? res.data : (res.data || 'An error occurred.'),
                    res.success ? 'success' : 'error'
                );

                if (res.success) {
                    // Re-mask any newly saved key fields
                    $('input.qwoo-input--key[name^="qwoo_api_keys"]').each(function () {
                        if ($(this).val().trim()) {
                            const keyName = $(this).attr('name').match(/\[([^\]]+)\]/)[1];
                            $(this).closest('.qwoo-key-field').find('label .qwoo-badge')
                                .removeClass('qwoo-badge--unset')
                                .addClass('qwoo-badge--set')
                                .text('Saved');
                            $(this).replaceWith(
                                '<div class="qwoo-masked-row">' +
                                '<input type="text" class="qwoo-input qwoo-input--masked" value="••••••••••••••••" readonly />' +
                                '<button type="button" class="qwoo-btn qwoo-btn--ghost qwoo-reset-key" data-key="' + keyName + '">Reset</button>' +
                                '</div>'
                            );
                        }
                    });
                }
            }).fail(function () {
                $text.show();
                $loader.hide();
                $btn.prop('disabled', false);
                showNotice('Request failed. Please try again.', 'error');
            });
        });

        /* ── Notice helper ── */
        function showNotice(message, type) {
            const $notice = $('#qwoo-save-notice');
            $notice
                .removeClass('qwoo-notice--success qwoo-notice--error')
                .addClass('qwoo-notice--' + type)
                .text(message)
                .show();

            $('html, body').animate({ scrollTop: 0 }, 300);

            setTimeout(function () {
                $notice.fadeOut(400);
            }, 4000);
        }

    });

}(jQuery));