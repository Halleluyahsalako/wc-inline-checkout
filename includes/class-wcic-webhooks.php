<?php
/**
 * Gateway webhooks.
 *
 * @package WC_Inline_Checkout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Receives server to server notifications from Paystack and Flutterwave.
 *
 * A webhook tells you something happened. It does not tell you what. Every
 * handler here authenticates the sender, then throws the payload away and asks
 * the gateway API directly, which is the only answer this plugin accepts.
 */
class WCIC_Webhooks {

	const EVENT_TABLE = 'wcic_events';

	/**
	 * Hook the REST routes. REST is used rather than a rewrite rule so there
	 * are no permalinks to flush and no chance of a page slug colliding.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'wcic_prune_events', array( __CLASS__, 'prune_events' ) );

		if ( ! wp_next_scheduled( 'wcic_prune_events' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wcic_prune_events' );
		}
	}

	public static function register_routes() {
		register_rest_route(
			'wcic/v1',
			'/webhook/(?P<gateway>paystack|flutterwave)',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true', // Authenticated by signature below.
				'args'                => array(
					'gateway' => array(
						'validate_callback' => function ( $value ) {
							return in_array( $value, array( 'paystack', 'flutterwave' ), true );
						},
					),
				),
			)
		);
	}

	/**
	 * The URL to paste into a gateway dashboard.
	 *
	 * @param string $gateway paystack|flutterwave.
	 * @return string
	 */
	public static function url( $gateway ) {
		return rest_url( 'wcic/v1/webhook/' . $gateway );
	}

	/**
	 * Entry point.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ) {

		$gateway = $request->get_param( 'gateway' );
		$raw     = $request->get_body();

		if ( '' === $raw ) {
			return new WP_REST_Response( array( 'message' => 'Empty body.' ), 400 );
		}

		$authentic = ( 'paystack' === $gateway )
			? self::authenticate_paystack( $raw, $request )
			: self::authenticate_flutterwave( $request );

		if ( is_wp_error( $authentic ) ) {
			return new WP_REST_Response(
				array( 'message' => $authentic->get_error_message() ),
				(int) ( $authentic->get_error_data() ?: 401 )
			);
		}

		$event = json_decode( $raw, true );

		if ( ! is_array( $event ) ) {
			return new WP_REST_Response( array( 'message' => 'Unreadable payload.' ), 400 );
		}

		// An event we have already handled is answered, not reprocessed. Both
		// gateways resend until they get a 200, so duplicates are routine.
		$event_id = self::event_id( $gateway, $event );

		if ( $event_id && self::already_seen( $event_id ) ) {
			return new WP_REST_Response( array( 'message' => 'Already processed.' ), 200 );
		}

		self::process( $gateway, $event );

		return new WP_REST_Response( array( 'message' => 'OK' ), 200 );
	}

	/**
	 * Paystack signs the raw body with HMAC SHA512 using the secret key, so the
	 * signature is evidence about this specific payload.
	 *
	 * @param string          $raw     Raw request body.
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	private static function authenticate_paystack( $raw, WP_REST_Request $request ) {

		$sent = (string) $request->get_header( 'x_paystack_signature' );

		if ( '' === $sent ) {
			return new WP_Error( 'wcic_no_signature', __( 'Missing signature.', 'inline-checkout-for-woocommerce' ), 401 );
		}

		$verifier = WCIC_Verifier::for_gateway( 'paystack' );

		if ( ! $verifier instanceof WCIC_Paystack_Verifier ) {
			return new WP_Error( 'wcic_no_gateway', __( 'Paystack is not configured.', 'inline-checkout-for-woocommerce' ), 503 );
		}

		if ( ! $verifier->is_configured() ) {
			return new WP_Error( 'wcic_no_secret', __( 'Paystack secret key is not configured.', 'inline-checkout-for-woocommerce' ), 503 );
		}

		if ( ! $verifier->signature_is_valid( $raw, $sent ) ) {
			return new WP_Error( 'wcic_bad_signature', __( 'Signature does not match.', 'inline-checkout-for-woocommerce' ), 401 );
		}

		return true;
	}

	/**
	 * Flutterwave sends back the secret hash you typed into its dashboard,
	 * unchanged, on every request. It proves the sender knows a password. It
	 * proves nothing about the body, which is why process() re-queries the API
	 * rather than reading amounts out of the payload.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	private static function authenticate_flutterwave( WP_REST_Request $request ) {

		$expected = (string) get_option( 'wcic_flutterwave_secret_hash', '' );

		// With no hash configured, an empty comparison would accept anything.
		// Refusing is the only safe default.
		if ( '' === $expected ) {
			return new WP_Error( 'wcic_no_secret', __( 'No Flutterwave secret hash is configured.', 'inline-checkout-for-woocommerce' ), 503 );
		}

		$sent = (string) $request->get_header( 'verif_hash' );

		if ( '' === $sent || ! hash_equals( $expected, $sent ) ) {
			return new WP_Error( 'wcic_bad_signature', __( 'Secret hash does not match.', 'inline-checkout-for-woocommerce' ), 401 );
		}

		return true;
	}

	/**
	 * Identify the event so a repeat delivery can be recognised.
	 *
	 * @param string $gateway Gateway slug.
	 * @param array  $event   Decoded payload.
	 * @return string
	 */
	private static function event_id( $gateway, array $event ) {

		$data = isset( $event['data'] ) && is_array( $event['data'] ) ? $event['data'] : array();
		$id   = (string) ( $data['id'] ?? '' );
		$name = (string) ( $event['event'] ?? 'event' );

		return ( '' === $id ) ? '' : $gateway . ':' . $name . ':' . $id;
	}

	/**
	 * Atomic test and set. The unique index does the locking, so two deliveries
	 * arriving together cannot both win.
	 *
	 * @param string $event_id Composite event identifier.
	 * @return bool True when this event has been seen before.
	 */
	private static function already_seen( $event_id ) {

		global $wpdb;

		$table = $wpdb->prefix . self::EVENT_TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO `{$table}` ( event_id, received_at ) VALUES ( %s, %s )",
				$event_id,
				current_time( 'mysql', true )
			)
		);

		return 0 === (int) $inserted;
	}

	/**
	 * Find the order, then verify against the API. The payload is used only to
	 * work out which order and which transaction this is about.
	 *
	 * @param string $gateway Gateway slug.
	 * @param array  $event   Decoded payload.
	 * @return void
	 */
	private static function process( $gateway, array $event ) {

		$data = isset( $event['data'] ) && is_array( $event['data'] ) ? $event['data'] : array();

		$reference = (string) ( $data['reference'] ?? $data['tx_ref'] ?? '' );

		if ( '' === $reference ) {
			return;
		}

		$order = self::order_for_reference( $reference );

		if ( ! $order || $order->is_paid() ) {
			return;
		}

		$verifier = WCIC_Verifier::for_gateway( (string) $order->get_meta( '_wcic_gateway' ) );

		if ( ! $verifier instanceof WCIC_Verifier ) {
			return;
		}

		$result = $verifier->verify( $reference, $order );

		if ( is_wp_error( $result ) ) {
			// A transport failure is not a declined payment. Leave the order be
			// and let the next delivery, or the customer's own return, settle it.
			if ( 'wcic_http' !== $result->get_error_code() ) {
				$order->add_order_note(
					sprintf(
						/* translators: %s: reason the verification failed */
						__( 'Webhook verification failed: %s', 'inline-checkout-for-woocommerce' ),
						$result->get_error_message()
					)
				);
			}
			return;
		}

		if ( ! empty( $result['overpaid'] ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: amount expected, 2: amount received */
					__( 'Overpayment. Expected %1$s, received %2$s. Consider refunding the difference.', 'inline-checkout-for-woocommerce' ),
					wc_price( $result['expected'] ),
					wc_price( $result['paid'] )
				)
			);
		}

		$order->add_order_note( __( 'Payment confirmed by gateway webhook.', 'inline-checkout-for-woocommerce' ) );
		$order->payment_complete( $result['gateway_reference'] );
	}

	/**
	 * Look an order up by the reference this plugin issued for it.
	 *
	 * @param string $reference Reference.
	 * @return WC_Order|null
	 */
	private static function order_for_reference( $reference ) {

		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'return'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'   => '_wcic_reference',
						'value' => $reference,
					),
				),
			)
		);

		if ( empty( $orders ) ) {
			return null;
		}

		$order = wc_get_order( $orders[0] );

		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * Create the dedupe table. Called on activation.
	 */
	public static function install() {

		global $wpdb;

		$table   = $wpdb->prefix . self::EVENT_TABLE;
		$collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE `{$table}` (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				event_id VARCHAR(191) NOT NULL,
				received_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY event_id (event_id),
				KEY received_at (received_at)
			) {$collate};"
		);
	}

	/**
	 * Both gateways stop retrying long before thirty days.
	 */
	public static function prune_events() {

		global $wpdb;

		$table = $wpdb->prefix . self::EVENT_TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			"DELETE FROM `{$table}` WHERE received_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL 30 DAY )"
		);
	}
}
