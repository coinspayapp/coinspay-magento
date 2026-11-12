/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
define([
    'jquery',
    'mage/translate'
], function ($, $t) {
    'use strict';

    /**
     * "Check connection" on the settings page: sends the address and key of the form (an unchanged
     * key field holds asterisks, and the saved key is checked then) and shows the answer line by line.
     *
     * @param {Object} config
     * @param {HTMLElement} element
     */
    return function (config, element) {
        $(element).on('click', function () {
            var out = $('#' + config.result);

            out.empty().append($('<div/>').text($t('Checking…')));
            $.ajax({
                url: config.url,
                type: 'POST',
                dataType: 'json',
                data: {
                    'form_key': window.FORM_KEY,
                    'api_url': $('#' + config.apiUrl).val() || '',
                    'api_key': $('#' + config.apiKey).val() || '',
                    website: config.website,
                    store: config.store
                }
            }).done(function (answer) {
                out.empty();
                $.each(answer.lines || [], function (i, line) {
                    $('<div/>')
                        .addClass(line.ok ? 'message message-success success' : 'message message-warning warning')
                        .text(line.text)
                        .appendTo(out);
                });
            }).fail(function () {
                out.empty().append(
                    $('<div class="message message-error error"/>').text($t('The check failed: the site did not answer.'))
                );
            });
        });
    };
});
