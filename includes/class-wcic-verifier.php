<?php
/**
 * Server side transaction verification.
 *
 * @package WC_Inline_Checkout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for gateway verification.
 *
 * The contract is narrow on purpose: given a reference and an order, either
 * return the confirmed gateway reference or a WP_Error explaining why the
 * payment must not be accepted. Nothing here trusts client input.
 */
abstract class WCIC_Verifier {

	/**
	 * Return a verifier for a WooCommerce gateway id, or null if unsupported.
	 *
	 * @param string $gateway_id WooCommerce gateway id.
	 * @return WCIC_Verifier|null
	 */
	public static function for_gateway( $gateway_id ) {
		switch ( $gateway_id ) {
			case 'paystack':
				return new WCIC_Paystack_Verifier();
			case 'rave':
				return new WCIC_Flutterwave_Verifier();
		}

		return apply_filters( 'wcic_verifier_for_gateway', null, $gateway_id );
	}

	/** Short name used in order notes and passed to the front end. */
	abstract public function slug();

	/** Publishable key for the inline widget. Never the secret. */
	abstract public function public_key();

	/** Secret key, used only for server to server calls. */
	abstract protected function secret_key();

	/**
	 * Verify a transaction.
	 *
	 * @param string   $reference Reference this plugin issued for the order.
	 * @param WC_Order $order     The order being paid.
	 * @return array|WP_Error array( 'gateway_reference' => string ) on success.
	 */
	abstract public function verify( $reference, WC_Order $order );

	/** Amount in the unit the gateway's JS widget expects. */
	public function amount_for_widget( $total ) {
		return (float) $total;
	}

	public function build_reference( $order_id ) {
		return strtoupper( $this->slug() ) . '_' . $order_id . '_' . wp_generate_password( 12, false );
	}

	/**
	 * Read a setting from the installed WooCommerce gateway, so store owners
	 * configure keys in one place rather than twice.
	 */
	protected function gateway_setting( $gateway_id, $key ) {
		$settings = get_option( 'woocommerce_' . $gateway_id . '_settings', array() );

		return isset( $settings[ $key ] ) ? trim( (string) $settings[ $key ] ) : '';
	}

	/**
	 * Compare the paid amount with the order total, allowing for floating point
	 * noise but nothing more. An underpayment must never complete an order.
	 */
	protected function amounts_match( $paid, $expected ) {
		return abs( (float) $paid - (float) $expected ) < 0.01;
	}

	protected function get( $url, array $headers ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'wcic_http', __( 'Could not reach the payment gateway.', 'wc-inline-checkout' ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code || ! is_array( $body ) ) {
			return new WP_Error( 'wcic_http', __( 'The payment gateway returned an unexpected response.', 'wc-inline-checkout' ) );
		}

		return $body;
	}
}

/**
 * Paystack.
 *
 * Amounts are handled in the currency's subunit, so a 1500.00 NGN order is
 * 150000 to Paystack. Getting this wrong is the classic way an amount check
 * passes when it should not, so the conversion lives in one place.
 */
class WCIC_Paystack_Verifier extends WCIC_Verifier {

	/** Currencies Paystack expects in subunits. */
	private $subunit_currencies = array( 'NGN', 'USD', 'GHS', 'ZAR', 'KES' );

	public function slug() {
		return 'paystack';
	}

	private function test_mode() {
		return 'yes' === $this->gateway_setting( 'paystack', 'testmode' );
	}

	public function public_key() {
		return $this->test_mode()
			? $this->gateway_setting( 'paystack', 'test_public_key' )
			: $this->gateway_setting( 'paystack', 'live_public_key' );
	}

	protected function secret_key() {
		return $this->test_mode()
			? $this->gateway_setting( 'paystack', 'test_secret_key' )
			: $this->gateway_setting( 'paystack', 'live_secret_key' );
	}

	public function amount_for_widget( $total ) {
		return (int) round( (float) $total * 100 );
	}

	public function verify( $reference, WC_Order $order ) {
		$secret = $this->secret_key();

		if ( ! $secret ) {
			return new WP_Error( 'wcic_config', __( 'Paystack secret key is not configured.', 'wc-inline-checkout' ) );
		}

		$body = $this->get(
			'https://api.paystack.co/transaction/verify/' . rawurlencode( $reference ),
			array(
				'Authorization' => 'Bearer ' . $secret,
				'Cache-Control' => 'no-cache',
			)
		);

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		if ( empty( $body['status'] ) || empty( $body['data'] ) ) {
			return new WP_Error( 'wcic_declined', __( 'Paystack did not recognise this transaction.', 'wc-inline-checkout' ) );
		}

		$data = $body['data'];

		if ( 'success' !== ( $data['status'] ?? '' ) ) {
			return new WP_Error(
				'wcic_declined',
				sprintf(
					/* translators: %s: transaction status reported by the gateway */
					__( 'Paystack reported this transaction as %s.', 'wc-inline-checkout' ),
					sanitize_text_field( (string) ( $data['status'] ?? 'unknown' ) )
				)
			);
		}

		$currency = strtoupper( (string) ( $data['currency'] ?? '' ) );

		if ( $currency !== strtoupper( $order->get_currency() ) ) {
			return new WP_Error( 'wcic_currency', __( 'The payment currency does not match the order.', 'wc-inline-checkout' ) );
		}

		$paid     = (float) ( $data['amount'] ?? 0 );
		$expected = (float) $order->get_total();

		if ( in_array( $currency, $this->subunit_currencies, true ) ) {
			$paid = $paid / 100;
		}

		if ( ! $this->amounts_match( $paid, $expected ) ) {
			return new WP_Error( 'wcic_amount', __( 'The amount paid does not match the order total.', 'wc-inline-checkout' ) );
		}

		return array( 'gateway_reference' => sanitize_text_field( (string) ( $data['reference'] ?? $reference ) ) );
	}
}

/**
 * Flutterwave, which WooCommerce installs under the gateway id "rave".
 */
class WCIC_Flutterwave_Verifier extends WCIC_Verifier {

	public function slug() {
		return 'flutterwave';
	}

	private function live_mode() {
		return 'yes' === $this->gateway_setting( 'rave', 'go_live' );
	}

	public function public_key() {
		return $this->live_mode()
			? $this->gateway_setting( 'rave', 'live_public_key' )
			: $this->gateway_setting( 'rave', 'test_public_key' );
	}

	protected function secret_key() {
		return $this->live_mode()
			? $this->gateway_setting( 'rave', 'live_secret_key' )
			: $this->gateway_setting( 'rave', 'test_secret_key' );
	}

	public function verify( $reference, WC_Order $order ) {
		$secret = $this->secret_key();

		if ( ! $secret ) {
			return new WP_Error( 'wcic_config', __( 'Flutterwave secret key is not configured.', 'wc-inline-checkout' ) );
		}

		$body = $this->get(
			add_query_arg( 'tx_ref', rawurlencode( $reference ), 'https://api.flutterwave.com/v3/transactions/verify_by_reference' ),
			array(
				'Authorization' => 'Bearer ' . $secret,
				'Content-Type'  => 'application/json',
			)
		);

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		if ( 'success' !== ( $body['status'] ?? '' ) || empty( $body['data'] ) ) {
			return new WP_Error( 'wcic_declined', __( 'Flutterwave did not recognise this transaction.', 'wc-inline-checkout' ) );
		}

		$data = $body['data'];

		if ( 'successful' !== ( $data['status'] ?? '' ) ) {
			return new WP_Error(
				'wcic_declined',
				sprintf(
					/* translators: %s: transaction status reported by the gateway */
					__( 'Flutterwave reported this transaction as %s.', 'wc-inline-checkout' ),
					sanitize_text_field( (string) ( $data['status'] ?? 'unknown' ) )
				)
			);
		}

		if ( strtoupper( (string) ( $data['currency'] ?? '' ) ) !== strtoupper( $order->get_currency() ) ) {
			return new WP_Error( 'wcic_currency', __( 'The payment currency does not match the order.', 'wc-inline-checkout' ) );
		}

		// Flutterwave reports both amount and amount_settled. Compare against
		// charged_amount where present, since settlement is net of fees.
		$paid = isset( $data['charged_amount'] ) ? (float) $data['charged_amount'] : (float) ( $data['amount'] ?? 0 );

		if ( ! $this->amounts_match( $paid, $order->get_total() ) ) {
			return new WP_Error( 'wcic_amount', __( 'The amount paid does not match the order total.', 'wc-inline-checkout' ) );
		}

		return array( 'gateway_reference' => sanitize_text_field( (string) ( $data['tx_ref'] ?? $reference ) ) );
	}
}
