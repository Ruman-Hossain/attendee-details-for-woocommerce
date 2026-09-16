<?php
/**
 * Front end: render the attendee fields on checkout and validate them.
 *
 * Nothing in this class runs unless the plugin is enabled AND the basket
 * actually contains a selected product — see the guard at the top of render().
 */

defined( 'ABSPATH' ) || exit;

class CQ_Attendee_Checkout {

	/** Hidden marker posted with the form, proving the fields were on the page. */
	const RENDERED_FLAG = 'cq_att_rendered';

	/** Guards against drawing the fields twice if both hooks below fire. */
	private $rendered = false;

	public function __construct() {
		// Fields sit above the order notes box, inside the main checkout column.
		// Deliberately NOT inside the order review panel, which WooCommerce
		// redraws by AJAX — anything in there would lose what the customer typed.
		add_action( 'woocommerce_before_order_notes', array( $this, 'render' ), 10, 1 );

		// Fallback, for a checkout that does not fire the hook above — some page
		// builders and custom templates skip it. Whichever fires first wins; the
		// other sees $rendered and does nothing.
		add_action( 'woocommerce_checkout_after_customer_details', array( $this, 'render' ), 10, 1 );

		add_action( 'woocommerce_checkout_process', array( $this, 'validate' ) );

		// Late priority on purpose: it has to run after whatever relabelled the
		// order notes box, or it would be overwritten again straight afterwards.
		add_filter( 'woocommerce_checkout_fields', array( $this, 'order_notes_mode' ), 99 );

		// Optional guard: hide the wallet buttons on the cart page when a
		// selected product is present, so the checkout form can't be skipped.
		add_action( 'wp_head', array( $this, 'maybe_hide_cart_wallets' ), 99 );
	}

	/**
	 * Deal with an order-notes box that has been repurposed to ask for the same
	 * attendee details this plugin now collects properly.
	 *
	 * "reset" puts WooCommerce's own wording back; "hide" removes the box.
	 * Either way nothing is edited anywhere else — the field is filtered on its
	 * way to the screen, and switching this back to "leave" restores whatever
	 * the shop had before.
	 *
	 * Only applies when the basket actually holds a selected product, so every
	 * other checkout keeps its notes box exactly as it is.
	 */
	public function order_notes_mode( $fields ) {
		$s = cq_attendee_get_settings();

		if ( 'leave' === $s['order_notes_mode'] || ! is_array( $fields ) ) {
			return $fields;
		}
		if ( empty( $fields['order']['order_comments'] ) ) {
			return $fields;
		}
		if ( ! cq_attendee_cart_has_applicable() ) {
			return $fields;
		}

		if ( 'hide' === $s['order_notes_mode'] ) {
			unset( $fields['order']['order_comments'] );
			return $fields;
		}

		// Deliberately this plugin's own text domain, not WooCommerce's.
		// Borrowing another plugin's domain is against the WordPress.org
		// guidelines, and it breaks the moment that plugin changes its strings.
		$fields['order']['order_comments']['label']       = __( 'Order notes', 'attendee-details-for-woocommerce' );
		$fields['order']['order_comments']['placeholder'] = __( 'Notes about your order, e.g. special notes for delivery.', 'attendee-details-for-woocommerce' );
		$fields['order']['order_comments']['required']    = false;

		return $fields;
	}

	/**
	 * Render one block per place booked.
	 */
	public function render( $checkout = null ) {
		if ( $this->rendered ) {
			return; // Already drawn by the other hook.
		}

		$lines = cq_attendee_lines_from_cart();
		if ( ! $lines ) {
			return; // Nothing applicable in the basket — output nothing at all.
		}

		$this->rendered = true;

		$s        = cq_attendee_get_settings();
		$posted   = $this->posted_values();
		$required = ( 'yes' === $s['required'] );

		$this->inline_styles();

		echo '<div class="cq-attendee-wrap" id="cq-attendee-wrap">';

		// Proof that the fields reached the page. Validation refuses to reject an
		// order unless this comes back with the form — see validate().
		echo '<input type="hidden" name="' . esc_attr( self::RENDERED_FLAG ) . '" value="1" />';

		if ( ! empty( $s['heading'] ) ) {
			echo '<h3 class="cq-attendee-heading">' . esc_html( $s['heading'] ) . '</h3>';
		}
		if ( ! empty( $s['intro'] ) ) {
			echo '<p class="cq-attendee-intro">' . esc_html( $s['intro'] ) . '</p>';
		}

		// The rules-of-entry warning. Its job is to make people give real
		// details rather than type the booker's name three times.
		if ( ! empty( $s['notice'] ) ) {
			echo '<p class="cq-attendee-notice">' . esc_html( $s['notice'] ) . '</p>';
		}

		foreach ( $lines as $key => $line ) {
			echo '<div class="cq-attendee-line">';
			echo '<p class="cq-attendee-course">' . esc_html( $line['name'] ) . '</p>';

			for ( $i = 1; $i <= $line['places']; $i++ ) {
				// Only pre-fill on the very first draw. If the customer has
				// already submitted once, whatever they typed wins — including
				// a box they deliberately cleared.
				$this->render_block( $key, $i, $line['places'], $posted, $s, $required, empty( $posted ) );
			}

			echo '</div>';
		}

		echo '</div>';

		$this->inline_script();
	}

	/**
	 * One attendee: name, and optionally mobile and email.
	 */
	private function render_block( $key, $i, $places, $posted, $s, $required, $first_render = false ) {
		$base = 'cq_att[' . esc_attr( $key ) . '][' . absint( $i ) . ']';
		$id   = 'cq_att_' . sanitize_html_class( $key ) . '_' . absint( $i );

		$label = ( $places > 1 )
			? sprintf( 'Attendee %d of %d', $i, $places )
			: 'Attendee details';

		echo '<fieldset class="cq-attendee-block">';
		echo '<legend class="cq-attendee-legend">' . esc_html( $label ) . '</legend>';

		// A convenience tick on the first attendee only.
		//
		// The booking already carries ONE name — the billing name of whoever is
		// paying. Very often that person is also attendee 1, so this copies it
		// across instead of making them type it twice. It is a copy, not a link:
		// they can overwrite it, which matters when the buyer is an office
		// manager who is not attending at all.
		if ( 1 === $i ) {
			echo '<label class="cq-attendee-sameas"><input type="checkbox" class="cq-attendee-sameas-input" id="' . esc_attr( $id ) . '_same" /> <span>Same as my billing details</span></label>';
		}

		// Pre-fill attendee 1 from the billing details WooCommerce already holds
		// for this customer, so the one name the booking always has is not asked
		// for twice. Blank for a brand-new guest — the tick box above covers that.
		$prefill = ( 1 === $i && $first_render && 'yes' === $s['prefill_billing'] );

		$this->field(
			$base . '[name]',
			$id . '_name',
			'Full name',
			$this->value( $posted, $key, $i, 'name', $prefill ? $this->billing_default( 'name' ) : '' ),
			$required,
			'text',
			'cq-att-name'
		);

		if ( 'yes' === $s['collect_mobile'] ) {
			$this->field(
				$base . '[mobile]',
				$id . '_mobile',
				'Mobile number',
				$this->value( $posted, $key, $i, 'mobile', $prefill ? $this->billing_default( 'phone' ) : '' ),
				$required,
				'tel',
				'cq-att-mobile'
			);
		}

		if ( 'yes' === $s['collect_email'] ) {
			$this->field(
				$base . '[email]',
				$id . '_email',
				'Email address',
				$this->value( $posted, $key, $i, 'email', $prefill ? $this->billing_default( 'email' ) : '' ),
				$required,
				'email',
				'cq-att-email'
			);
		}

		echo '</fieldset>';
	}

	private function field( $name, $id, $label, $value, $required, $type, $class ) {
		echo '<p class="form-row cq-attendee-row">';
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label );
		if ( $required ) {
			echo ' <abbr class="required" title="required">*</abbr>';
		}
		echo '</label>';
		echo '<span class="woocommerce-input-wrapper">';
		echo '<input type="' . esc_attr( $type ) . '" class="input-text ' . esc_attr( $class ) . '" name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '" autocomplete="off" />';
		echo '</span>';
		echo '</p>';
	}

	/**
	 * Raw posted values, used to repopulate the form when validation fails so
	 * the customer never has to retype everything.
	 */
	private function posted_values() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only repopulation; WooCommerce verifies its own checkout nonce.
		return isset( $_POST['cq_att'] ) && is_array( $_POST['cq_att'] ) ? wp_unslash( $_POST['cq_att'] ) : array();
	}

	private function value( $posted, $key, $i, $field, $fallback = '' ) {
		if ( isset( $posted[ $key ][ $i ][ $field ] ) && is_scalar( $posted[ $key ][ $i ][ $field ] ) ) {
			return sanitize_text_field( $posted[ $key ][ $i ][ $field ] );
		}
		return $fallback;
	}

	/**
	 * Billing details WooCommerce already knows, used only as a starting value.
	 * Returns '' for a guest who has typed nothing yet.
	 */
	private function billing_default( $what ) {
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return '';
		}
		$c = WC()->customer;

		if ( 'name' === $what ) {
			$full = trim( $c->get_billing_first_name() . ' ' . $c->get_billing_last_name() );
			return sanitize_text_field( $full );
		}
		if ( 'phone' === $what ) {
			return sanitize_text_field( $c->get_billing_phone() );
		}
		if ( 'email' === $what ) {
			$email = $c->get_billing_email();
			return is_email( $email ) ? sanitize_text_field( $email ) : '';
		}
		return '';
	}

	/**
	 * Block the order if anything required is missing or malformed.
	 * Rebuilds the expected shape from the live cart, never from the POST.
	 */
	public function validate() {
		$s = cq_attendee_get_settings();
		if ( 'yes' !== $s['required'] ) {
			return; // Collecting only — never stand between a customer and paying.
		}

		// THE IMPORTANT SAFETY CHECK.
		//
		// Validation and rendering happen in two different requests, and they can
		// disagree: if a theme or page builder never fires the hook the fields are
		// drawn on, the customer sees no boxes — but this would still demand they
		// be filled in, and the order could never be placed at all.
		//
		// So an order is only ever rejected when the form actually reached the
		// page and said so. Missing marker means the fields were not shown, and
		// the customer is let through rather than trapped.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this runs.
		if ( empty( $_POST[ self::RENDERED_FLAG ] ) ) {
			return;
		}

		$lines = cq_attendee_lines_from_cart();
		if ( ! $lines ) {
			return;
		}

		$posted = $this->posted_values();

		foreach ( $lines as $key => $line ) {
			for ( $i = 1; $i <= $line['places']; $i++ ) {

				$who = ( $line['places'] > 1 )
					? sprintf( 'attendee %d of %d', $i, $line['places'] )
					: 'the attendee';

				$name = isset( $posted[ $key ][ $i ]['name'] ) ? trim( sanitize_text_field( $posted[ $key ][ $i ]['name'] ) ) : '';
				if ( '' === $name ) {
					/* translators: 1: attendee position, 2: course name */
					wc_add_notice( sprintf( 'Please enter the full name for %1$s on %2$s.', $who, $line['name'] ), 'error' );
				}

				if ( 'yes' === $s['collect_mobile'] ) {
					$mobile = isset( $posted[ $key ][ $i ]['mobile'] ) ? trim( sanitize_text_field( $posted[ $key ][ $i ]['mobile'] ) ) : '';
					$digits = preg_replace( '/\D+/', '', $mobile );
					if ( '' === $mobile || strlen( $digits ) < 7 ) {
						wc_add_notice( sprintf( 'Please enter a valid mobile number for %1$s on %2$s.', $who, $line['name'] ), 'error' );
					}
				}

				if ( 'yes' === $s['collect_email'] ) {
					$email = isset( $posted[ $key ][ $i ]['email'] ) ? trim( sanitize_text_field( $posted[ $key ][ $i ]['email'] ) ) : '';
					if ( '' === $email || ! is_email( $email ) ) {
						wc_add_notice( sprintf( 'Please enter a valid email address for %1$s on %2$s.', $who, $line['name'] ), 'error' );
					}
				}
			}
		}
	}

	/**
	 * Hide Apple Pay / Google Pay buttons on the CART page only, and only when
	 * the basket holds a selected product. Those buttons create an order without
	 * ever loading the checkout page, which would skip these fields entirely.
	 *
	 * CSS only, on purpose: if a class name ever changes the buttons simply
	 * reappear. Nothing breaks.
	 */
	public function maybe_hide_cart_wallets() {
		$s = cq_attendee_get_settings();
		if ( 'yes' !== $s['hide_wallet_cart'] ) {
			return;
		}
		if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
			return;
		}
		if ( ! cq_attendee_cart_has_applicable() ) {
			return;
		}
		// The container is the primary target: on Payment Plugins' Stripe it holds
		// the "— or —" divider AND both buttons, and nothing else — the Proceed to
		// Checkout button is a sibling, not a child. Hiding only the buttons leaves
		// a stranded "— or —" under the checkout button.
		//
		// The individual selectors stay as a fallback for other gateways and for
		// older markup. Verified against this markup on 16 Sep 2026.
		echo '<style id="cq-attendee-wallet-guard">'
			. '.wc-stripe-cart-checkout-container,'
			. '.wc-stripe_applepay-cart-button,.wc-stripe_googlepay-cart-button,'
			. '.wc-stripe-payment-method.payment_method_stripe_applepay,'
			. '.wc-stripe-payment-method.payment_method_stripe_googlepay,'
			. '.wc-stripe-cart-or,li.wc-stripe-payment-method.or,'
			. '.wc-stripe-cart-buttons,.wcpay-payment-request-wrapper'
			. '{display:none!important}'
			. '</style>';
	}

	private function inline_styles() {
		echo '<style id="cq-attendee-css">'
			. '.cq-attendee-wrap{margin:0 0 24px}'
			. '.cq-attendee-heading{margin:0 0 6px}'
			. '.cq-attendee-intro{margin:0 0 14px;opacity:.85;font-size:.95em}'
			. '.cq-attendee-notice{margin:0 0 16px;padding:10px 12px;border-left:4px solid #c8811a;background:rgba(200,129,26,.10);font-size:.93em;line-height:1.45}'
			. '.cq-attendee-line{margin:0 0 18px}'
			. '.cq-attendee-course{font-weight:600;margin:0 0 8px}'
			. '.cq-attendee-block{border:1px solid rgba(128,128,128,.35);padding:12px 14px 4px;margin:0 0 12px}'
			. '.cq-attendee-legend{font-size:.85em;text-transform:uppercase;letter-spacing:.06em;padding:0 6px;opacity:.8}'
			. '.cq-attendee-sameas{display:block;margin:0 0 10px;font-size:.92em;cursor:pointer}'
			. '.cq-attendee-sameas input{margin-right:6px}'
			. '.cq-attendee-row{margin:0 0 10px}'
			. '</style>';
	}

	/**
	 * Small convenience script. Everything still works with JavaScript off —
	 * this only copies values the customer has already typed.
	 */
	private function inline_script() {
		?>
<script id="cq-attendee-js">
(function(){
	function val(id){ var el=document.getElementById(id); return el ? el.value : ''; }

	function copyInto(box){
		if(!box) return;
		var name  = box.querySelector('.cq-att-name');
		var mob   = box.querySelector('.cq-att-mobile');
		var email = box.querySelector('.cq-att-email');
		var full  = (val('billing_first_name') + ' ' + val('billing_last_name')).trim();
		if(name)  name.value  = full;
		if(mob)   mob.value   = val('billing_phone');
		if(email) email.value = val('billing_email');
	}

	// Ticking the box copies the billing details across.
	document.addEventListener('change', function(e){
		if(!e.target || !e.target.classList || !e.target.classList.contains('cq-attendee-sameas-input')) return;
		if(e.target.checked) copyInto(e.target.closest('.cq-attendee-block'));
	});

	// While a box stays ticked, keep it in step with the billing fields —
	// people often tick it first and type their name afterwards.
	['billing_first_name','billing_last_name','billing_phone','billing_email'].forEach(function(id){
		document.addEventListener('input', function(e){
			if(!e.target || e.target.id !== id) return;
			var ticked = document.querySelectorAll('.cq-attendee-sameas-input:checked');
			for(var i=0;i<ticked.length;i++) copyInto(ticked[i].closest('.cq-attendee-block'));
		});
	});
})();
</script>
		<?php
	}
}
