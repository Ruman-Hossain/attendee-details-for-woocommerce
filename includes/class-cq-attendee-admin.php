<?php
/**
 * Entering and correcting attendee details on the order screen.
 *
 * The checkout can be made to demand attendee details, but an order created in
 * admin — a phone booking — never passes through a checkout, so it can never be
 * forced. Flagging those red tells you something is missing; this box is how you
 * fix it. It is also how you correct a typo in a name without touching the
 * database by hand.
 *
 * Writes to exactly the same meta the checkout writes, so an order completed
 * here is indistinguishable from one booked online.
 */

defined( 'ABSPATH' ) || exit;

class CQ_Attendee_Admin {

	const NONCE = 'cq_attendee_admin_save';

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_box' ), 30 );
		// Priority 60 on purpose. WooCommerce's own handlers sit on this hook at
		// 10 (line items), 30 (downloads), 40 (order data) and 50 (order actions),
		// and the one at 40 loads its own copy of the order and saves it. Writing
		// at the same priority risks our meta being overwritten by that copy, so
		// we wait until every one of them has finished.
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save' ), 60, 2 );
	}

	/**
	 * Registered for both order screens: the classic post editor and the newer
	 * High-Performance Order Storage screen, which has a different screen ID.
	 */
	public function add_box() {
		$screens = array( 'shop_order' );

		if ( class_exists( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )
			&& function_exists( 'wc_get_page_screen_id' ) ) {
			$hpos = wc_get_page_screen_id( 'shop-order' );
			if ( $hpos ) {
				$screens[] = $hpos;
			}
		}

		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box(
				'cq_attendee_box',
				'Attendee details',
				array( $this, 'render' ),
				$screen,
				'normal',
				'default'
			);
		}
	}

	/**
	 * @param WP_Post|WC_Order $post_or_order
	 */
	public function render( $post_or_order ) {
		$order = ( $post_or_order instanceof WP_Post ) ? wc_get_order( $post_or_order->ID ) : $post_or_order;

		if ( ! $order || ! method_exists( $order, 'get_items' ) ) {
			echo '<p>Order could not be read.</p>';
			return;
		}

		$lines = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}
			if ( ! cq_attendee_product_applies( $item->get_product_id() ) ) {
				continue;
			}
			$lines[ $item_id ] = $item;
		}

		if ( ! $lines ) {
			echo '<p>Nothing on this order needs attendee details. Only the products chosen in <strong>WooCommerce → Attendee Details</strong> are asked about.</p>';
			return;
		}

		wp_nonce_field( self::NONCE, 'cq_attendee_admin_nonce' );

		$s   = cq_attendee_get_settings();
		$cap = max( 1, absint( $s['max_per_line'] ) );

		echo '<p style="margin-top:0">Saved with the order, exactly as if the customer had typed them at checkout. They appear in the order emails and in the <strong>Attendees</strong> column.</p>';

		foreach ( $lines as $item_id => $item ) {

			$places = min( max( 1, (int) $item->get_quantity() ), $cap );

			$existing = array();
			$raw      = (string) $item->get_meta( CQ_Attendee_Order::RAW_META, true );
			if ( '' !== $raw ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					$existing = $decoded;
				}
			}

			echo '<h4 style="margin:18px 0 6px">' . esc_html( $item->get_name() ) . ' — ' . esc_html( $places ) . ( 1 === $places ? ' place' : ' places' ) . '</h4>';

			echo '<table class="widefat striped" style="max-width:860px"><thead><tr>'
				. '<th style="width:38px">#</th><th>Full name</th><th style="width:22%">Mobile</th><th style="width:30%">Email</th>'
				. '</tr></thead><tbody>';

			for ( $i = 1; $i <= $places; $i++ ) {
				$row  = isset( $existing[ $i ] ) && is_array( $existing[ $i ] ) ? $existing[ $i ] : array();
				$base = 'cq_admin_att[' . absint( $item_id ) . '][' . $i . ']';

				echo '<tr>';
				echo '<td>' . esc_html( $i ) . '</td>';
				echo '<td><input type="text" style="width:100%" name="' . esc_attr( $base . '[name]' ) . '" value="' . esc_attr( isset( $row['name'] ) ? $row['name'] : '' ) . '" /></td>';
				echo '<td><input type="text" style="width:100%" name="' . esc_attr( $base . '[mobile]' ) . '" value="' . esc_attr( isset( $row['mobile'] ) ? $row['mobile'] : '' ) . '" /></td>';
				echo '<td><input type="text" style="width:100%" name="' . esc_attr( $base . '[email]' ) . '" value="' . esc_attr( isset( $row['email'] ) ? $row['email'] : '' ) . '" /></td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		echo '<p class="description" style="margin-top:10px">Leave a row blank to remove that attendee. Saving replaces what is stored for this order.</p>';
	}

	/**
	 * @param int               $order_id
	 * @param WP_Post|WC_Order  $post_or_order
	 */
	public function save( $order_id, $post_or_order = null ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST['cq_attendee_admin_nonce'] ) ) {
			return; // Our box was not on the screen — leave everything alone.
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['cq_attendee_admin_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! method_exists( $order, 'get_items' ) ) {
			return;
		}

		$posted = isset( $_POST['cq_admin_att'] ) && is_array( $_POST['cq_admin_att'] )
			? wp_unslash( $_POST['cq_admin_att'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: array();

		$s       = cq_attendee_get_settings();
		$cap     = max( 1, absint( $s['max_per_line'] ) );
		$missing = false;
		$touched = false;

		// Walk the order's own items, never the posted keys.
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}
			if ( ! cq_attendee_product_applies( $item->get_product_id() ) ) {
				continue;
			}

			$places     = min( max( 1, (int) $item->get_quantity() ), $cap );
			$structured = array();

			// Clear the readable rows before rewriting them, or editing would
			// leave the old values stacked underneath the new ones.
			$item->delete_meta_data( 'Attendee' );
			for ( $i = 1; $i <= $cap; $i++ ) {
				$item->delete_meta_data( sprintf( 'Attendee %d', $i ) );
			}
			$item->delete_meta_data( CQ_Attendee_Order::RAW_META );

			for ( $i = 1; $i <= $places; $i++ ) {
				$row = isset( $posted[ $item_id ][ $i ] ) && is_array( $posted[ $item_id ][ $i ] )
					? $posted[ $item_id ][ $i ]
					: array();

				$name   = isset( $row['name'] ) ? trim( sanitize_text_field( $row['name'] ) ) : '';
				$mobile = isset( $row['mobile'] ) ? trim( sanitize_text_field( $row['mobile'] ) ) : '';
				$email  = isset( $row['email'] ) ? trim( sanitize_text_field( $row['email'] ) ) : '';

				if ( '' !== $email && ! is_email( $email ) ) {
					$email = '';
				}

				if ( '' === $name && '' === $mobile && '' === $email ) {
					continue;
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
				$item->add_meta_data( CQ_Attendee_Order::RAW_META, wp_json_encode( $structured ), true );
			} else {
				$missing = true;
			}

			$item->save();
			$touched = true;
		}

		if ( ! $touched ) {
			return;
		}

		if ( $missing ) {
			$order->update_meta_data( CQ_Attendee_Order::MISSING_META, 'yes' );
		} else {
			$order->delete_meta_data( CQ_Attendee_Order::MISSING_META );
		}

		$order->save();
	}
}
