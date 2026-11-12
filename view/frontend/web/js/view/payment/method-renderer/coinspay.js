/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
define([
    'Magento_Checkout/js/view/payment/default',
    'mage/url'
], function (Component, url) {
    'use strict';

    /**
     * The CoinsPay method at checkout: after "Place Order" the buyer goes to the module's redirect,
     * which makes the invoice and opens the CoinsPay payment page.
     */
    return Component.extend({
        defaults: {
            template: 'CoinsPay_Payment/payment/coinspay',
            redirectAfterPlaceOrder: false
        },

        /**
         * @returns {Object}
         */
        settings: function () {
            var payment = window.checkoutConfig.payment || {};

            return payment.coinspay || {};
        },

        /**
         * @returns {String}
         */
        getDescription: function () {
            return this.settings().description || '';
        },

        /**
         * @returns {String}
         */
        getTestNote: function () {
            return this.settings().testNote || '';
        },

        /**
         * Goes to the payment page of the order just placed.
         */
        afterPlaceOrder: function () {
            window.location.replace(url.build('coinspay/payment/redirect'));
        }
    });
});
