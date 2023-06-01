define(
    [
        'jquery',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_Checkout/js/model/quote',
        'Magento_Customer/js/customer-data',
        'mage/url',
        'Magento_Ui/js/model/messageList',
        'Magento_Checkout/js/model/totals'
    ],
    function($, Component, additionalValidators, quote, customerData, url, messageList, totals) {
        'use strict';
        return Component.extend({
            defaults: {
                template: 'TradeSafe_PaymentGateway/payment/tradesafe'
            },
            getMailingAddress: function () {
                return window.checkoutConfig.payment.checkmo.mailingAddress;
            },
            getInstructions: function () {
                return window.checkoutConfig.payment.instructions[this.item.method];
            },
            getLogo: function() {
                return require.toUrl('TradeSafe_PaymentGateway/images/logo.png');
            },

            continueToTradeSafe: function() {
                console.log('trade safe clicked');
                console.log(totals.totals().base_grand_total);
                console.log(customerData);
                $('body').trigger('processStart');
                if (additionalValidators.validate()) {
                    customerData.invalidate(['cart']);

                    var linkUrl = url.build('tradesafe/checkout/index');
                    var linkUrlsuccess = url.build('tradesafe/success/index');
                    console.log('linkUrlsuccess:: ' + linkUrlsuccess);
                    var cartTotal = totals.totals().base_grand_total;
                    $.ajax({
                        url: linkUrl,
                        type: 'POST',
                        data: { cartTotal: cartTotal} ,
                        success: function(response) {
                            console.log(response);
                            if (response.redirectUrl) {
                                window.location = response.redirectUrl;
                            }
                            console.log(response.error);
                            $('body').trigger('processStop');
                        },
                        error: function(xhr, status, errorThrown) {
                            $('body').trigger('processStop');
                            messageList.addErrorMessage({ message: errorThrown });
                        }
                    });
                    return false;
                }
                $('body').trigger('processStop');
            }
        });
    }
);
