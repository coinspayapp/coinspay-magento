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
     * "Refresh" on the order page: asks CoinsPay about the invoice now, then reloads the page to show
     * the order as it is.
     *
     * @param {Object} config
     * @param {HTMLElement} element
     */
    return function (config, element) {
        $(element).on('click', function () {
            var out = $('#' + config.result);

            out.text($t('Checking…'));
            $.ajax({
                url: config.url,
                type: 'POST',
                dataType: 'json',
                data: {'form_key': window.FORM_KEY, 'order_id': config.orderId}
            }).done(function (answer) {
                out.text(answer.message || '');
                if (answer.ok) {
                    window.location.reload();
                }
            }).fail(function () {
                out.text($t('The check failed: the site did not answer.'));
            });
        });
    };
});
