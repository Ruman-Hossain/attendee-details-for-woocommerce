<?php
/**
 * Saving attendee answers onto the order, and surfacing orders that arrived
 * without them (phone bookings, wallet express checkouts).
 */

defined( 'ABSPATH' ) || exit;

class CQ_Attendee_Order {

	/** Hidden meta key holding the structured copy, for future reporting. */
	const RAW_META = '_cq_attendees';

	/** Hidden order meta flag set when a line needed details and has none. */
	const MISSING_META = '_cq_attendees_missing';

	/** Hidden order meta recording the wording the customer agreed to. */
	const TERMS_META = '_cq_attendees_notice';

	/** Hidden line item meta holding the venue as it stood on the day of booking. */
	const VENUE_META = '_cq_venue';

	public function __construct() {
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_to_line_item' ), 10, 4 );

		// Keep a copy of the rules-of-entry wording as it stood when this order
		// was placed. If the policy is ever changed, an old order still shows
		// what that customer was actually told.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'stamp_notice' ), 10, 1 );

		// NOTE: must run AFTER line items exist. woocommerce_checkout_create_order
		// fires before they are added, so the order would look empty there.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'flag_missing' ), 20, 3 );

		// Admin visibility for orders that slipped through without details.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ), 20 );
		add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'add_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column' ), 20, 2 );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'render_column_hpos' ), 20, 2 );
	}

	/**
	 * Attach one readable meta row per attendee, plus a hidden structured copy.
	 *
	 * Readable keys (no leading underscore) show automatically on the order
	 * screen and inside every order email, exactly as "course-dates" already does.
	 *
	 * @param WC_Order_Item_Product $item
	 * @param string                $cart_item_key
	 * @param array                 $values
	 * @param WC_Order              $order
	 */
	public function save_to_line_item( $item, $cart_item_key, $values, $order ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'add_meta_data' ) ) {
			return;
		}

		$product_id = isset( $values['product_id'] ) ? absint( $values['product_id'] ) : 0;
		if ( ! cq_attendee_product_applies( $product_id ) ) {
			return;
		}

		// Freeze the venue onto the line, before anything else can return early.
		//
		// It is looked up rather than asked for: the customer already chose it
		// when they chose the date. Storing it means an order still shows the
		// right venue even if the date is later moved to a different hotel.
		$variation_id = isset( $values['variation_id'] ) ? absint( $values['variation_id'] ) : 0;
		$venue        = cq_attendee_venue_lookup( $variation_id, $product_id );
		if ( $venue ) {
			$item->add_meta_data( self::VENUE_META, wp_json_encode( $venue ), true );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this runs.
		$posted = isset( $_POST['cq_att'] ) && is_array( $_POST['cq_att'] ) ? wp_unslash( $_POST['cq_att'] ) : array();
		if ( empty( $posted[ $cart_item_key ] ) || ! is_array( $posted[ $cart_item_key ] ) ) {
			return;
		}

		$s         = cq_attendee_get_settings();
		$places    = isset( $values['quantity'] ) ? absint( $values['quantity'] ) : 1;
		$cap       = max( 1, absint( $s['max_per_line'] ) );
		$places    = min( max( 1, $places ), $cap );
		$structured = array();

		for ( $i = 1; $i <= $places; $i++ ) {
			$row = isset( $posted[ $cart_item_key ][ $i ] ) && is_array( $posted[ $cart_item_key ][ $i ] )
				? $posted[ $cart_item_key ][ $i ]
				: array();

			$name   = isset( $row['name'] ) ? trim( sanitize_text_field( $row['name'] ) ) : '';
			$mobile = isset( $row['mobile'] ) ? trim( sanitize_text_field( $row['mobile'] ) ) : '';
			$email  = isset( $row['email'] ) ? trim( sanitize_text_field( $row['email'] ) ) : '';

			if ( '' !== $email && ! is_email( $email ) ) {
				$email = '';
			}

			if ( '' === $name && '' === $mobile && '' === $email ) {
				continue; // Nothing given for this place — record nothing.
			}

			$parts = array_filter( array( $name, $mobile, $email ), 'strlen' );

			$label = ( $places > 1 ) ? sprintf( 'Attendee %d', $i ) : 'Attendee';

			$item->add_meta_data( $label, implode( ' · ', $parts ), true );

			$structured[ $i ] = array(
				'name'   => $name,
				'mobile' => $mobile,
				'email'  => $email,
			);
		}

		if ( $structured ) {
			$item->add_meta_data( self::RAW_META, wp_json_encode( $structured ), true );
		}
	}

	/**
	 * @param WC_Order $order
	 */
	public function stamp_notice( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}
		if ( ! cq_attendee_cart_has_applicable() ) {
			return;
		}
		$s = cq_attendee_get_settings();
		if ( empty( $s['notice'] ) ) {
			return;
		}
		$order->update_meta_data( self::TERMS_META, sanitize_textarea_field( $s['notice'] ) );
	}

	/**
	 * After the order is built, note whether any line that should have had
	 * attendee details ended up without them. This catches admin-created orders
	 * and any express wallet checkout that skipped the form.
	 *
	 * @param int      $order_id
	 * @param array    $posted_data
	 * @param WC_Order $order
	 */
	public function flag_missing( $order_id, $posted_data = array(), $order = null ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order || ! method_exists( $order, 'get_items' ) ) {
			return;
		}

		$missing = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}
			if ( ! cq_attendee_product_applies( $item->get_product_id() ) ) {
				continue;
			}
			if ( '' === (string) $item->get_meta( self::RAW_META, true ) ) {
				$missing[] = $item->get_name();
			}
		}

		if ( ! $missing ) {
			return;
		}

		$order->update_meta_data( self::MISSING_META, 'yes' );
		$order->add_order_note(
			'Attendee details are missing for: ' . implode( ', ', array_map( 'sanitize_text_field', $missing ) )
			. '. This usually means the order was placed without the checkout form — for example an express wallet payment or an order created in admin.'
		);
		$order->save();
	}

	/* ---------------------------------------------------------------------
	 * Orders list column
	 * ------------------------------------------------------------------ */

	public function add_column( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}

		$s   = cq_attendee_get_settings();
		$add = array( 'cq_attendees' => 'Attendees' );
		if ( 'yes' === $s['show_location'] ) {
			// Escaped here, not at print time: WooCommerce's list table prints
			// column headings straight out, so a heading holding markup would
			// otherwise land on the page as markup.
			$add['cq_location'] = esc_html( $s['location_label'] ? $s['location_label'] : 'Location' );
		}

		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$new = array_merge( $new, $add );
			}
		}
		foreach ( $add as $key => $label ) {
			if ( ! isset( $new[ $key ] ) ) {
				$new[ $key ] = $label;
			}
		}
		return $new;
	}

	public function render_column( $column, $post_id ) {
		if ( 'cq_attendees' !== $column && 'cq_location' !== $column ) {
			return;
		}
		$this->route_column( $column, wc_get_order( $post_id ) );
	}

	public function render_column_hpos( $column, $order ) {
		if ( 'cq_attendees' !== $column && 'cq_location' !== $column ) {
			return;
		}
		$this->route_column( $column, $order );
	}

	private function route_column( $column, $order ) {
		if ( 'cq_location' === $column ) {
			$this->location_output( $order );
			return;
		}
		$this->column_output( $order );
	}

	/**
	 * Which venue this order is for — the thing that is otherwise only
	 * discoverable by opening the order and reading the date.
	 */
	private function location_output( $order ) {
		if ( ! $order || ! method_exists( $order, 'get_items' ) ) {
			echo '&mdash;';
			return;
		}

		$names = array();

		foreach ( $order->get_items() as $item ) {
			$venue = self::venue_for_item( $item );
			if ( $venue && ! in_array( $venue['name'], $names, true ) ) {
				$names[] = $venue['name'];
			}
		}

		echo $names ? esc_html( implode( ', ', $names ) ) : '&mdash;';
	}

	/**
	 * The venue for one line: the copy frozen at checkout if there is one,
	 * otherwise looked up live so orders placed before this plugin existed
	 * still show a location.
	 */
	public static function venue_for_item( $item ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
			return array();
		}

		$raw = (string) $item->get_meta( self::VENUE_META, true );
		if ( '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) && ! empty( $decoded['name'] ) ) {
				return array(
					'name' => (string) $decoded['name'],
					'code' => isset( $decoded['code'] ) ? (string) $decoded['code'] : '',
				);
			}
		}

		$variation_id = method_exists( $item, 'get_variation_id' ) ? absint( $item->get_variation_id() ) : 0;
		$product_id   = method_exists( $item, 'get_product_id' ) ? absint( $item->get_product_id() ) : 0;

		return cq_attendee_venue_lookup( $variation_id, $product_id );
	}

	private function column_output( $order ) {
		if ( ! $order || ! method_exists( $order, 'get_items' ) ) {
			echo '&mdash;';
			return;
		}

		$expected = 0;
		$got      = 0;

		foreach ( $order->get_items() as $item ) {
			if ( ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}
			if ( ! cq_attendee_product_applies( $item->get_product_id() ) ) {
				continue;
			}
			$expected += max( 1, (int) $item->get_quantity() );

			$raw = (string) $item->get_meta( self::RAW_META, true );
			if ( '' !== $raw ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					$got += count( $decoded );
				}
			}
		}

		if ( 0 === $expected ) {
			echo '&mdash;';
			return;
		}

		if ( $got >= $expected ) {
			echo '<span style="color:#1f7a3d;font-weight:600;">' . esc_html( $got . '/' . $expected ) . '</span>';
		} else {
			echo '<span style="color:#b32d2e;font-weight:600;" title="Attendee details missing">' . esc_html( $got . '/' . $expected ) . ' ⚠</span>';
		}
	}
}
