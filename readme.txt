=== Inline Checkout for WooCommerce ===
Contributors: halleluyahsalako
Tags: woocommerce, checkout, one page checkout, buy now, paystack, flutterwave
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Buy Now and one page checkout for WooCommerce with inline Paystack and Flutterwave payment, verified server side.

== Description ==

Adds a Buy Now button to the product page and renders the full WooCommerce checkout inline beneath it. Paystack and Flutterwave payments open in their own widget, so the customer never leaves the product page.

WooCommerce still creates the order through its own checkout process, so taxes, coupons, shipping rules and third party hooks behave normally. Only the payment step is replaced, and only for gateways this plugin understands. Everything else falls through to the standard checkout.

= Payments are verified server side =

When a payment widget reports success, the browser is not believed. The server checks the reference against the one it issued for that order, then calls the gateway API directly with the secret key to confirm the transaction status, currency and amount before completing the order.

A failed verification leaves the order unpaid and records the reason in the order notes.

= Webhooks =

Payment confirmation does not depend on the customer's browser reaching your site. Each gateway can post directly to the plugin, which authenticates the sender, then ignores the payload and asks the gateway API what actually happened before completing anything.

Paystack webhooks are authenticated by recomputing the HMAC SHA512 signature over the raw request body and comparing it with hash_equals. Flutterwave webhooks are authenticated against the secret hash you set in its dashboard; if no hash is configured the plugin refuses the request rather than accepting an empty comparison.

Repeat deliveries are expected and handled. Every event is recorded against a unique index, so a webhook delivered three times completes an order once. Records older than thirty days are pruned automatically.

The URLs to paste into each dashboard are shown in the plugin settings.

= Requirements =

The Paystack or Flutterwave WooCommerce plugin must be installed and configured. Keys are read from those settings, so there is nothing to configure twice.

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/
2. Activate it through the Plugins menu
3. Make sure Paystack or Flutterwave is configured in WooCommerce > Settings > Payments

== Frequently Asked Questions ==

= Does this work with other payment gateways? =

Other gateways are left alone and use the normal WooCommerce checkout. Support for another gateway can be added through the wcic_verifier_for_gateway filter.

= My theme's Add to Cart fights with it =

Some themes replace the single product Add to Cart with their own AJAX. Dequeue the conflicting script through the wcic_conflicting_script_handles filter.

= Is it compatible with High Performance Order Storage? =

Yes, and it declares compatibility.

== Changelog ==

= 2.1.0 =
* Added webhook endpoints for Paystack and Flutterwave, so payment confirmation no longer depends on the customer's browser returning to the site.
* Paystack webhooks are authenticated by HMAC SHA512 over the raw body, compared with hash_equals.
* Flutterwave webhooks are authenticated against the configured secret hash, and are refused outright when no hash is set.
* Webhook payloads are never trusted for amounts. Every event triggers a fresh server to server verification.
* Repeat webhook deliveries are deduplicated through a unique index, so an event delivered more than once completes an order once.
* Paystack transactions now carry an environment check: a test transaction cannot complete an order on a live shop, and the reverse is also refused.
* Amount comparison changed from strict equality to "not short", in line with Flutterwave's own guidance, so a rounded or over paid amount no longer fails a correct payment.
* Overpayments are completed and recorded in an order note asking for the difference to be refunded.

= 2.0.0 =
* Payments are now verified server side against the Paystack and Flutterwave APIs, checking status, currency and amount before the order is completed. The previous release trusted the browser callback.
* References are bound to their order and compared with hash_equals, so a reference cannot be replayed against a different order.
* Correct subunit handling for Paystack currencies, so amount checks cannot be bypassed.
* Repeat verification of an already paid order returns success instead of an error.
* Gateway verification extracted behind a WCIC_Verifier base class, extensible via filter.
* Theme conflict handling moved from hardcoded Astra references to filters.
* The add to cart redirect is now suppressed only on product pages instead of site wide.
* Error messages are escaped before being rendered.
* Debug logging removed.

= 1.0.0 =
* Initial release.
