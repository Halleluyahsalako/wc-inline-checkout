=== Inline Checkout for WooCommerce ===
Contributors: halleluyahsalako
Tags: woocommerce, checkout, one page checkout, buy now, paystack, flutterwave
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Buy Now and one page checkout for WooCommerce with inline Paystack and Flutterwave payment, verified server side.

== Description ==

Adds a Buy Now button to the product page and renders the full WooCommerce checkout inline beneath it. Paystack and Flutterwave payments open in their own widget, so the customer never leaves the product page.

WooCommerce still creates the order through its own checkout process, so taxes, coupons, shipping rules and third party hooks behave normally. Only the payment step is replaced, and only for gateways this plugin understands. Everything else falls through to the standard checkout.

= Payments are verified server side =

When a payment widget reports success, the browser is not believed. The server checks the reference against the one it issued for that order, then calls the gateway API directly with the secret key to confirm the transaction status, currency and amount before completing the order.

A failed verification leaves the order unpaid and records the reason in the order notes.

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
