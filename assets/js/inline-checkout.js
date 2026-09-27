/**
 * Inline Checkout for WooCommerce
 *
 * Buy Now, AJAX add to cart, and in page payment for Paystack and Flutterwave.
 *
 * The gateway widgets return a reference to the browser, but nothing here
 * decides whether an order is paid. That happens server side in
 * WCIC_Verifier, which calls the gateway API directly.
 */
( function ( $ ) {
	'use strict';

	var vars = window.wcicVars || {};
	var restoringShipping = false;

	function isProductPage() {
		return $( 'body' ).hasClass( 'single-product' );
	}

	function escapeHtml( value ) {
		return $( '<div/>' ).text( value == null ? '' : value ).html();
	}

	function blockEl( $el, message ) {
		if ( ! $.fn.block ) {
			return;
		}
		$el.block( {
			message: message || null,
			overlayCSS: { background: '#fff', opacity: 0.6 }
		} );
	}

	function unblockEl( $el ) {
		if ( $.fn.unblock ) {
			$el.unblock();
		}
	}

	/* ---------------------------------------------------------------------
	 * Cart notification
	 * ------------------------------------------------------------------- */

	function showCartNotification( productName ) {
		$( '.wcic-notice' ).remove();

		var html =
			'<div class="wcic-notice" role="status" aria-live="polite">' +
				'<div class="wcic-notice__inner">' +
					'<div class="wcic-notice__head">' +
						'<svg class="wcic-notice__icon" viewBox="0 0 52 52" aria-hidden="true">' +
							'<circle cx="26" cy="26" r="25" fill="none"/>' +
							'<path fill="none" d="M14.1 27.2l7.1 7.2 16.7-16.8"/>' +
						'</svg>' +
						'<h3>' + escapeHtml( 'Added to cart' ) + '</h3>' +
						'<button type="button" class="wcic-notice__close" aria-label="Close">&times;</button>' +
					'</div>' +
					'<p class="wcic-notice__product">' + escapeHtml( productName ) + '</p>' +
					'<div class="wcic-notice__actions">' +
						'<a href="' + escapeHtml( vars.cartUrl || '/cart' ) + '" class="wcic-notice__cart">View cart</a>' +
						'<a href="' + escapeHtml( vars.checkoutUrl || '/checkout' ) + '" class="wcic-notice__checkout">Checkout</a>' +
					'</div>' +
					'<button type="button" class="wcic-notice__continue">Continue shopping</button>' +
				'</div>' +
			'</div>';

		var $notice = $( html ).appendTo( 'body' );

		window.requestAnimationFrame( function () {
			$notice.addClass( 'is-visible' );
		} );

		var hideTimer = window.setTimeout( hideCartNotification, 8000 );

		$notice.on( 'click', '.wcic-notice__close, .wcic-notice__continue', function ( e ) {
			e.preventDefault();
			window.clearTimeout( hideTimer );
			hideCartNotification();
		} );
	}

	function hideCartNotification() {
		var $notice = $( '.wcic-notice' ).removeClass( 'is-visible' );
		window.setTimeout( function () {
			$notice.remove();
		}, 300 );
	}

	/* ---------------------------------------------------------------------
	 * Buy Now
	 * ------------------------------------------------------------------- */

	$( document ).on( 'click', '.wcic-buy-now', function ( e ) {
		e.preventDefault();
		e.stopImmediatePropagation();

		var $button = $( this );
		var $container = $( '#wcic-container' );

		$button.addClass( 'loading' );

		$.post( vars.ajaxUrl, {
			action: 'wcic_buy_now',
			nonce: vars.nonce,
			product_id: $button.data( 'product-id' ),
			quantity: $( 'form.cart' ).find( 'input.qty' ).val() || 1
		} )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					window.alert( ( response && response.data && response.data.message ) || vars.i18n.genericError );
					return;
				}

				$( '#wcic-form-wrapper' ).html( response.data.checkout_html );
				$container.prop( 'hidden', false );

				$( 'html, body' ).animate( { scrollTop: $container.offset().top - 80 }, 400 );

				$( document.body ).trigger( 'wcic_checkout_loaded' );
				bindCheckoutFields();
				bindPaymentInterception();
			} )
			.fail( function () {
				window.alert( vars.i18n.genericError );
			} )
			.always( function () {
				$button.removeClass( 'loading' );
			} );
	} );

	/* ---------------------------------------------------------------------
	 * Recalculate totals when address or shipping changes
	 * ------------------------------------------------------------------- */

	function bindCheckoutFields() {
		var $wrapper = $( '#wcic-form-wrapper' );
		var timer = null;

		function debounced() {
			window.clearTimeout( timer );
			timer = window.setTimeout( updateOrderReview, 700 );
		}

		$wrapper
			.off( '.wcic' )
			.on( 'change.wcic', 'input[name^="billing_"], select[name^="billing_"]', debounced )
			.on( 'change.wcic', 'input[name^="shipping_method"]', function () {
				if ( ! restoringShipping ) {
					updateOrderReview();
				}
			} );
	}

	function updateOrderReview() {
		var $form = $( '#wcic-form-wrapper' ).find( 'form.checkout, .woocommerce-checkout' ).first();

		if ( ! $form.length ) {
			return;
		}

		var chosenShipping = $form.find( 'input[name^="shipping_method"]:checked' ).val();
		var $review = $form.find( '.woocommerce-checkout-review-order' );

		blockEl( $review );

		$.post( vars.ajaxUrl, {
			action: 'wcic_update_order_review',
			security: vars.reviewNonce,
			post_data: $form.serialize()
		} )
			.done( function ( response ) {
				if ( ! response || 'success' !== response.result || ! response.fragments ) {
					return;
				}

				if ( response.fragments['.woocommerce-checkout-review-order-table'] ) {
					$form
						.find( '.woocommerce-checkout-review-order-table' )
						.replaceWith( response.fragments['.woocommerce-checkout-review-order-table'] );

					// Replacing the table wipes the radio selection, so put it
					// back without letting the change handler fire and loop.
					if ( chosenShipping ) {
						restoringShipping = true;
						$form
							.find( 'input[name^="shipping_method"][value="' + chosenShipping + '"]' )
							.prop( 'checked', true );
						window.setTimeout( function () {
							restoringShipping = false;
						}, 100 );
					}
				}

				if ( response.fragments['.woocommerce-checkout-payment'] ) {
					$form
						.find( '.woocommerce-checkout-payment' )
						.replaceWith( response.fragments['.woocommerce-checkout-payment'] );

					bindPaymentInterception();
					bindCheckoutFields();
				}
			} )
			.always( function () {
				unblockEl( $review );
			} );
	}

	/* ---------------------------------------------------------------------
	 * AJAX add to cart on the product page
	 * ------------------------------------------------------------------- */

	if ( isProductPage() ) {
		$( document ).on( 'submit.wcic', 'form.cart', function ( e ) {
			if ( $( document.activeElement ).hasClass( 'wcic-buy-now' ) ) {
				return false;
			}

			e.preventDefault();
			e.stopImmediatePropagation();

			var $form = $( this );
			var $button = $form.find( 'button[name="add-to-cart"], button.single_add_to_cart_button' );
			var productName = $( '.product_title' ).first().text() || 'Product';

			var data = {
				product_id: $button.val() || $form.find( 'input[name="add-to-cart"]' ).val(),
				quantity: $form.find( 'input.qty' ).val() || 1,
				variation_id: $form.find( 'input[name="variation_id"]' ).val() || 0
			};

			$form.find( 'select[name^="attribute_"]' ).each( function () {
				data[ $( this ).attr( 'name' ) ] = $( this ).val();
			} );

			$button.removeClass( 'added' ).addClass( 'loading' );

			var url =
				typeof window.wc_add_to_cart_params !== 'undefined' && window.wc_add_to_cart_params.wc_ajax_url
					? window.wc_add_to_cart_params.wc_ajax_url.toString().replace( '%%endpoint%%', 'add_to_cart' )
					: '?wc-ajax=add_to_cart';

			$.post( url, data )
				.done( function ( response ) {
					if ( ! response ) {
						return;
					}

					if ( response.error && response.product_url ) {
						window.location = response.product_url;
						return;
					}

					if ( response.fragments ) {
						$.each( response.fragments, function ( key, value ) {
							$( key ).replaceWith( value );
						} );
					}

					$( document.body ).trigger( 'added_to_cart', [ response.fragments, response.cart_hash, $button ] );

					$button.addClass( 'added' );
					showCartNotification( productName );
				} )
				.fail( function () {
					window.alert( vars.i18n.genericError );
				} )
				.always( function () {
					$button.removeClass( 'loading' );
				} );

			return false;
		} );
	}

	/* ---------------------------------------------------------------------
	 * Payment
	 * ------------------------------------------------------------------- */

	function bindPaymentInterception() {
		$( document ).off( 'submit.wcicPayment' );

		$( document ).on( 'submit.wcicPayment', 'form.checkout, form.woocommerce-checkout', function ( e ) {
			var $form = $( this );
			var method = $( 'input[name="payment_method"]:checked', $form ).val();

			// Anything this plugin does not render inline goes through
			// WooCommerce's normal flow untouched.
			if ( 'paystack' !== method && 'rave' !== method ) {
				return true;
			}

			e.preventDefault();
			e.stopImmediatePropagation();

			$form.addClass( 'processing' );
			blockEl( $form );

			$.post( vars.wcAjaxUrl.replace( '%%endpoint%%', 'checkout' ), $form.serialize() )
				.done( function ( result ) {
					if ( ! result || 'success' !== result.result || ! result.order_id ) {
						showError( $form, ( result && result.messages ) || vars.i18n.genericError );
						releaseForm( $form );
						return;
					}

					requestPaymentData( result.order_id, method, $form );
				} )
				.fail( function () {
					showError( $form, vars.i18n.genericError );
					releaseForm( $form );
				} );

			return false;
		} );
	}

	function requestPaymentData( orderId, method, $form ) {
		$.post( vars.ajaxUrl, {
			action: 'wcic_payment_data',
			nonce: vars.nonce,
			order_id: orderId,
			payment_method: method
		} )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					showError( $form, ( response && response.data && response.data.message ) || vars.i18n.genericError );
					releaseForm( $form );
					return;
				}

				if ( 'paystack' === response.data.gateway ) {
					withScript( 'https://js.paystack.co/v1/inline.js', 'PaystackPop', function () {
						openPaystack( response.data, $form );
					} );
				} else {
					withScript( 'https://checkout.flutterwave.com/v3.js', 'FlutterwaveCheckout', function () {
						openFlutterwave( response.data, $form );
					} );
				}
			} )
			.fail( function () {
				showError( $form, vars.i18n.genericError );
				releaseForm( $form );
			} );
	}

	function withScript( src, globalName, callback ) {
		if ( typeof window[ globalName ] !== 'undefined' ) {
			callback();
			return;
		}

		var script = document.createElement( 'script' );
		script.src = src;
		script.onload = callback;
		document.head.appendChild( script );
	}

	function openPaystack( data, $form ) {
		window.PaystackPop.setup( {
			key: data.publicKey,
			email: data.email,
			amount: data.amount,
			currency: data.currency,
			ref: data.reference,
			callback: function () {
				// The reference we issued is what the server checks, so the
				// gateway's own response is not needed here.
				verifyPayment( data.orderId, data.reference, $form );
			},
			onClose: function () {
				releaseForm( $form );
			}
		} ).openIframe();
	}

	function openFlutterwave( data, $form ) {
		window.FlutterwaveCheckout( {
			public_key: data.publicKey,
			tx_ref: data.reference,
			amount: data.amount,
			currency: data.currency,
			payment_options: 'card,banktransfer,ussd',
			customer: {
				email: data.email,
				name: data.customerName
			},
			customizations: {
				title: data.siteName,
				description: 'Order #' + data.orderId
			},
			callback: function () {
				verifyPayment( data.orderId, data.reference, $form );
			},
			onclose: function () {
				releaseForm( $form );
			}
		} );
	}

	function verifyPayment( orderId, reference, $form ) {
		blockEl( $form, vars.i18n.verifying );

		$.post( vars.ajaxUrl, {
			action: 'wcic_verify_payment',
			nonce: vars.nonce,
			order_id: orderId,
			reference: reference
		} )
			.done( function ( response ) {
				if ( response && response.success && response.data.redirect ) {
					window.location.href = response.data.redirect;
					return;
				}

				showError( $form, ( response && response.data && response.data.message ) || vars.i18n.genericError );
				releaseForm( $form );
			} )
			.fail( function () {
				showError( $form, vars.i18n.genericError );
				releaseForm( $form );
			} );
	}

	function releaseForm( $form ) {
		$form.removeClass( 'processing' );
		unblockEl( $form );
	}

	function showError( $form, message ) {
		$( '.woocommerce-error, .woocommerce-message' ).remove();

		$( '<div class="woocommerce-error" role="alert"></div>' )
			.html( message )
			.prependTo( $form );

		$( 'html, body' ).animate( { scrollTop: $form.offset().top - 100 }, 400 );
	}

	/* ---------------------------------------------------------------------
	 * Init
	 * ------------------------------------------------------------------- */

	$( function () {
		bindPaymentInterception();

		$( document.body ).on( 'updated_checkout', function () {
			bindPaymentInterception();
		} );
	} );
} )( jQuery );
