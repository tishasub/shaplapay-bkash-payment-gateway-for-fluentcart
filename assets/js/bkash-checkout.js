window.addEventListener('fluent_cart_load_payments_bkash', function (event) {
    var loader = event.detail && event.detail.paymentLoader;
    var vars = window.fluentcart_checkout_vars || {};
    var label = (vars.submit_button || {}).text || 'Place Order';

    if (loader && typeof loader.enableCheckoutButton === 'function') {
        loader.enableCheckoutButton(label);
    }
});
