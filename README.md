# Inline Checkout for WooCommerce

Buy Now and a one page checkout for WooCommerce, with Paystack and Flutterwave payment collected in a modal instead of a redirect. Transactions are verified server side against the gateway before an order is marked paid.

Built for African stores where Paystack and Flutterwave are the practical gateways, and where every extra page load between a product and a payment costs conversions.

## What it does

- Adds a **Buy Now** button beside Add to Cart on the product page.
- Clicking it empties the cart, adds that product, and renders the full WooCommerce checkout inline on the same page.
- Address and shipping changes recalculate totals over AJAX without a page reload.
- Paystack and Flutterwave open in their own inline widget. The customer never leaves the product page.
- Any other gateway falls through to WooCommerce's normal checkout, untouched.

WooCommerce still creates the order through its own checkout process, so taxes, coupons, shipping rules and third party hooks behave exactly as they normally would. Only the payment step is replaced.

## Why the verification matters

The obvious way to build this is to let the gateway's JavaScript callback tell your server the payment succeeded, then complete the order. That is also how you give away free products.

The browser can be made to say anything. A payment widget callback is not proof of payment, it is a hint that payment might have happened.

This plugin treats it that way:

1. When the customer picks a gateway, the server generates a reference, stores it on the order, and returns only the **publishable** key to the browser.
2. When the widget reports success, the browser sends back the order id and reference. That is all it is trusted to do.
3. The server checks the reference matches the one it issued for that order, then calls the gateway API directly with the **secret** key:
   - Paystack: `GET https://api.paystack.co/transaction/verify/{reference}`
   - Flutterwave: `GET https://api.flutterwave.com/v3/transactions/verify_by_reference`
4. It confirms the transaction status is successful, the currency matches the order, and the amount matches the order total.
5. Only then does `payment_complete()` run.

A failed verification leaves the order unpaid and writes the reason into the order notes, so a store owner can see what happened rather than guessing.

### Things that are easy to get wrong, and how this handles them

**Subunit conversion.** Paystack quotes NGN, USD, GHS, ZAR and KES in the currency's subunit: a 1,500.00 NGN order is `150000`. Compare a subunit amount against a major unit total and the check passes for anything above one naira. The conversion lives in one place, keyed to a currency list.

**Reference replay.** A valid reference from one order could otherwise be submitted against another. The server checks the reference against the one stored on that specific order using `hash_equals`.

**Double completion.** A customer refreshing mid redirect hits the verification endpoint twice. The second call returns success rather than an error, because the order is already paid and that is not a failure state.

**Fees.** Flutterwave reports both `amount` and `amount_settled`. Settlement is net of fees, so comparing against it would reject every legitimate payment. This compares `charged_amount`.

## Requirements

- WordPress 6.0+
- WooCommerce 6.0+
- PHP 7.4+
- The Paystack or Flutterwave WooCommerce plugin installed and configured

Keys are read from the existing gateway settings, so there is nothing to configure twice. Install the plugin, activate it, and the Buy Now button appears.

## Compatibility

Declares compatibility with High Performance Order Storage.

Some themes replace the single product Add to Cart with their own AJAX handler, which fights the inline checkout. Conflicting handles can be dequeued through a filter:

```php
add_filter( 'wcic_conflicting_script_handles', function ( $handles ) {
    $handles[] = 'my-theme-ajax-cart';
    return $handles;
} );
```

## Filters

| Filter | Purpose |
|---|---|
| `wcic_buy_now_label` | Change the button text |
| `wcic_conflicting_script_handles` | Script handles to dequeue on product pages |
| `wcic_conflicting_cart_redirect_filters` | Callbacks to remove from `woocommerce_add_to_cart_redirect` |
| `wcic_verifier_for_gateway` | Register a verifier for another gateway |

### Adding a gateway

Extend `WCIC_Verifier`, implement four methods, and hook it up:

```php
class My_Verifier extends WCIC_Verifier {
    public function slug() { return 'mygateway'; }
    public function public_key() { return $this->gateway_setting( 'mygateway', 'public_key' ); }
    protected function secret_key() { return $this->gateway_setting( 'mygateway', 'secret_key' ); }

    public function verify( $reference, WC_Order $order ) {
        // Call the gateway API. Return WP_Error to refuse the payment, or
        // array( 'gateway_reference' => $ref ) to complete the order.
    }
}

add_filter( 'wcic_verifier_for_gateway', function ( $verifier, $gateway_id ) {
    return 'mygateway' === $gateway_id ? new My_Verifier() : $verifier;
}, 10, 2 );
```

## Status

Extracted from production code on a live store and generalised for release. The verification layer described above is a rewrite of the original, which trusted the browser callback. If you are running something similar in your own store, that is the part worth checking.

Issues and pull requests welcome.

## Licence

GPL-2.0-or-later. Same as WordPress.
