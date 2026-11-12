/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'coinspay',
        component: 'CoinsPay_Payment/js/view/payment/method-renderer/coinspay'
    });

    return Component.extend({});
});
