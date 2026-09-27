<?php
/**
 * Plugin Name:       Inline Checkout for WooCommerce
 * Plugin URI:        https://github.com/Halleluyahsalako/wc-inline-checkout
 * Description:       Buy Now and one page checkout for WooCommerce, with inline Paystack and Flutterwave payment. Transactions are verified server side against the gateway before an order is completed.
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Salako Halleluyah
 * Author URI:        https://halleluyahsalako.github.io
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wc-inline-checkout
 * Domain Path:       /languages
 * WC requires at least: 6.0
 * WC tested up to:   9.4
 *
 * @package WC_Inline_Checkout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCIC_VERSION', '2.0.0' );
define( 'WCIC_FILE', __FILE__ );
define( 'WCIC_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCIC_URL', plugin_dir_url( __FILE__ ) );

require_once WCIC_DIR . 'includes/class-wcic-verifier.php';

/**
 * Buy Now plus an inline checkout on the product page, with in page payment.
 *
 * The flow is deliberately conventional up to the payment step: WooCommerce
 * still creates the order through its own checkout process, so taxes, coupons,
 * shipping and every third party hook behave normally. Only the payment step
 * is replaced, and only the gateways this plugin understands are intercepted.
 */
class WC_Inline_Checkout {

	/**
	 * Gateway ids this plugin can render inline. Anything else falls through
	 * to WooCommerce's normal redirect behaviour.
	 *
	 * @var string[]
	 */
	private $supported_gateways = array( 'paystack', 'rave' );

	public function __construct() {
		if ( ! $this->woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'missing_woocommerce_notice' ) );
			return;
		}

		add_action( 'init', array( $this, 'load_textdomain' ) );

		add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'render_buy_now_button' ) );
		add_action( 'woocommerce_after_single_product_summary', array( $this, 'render_checkout_container' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 999 );

		add_action( 'wp_ajax_wcic_buy_now', array( $this, 'ajax_buy_now' ) );
		add_action( 'wp_ajax_nopriv_wcic_buy_now', array( $this, 'ajax_buy_now' ) );
		add_action( 'wp_ajax_wcic_payment_data', array( $this, 'ajax_payment_data' ) );
		add_action( 'wp_ajax_nopriv_wcic_payment_data', array( $this, 'ajax_payment_data' ) );
		add_action( 'wp_ajax_wcic_verify_payment', array( $this, 'ajax_verify_payment' ) );
		add_action( 'wp_ajax_nopriv_wcic_verify_payment', array( $this, 'ajax_verify_payment' ) );
		add_action( 'wp_ajax_wcic_update_order_review', array( $this, 'ajax_update_order_review' ) );
		add_action( 'wp_ajax_nopriv_wcic_update_order_review', array( $this, 'ajax_update_order_review' ) );

		// Only suppress the add to cart redirect on product pages, where the
		// inline checkout actually runs. Applying this site wide breaks other
		// plugins that legitimately redirect after adding to cart.
		add_filter( 'woocommerce_add_to_cart_redirect', array( $this, 'maybe_suppress_redirect' ), 999 );

		add_action( 'wp', array( $this, 'resolve_theme_conflicts' ), 999 );
	}

	private function woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	public function missing_woocommerce_notice() {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Inline Checkout for WooCommerce requires WooCommerce to be installed and active.', 'wc-inline-checkout' )
		);
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'wc-inline-checkout', false, dirname( plugin_basename( WCIC_FILE ) ) . '/languages' );
	}

	/* ---------------------------------------------------------------------
	 * Front end
	 * ------------------------------------------------------------------- */

	public function render_buy_now_button() {
		global $product;

		if ( ! $product instanceof WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return;
		}

		printf(
			'<button type="button" class="button alt wcic-buy-now" data-product-id="%1$s">%2$s</button>',
			esc_attr( $product->get_id() ),
			esc_html( apply_filters( 'wcic_buy_now_label', __( 'Buy Now', 'wc-inline-checkout' ) ) )
		);
	}

	public function render_checkout_container() {
		?>
		<div id="wcic-container" class="wcic-container" hidden>
			<h2 class="wcic-heading"><?php esc_html_e( 'Checkout', 'wc-inline-checkout' ); ?></h2>
			<div id="wcic-form-wrapper"></div>
		</div>
		<?php
	}

	public function enqueue_assets() {
		if ( ! is_product() ) {
			return;
		}

		wp_enqueue_script( 'wc-add-to-cart' );
		wp_enqueue_script( 'wc-cart-fragments' );

		wp_enqueue_style( 'wcic', WCIC_URL . 'assets/css/inline-checkout.css', array(), WCIC_VERSION );
		wp_enqueue_script(
			'wcic',
			WCIC_URL . 'assets/js/inline-checkout.js',
			array( 'jquery', 'wc-add-to-cart', 'wc-cart-fragments' ),
			WCIC_VERSION,
			true
		);

		wp_localize_script(
			'wcic',
			'wcicVars',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'wcAjaxUrl'    => WC_AJAX::get_endpoint( '%%endpoint%%' ),
				'checkoutUrl'  => wc_get_checkout_url(),
				'cartUrl'      => wc_get_cart_url(),
				'nonce'        => wp_create_nonce( 'wcic' ),
				'reviewNonce'  => wp_create_nonce( 'update-order-review' ),
				'i18n'         => array(
					'genericError' => __( 'Something went wrong. Please try again.', 'wc-inline-checkout' ),
					'verifying'    => __( 'Verifying payment, please wait.', 'wc-inline-checkout' ),
				),
			)
		);
	}

	/**
	 * Suppress the post add to cart redirect only on product pages.
	 */
	public function maybe_suppress_redirect( $url ) {
		return is_product() ? false : $url;
	}

	/**
	 * Some themes hijack the single product add to cart with their own AJAX,
	 * which fights the inline checkout. Themes vary, so the list is filterable
	 * rather than hardcoded.
	 */
	public function resolve_theme_conflicts() {
		if ( ! is_product() ) {
			return;
		}

		$handles = apply_filters(
			'wcic_conflicting_script_handles',
			array( 'astra-addon-js', 'astra-ajax-product' )
		);

		foreach ( (array) $handles as $handle ) {
			wp_dequeue_script( $handle );
		}

		$filters = apply_filters(
			'wcic_conflicting_cart_redirect_filters',
			array( 'astra_product_single_ajax_add_to_cart_redirect' )
		);

		foreach ( (array) $filters as $callback ) {
			remove_filter( 'woocommerce_add_to_cart_redirect', $callback );
		}
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------- */

	/**
	 * Empty the cart, add the chosen product, and return the rendered checkout.
	 */
	public function ajax_buy_now() {
		check_ajax_referer( 'wcic', 'nonce' );

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$quantity   = isset( $_POST['quantity'] ) ? absint( $_POST['quantity'] ) : 1;

		$product = $product_id ? wc_get_product( $product_id ) : false;

		if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			wp_send_json_error( array( 'message' => __( 'This product cannot be purchased.', 'wc-inline-checkout' ) ) );
		}

		if ( ! WC()->cart ) {
			wc_load_cart();
		}

		WC()->cart->empty_cart();

		if ( ! WC()->cart->add_to_cart( $product_id, max( 1, $quantity ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not add this product to the cart.', 'wc-inline-checkout' ) ) );
		}

		ob_start();
		echo do_shortcode( '[woocommerce_checkout]' );
		$html = ob_get_clean();

		wp_send_json_success( array( 'checkout_html' => $html ) );
	}

	/**
	 * Recalculate totals when the customer changes address or shipping method.
	 */
	public function ajax_update_order_review() {
		check_ajax_referer( 'update-order-review', 'security' );

		wc_maybe_define_constant( 'WOOCOMMERCE_CHECKOUT', true );

		$post_data = array();
		if ( isset( $_POST['post_data'] ) ) {
			parse_str( wp_unslash( $_POST['post_data'] ), $post_data );
		}

		$this->apply_customer_address( $post_data );
		$this->apply_shipping_choice( $post_data );

		WC()->cart->calculate_shipping();
		WC()->cart->calculate_totals();

		ob_start();
		woocommerce_order_review();
		$review = ob_get_clean();

		ob_start();
		woocommerce_checkout_payment();
		$payment = ob_get_clean();

		wp_send_json(
			array(
				'result'    => 'success',
				'reload'    => false,
				'fragments' => array(
					'.woocommerce-checkout-review-order-table' => $review,
					'.woocommerce-checkout-payment'            => $payment,
				),
			)
		);
	}

	private function apply_customer_address( array $post_data ) {
		$fields = array( 'country', 'state', 'postcode', 'city', 'address_1' );

		foreach ( $fields as $field ) {
			$key = 'billing_' . $field;

			if ( empty( $post_data[ $key ] ) ) {
				continue;
			}

			$value = wc_clean( wp_unslash( $post_data[ $key ] ) );

			WC()->customer->{"set_billing_{$field}"}( $value );
			WC()->customer->{"set_shipping_{$field}"}( $value );
		}

		WC()->customer->save();
	}

	private function apply_shipping_choice( array $post_data ) {
		if ( empty( $post_data['shipping_method'] ) ) {
			return;
		}

		$chosen = (array) $post_data['shipping_method'];
		$chosen = array_map( 'wc_clean', $chosen );

		WC()->session->set( 'chosen_shipping_methods', $chosen );
	}

	/**
	 * Hand the browser the public key and reference it needs to open the
	 * gateway's inline widget. No secret ever leaves the server.
	 */
	public function ajax_payment_data() {
		check_ajax_referer( 'wcic', 'nonce' );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$method   = isset( $_POST['payment_method'] ) ? sanitize_key( wp_unslash( $_POST['payment_method'] ) ) : '';

		$order = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || ! $this->current_user_owns_order( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'wc-inline-checkout' ) ) );
		}

		if ( ! in_array( $method, $this->supported_gateways, true ) ) {
			wp_send_json_error( array( 'message' => __( 'This payment method is not handled inline.', 'wc-inline-checkout' ) ) );
		}

		$verifier   = WCIC_Verifier::for_gateway( $method );
		$public_key = $verifier ? $verifier->public_key() : '';

		if ( ! $public_key ) {
			wp_send_json_error( array( 'message' => __( 'This gateway is not fully configured.', 'wc-inline-checkout' ) ) );
		}

		$reference = $verifier->build_reference( $order_id );
		$order->update_meta_data( '_wcic_reference', $reference );
		$order->update_meta_data( '_wcic_gateway', $method );
		$order->save();

		wp_send_json_success(
			array(
				'gateway'      => $verifier->slug(),
				'publicKey'    => $public_key,
				'email'        => $order->get_billing_email(),
				'amount'       => $verifier->amount_for_widget( $order->get_total() ),
				'currency'     => $order->get_currency(),
				'reference'    => $reference,
				'customerName' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'siteName'     => get_bloginfo( 'name' ),
				'orderId'      => $order_id,
			)
		);
	}

	/**
	 * Complete the order, but only after the gateway itself confirms the
	 * transaction succeeded for the right amount and currency.
	 *
	 * The browser is not trusted here. It tells us which order and reference
	 * to look at; everything that decides whether the order gets paid comes
	 * from a server to server call to the gateway.
	 */
	public function ajax_verify_payment() {
		check_ajax_referer( 'wcic', 'nonce' );

		$order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$reference = isset( $_POST['reference'] ) ? sanitize_text_field( wp_unslash( $_POST['reference'] ) ) : '';

		$order = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || ! $reference ) {
			wp_send_json_error( array( 'message' => __( 'Missing order or reference.', 'wc-inline-checkout' ) ) );
		}

		if ( ! $this->current_user_owns_order( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'wc-inline-checkout' ) ) );
		}

		// Already paid. Treat a repeat call as success rather than an error,
		// because a customer refreshing mid redirect is normal.
		if ( $order->is_paid() ) {
			wp_send_json_success(
				array(
					'redirect'        => $order->get_checkout_order_received_url(),
					'alreadyComplete' => true,
				)
			);
		}

		// The reference must be the one we issued for this order. Without this
		// check a valid transaction from any other order could be replayed here.
		if ( ! hash_equals( (string) $order->get_meta( '_wcic_reference' ), $reference ) ) {
			wp_send_json_error( array( 'message' => __( 'This reference does not belong to this order.', 'wc-inline-checkout' ) ) );
		}

		$gateway  = (string) $order->get_meta( '_wcic_gateway' );
		$verifier = WCIC_Verifier::for_gateway( $gateway );

		if ( ! $verifier ) {
			wp_send_json_error( array( 'message' => __( 'Unknown gateway for this order.', 'wc-inline-checkout' ) ) );
		}

		$result = $verifier->verify( $reference, $order );

		if ( is_wp_error( $result ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: gateway name, 2: error message */
					__( 'Inline Checkout could not verify the payment with %1$s: %2$s', 'wc-inline-checkout' ),
					$verifier->slug(),
					$result->get_error_message()
				)
			);
			$order->save();

			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$order->payment_complete( $result['gateway_reference'] );
		$order->add_order_note(
			sprintf(
				/* translators: 1: gateway name, 2: transaction reference */
				__( 'Payment verified server side with %1$s. Reference: %2$s', 'wc-inline-checkout' ),
				$verifier->slug(),
				$result['gateway_reference']
			)
		);
		$order->save();

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		wp_send_json_success(
			array(
				'redirect' => $order->get_checkout_order_received_url(),
				'orderId'  => $order_id,
			)
		);
	}

	/**
	 * Guests place orders too, so this cannot simply compare user ids. A guest
	 * is allowed through only while the order key is still in their session,
	 * which is how WooCommerce itself scopes the order received page.
	 */
	private function current_user_owns_order( WC_Order $order ) {
		$customer_id = $order->get_customer_id();

		if ( $customer_id && get_current_user_id() === $customer_id ) {
			return true;
		}

		if ( ! $customer_id && WC()->session ) {
			$session_order_id = absint( WC()->session->get( 'order_awaiting_payment' ) );

			if ( $session_order_id === $order->get_id() ) {
				return true;
			}
		}

		return current_user_can( 'edit_shop_orders' );
	}
}

add_action( 'plugins_loaded', function () {
	new WC_Inline_Checkout();
} );

// Tell WooCommerce this plugin is safe with High Performance Order Storage.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WCIC_FILE, true );
	}
} );
