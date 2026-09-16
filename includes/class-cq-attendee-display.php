<?php
/**
 * Showing the attendees as a table in order emails and on the customer's
 * order page.
 *
 * Everything here is presentation only. It adds no data and changes no other
 * plugin, theme or template file — the table is printed onto a WooCommerce
 * hook that already exists for exactly this purpose.
 */

defined( 'ABSPATH' ) || exit;

class CQ_Attendee_Display {

	/**
	 * True only while WooCommerce is drawing the line-item table inside an
	 * email or the customer's order page.
	 *
	 * The per-attendee rows are stored as ordinary line item meta so they show
	 * everywhere by default. When the table layout is chosen those same rows
	 * would appear twice — once squeezed under the product name, once in the
	 * table — so they are filtered out of the item table for that stretch only.
	 * Admin order screens are untouched: the flag is never on there.
	 */
	private $inside_item_table = false;

	public function __construct() {
		// Mark the start and end of the item table in both customer-facing places.
		add_action( 'woocommerce_email_before_order_table', array( $this, 'flag_on' ), 1 );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'flag_off' ), 1 );
		add_action( 'woocommerce_order_details_before_order_table', array( $this, 'flag_on' ), 1 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'flag_off' ), 1 );

		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( $this, 'filter_item_meta' ), 20, 2 );

		// Where the table itself is printed.
		//
		// woocommerce_email_order_meta runs immediately after the totals, which
		// is where this shop's "Course Date:" and "Attendee:" lines already sit,
		// so the table lands with them rather than somewhere unrelated.
		//
		// Priority 0 and 9999 wrap everything else on that hook in an output
		// buffer, so the single-attendee line another plugin prints can be taken
		// back out. Nothing is edited anywhere else — the line is simply not
		// copied through. Turning the setting off removes the buffer entirely.
		add_action( 'woocommerce_email_order_meta', array( $this, 'capture_start' ), 0, 4 );
		add_action( 'woocommerce_email_order_meta', array( $this, 'capture_end' ), 9999, 4 );

		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'page_table' ), 20, 1 );
	}

	/** Set while this class is holding the hook's output in a buffer. */
	private $capturing = false;

	private function table_layout() {
		$s = cq_attendee_get_settings();
		return ( 'table' === $s['email_layout'] );
	}

	public function flag_on() {
		$this->inside_item_table = true;
	}

	public function flag_off() {
		$this->inside_item_table = false;
	}

	/**
	 * Drop the per-attendee rows from the line-item table while the table
	 * layout is drawing them separately. Nothing is deleted — this only hides
	 * them from that one view.
	 */
	public function filter_item_meta( $formatted, $item = null ) {
		if ( ! $this->inside_item_table || ! $this->table_layout() ) {
			return $formatted;
		}
		if ( ! is_array( $formatted ) ) {
			return $formatted;
		}

		foreach ( $formatted as $id => $meta ) {
			$key = isset( $meta->key ) ? (string) $meta->key : '';
			if ( preg_match( '/^Attendee(\s\d+)?$/', $key ) ) {
				unset( $formatted[ $id ] );
			}
		}

		return $formatted;
	}

	/* ---------------------------------------------------------------------
	 * Gathering
	 * ------------------------------------------------------------------ */

	/**
	 * Pull the structured attendees back off the order.
	 *
	 * @return array [ [ 'course' => string, 'people' => [ [name, mobile, email] ] ] ]
	 */
	private function collect( $order ) {
		$out = array();

		if ( ! $order || ! method_exists( $order, 'get_items' ) ) {
			return $out;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! method_exists( $item, 'get_meta' ) ) {
				continue;
			}

			$raw = (string) $item->get_meta( CQ_Attendee_Order::RAW_META, true );
			if ( '' === $raw ) {
				continue;
			}

			$people = json_decode( $raw, true );
			if ( ! is_array( $people ) || ! $people ) {
				continue;
			}

			$course = $item->get_name();
			$venue  = CQ_Attendee_Order::venue_for_item( $item );

			// Add the booked date to the heading if the line carries one.
			foreach ( $item->get_meta_data() as $meta ) {
				$data = method_exists( $meta, 'get_data' ) ? $meta->get_data() : array();
				$key  = isset( $data['key'] ) ? (string) $data['key'] : '';
				if ( preg_match( '/course[-_ ]?dates?/i', $key ) && ! empty( $data['value'] ) && is_scalar( $data['value'] ) ) {
					$course .= ' — ' . $data['value'];
					break;
				}
			}

			$out[] = array(
				'course' => $course,
				'people' => $people,
				'venue'  => $venue,
			);
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Output
	 * ------------------------------------------------------------------ */

	/**
	 * Start holding the hook's output, but ONLY when there is a replacement to
	 * put in its place. An order with no attendee details keeps its existing
	 * line exactly as it is today.
	 */
	public function capture_start( $order = null, $sent_to_admin = false, $plain_text = false, $email = null ) {
		$s = cq_attendee_get_settings();

		if ( 'yes' !== $s['hide_legacy'] || '' === trim( (string) $s['legacy_label'] ) ) {
			return;
		}
		if ( ! $this->collect( $order ) ) {
			return;
		}

		$this->capturing = ob_start();
	}

	/**
	 * Put back everything the other plugins printed, minus the single-attendee
	 * line, then add the table and the course location.
	 *
	 * @param WC_Order $order
	 * @param bool     $sent_to_admin
	 * @param bool     $plain_text
	 * @param mixed    $email
	 */
	public function capture_end( $order = null, $sent_to_admin = false, $plain_text = false, $email = null ) {
		if ( $this->capturing ) {
			$this->capturing = false;
			$captured        = ob_get_clean();

			if ( is_string( $captured ) ) {
				// Echoed as-is on purpose. This is other plugins' own finished
				// output being handed back unchanged; running it through an
				// escaper here would mangle their markup, not make it safer.
				echo $this->strip_legacy_line( $captured, (bool) $plain_text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}

		if ( ! $this->table_layout() ) {
			return;
		}

		$lines = $this->collect( $order );
		if ( ! $lines ) {
			return;
		}

		if ( $plain_text ) {
			$this->render_plain( $lines );
			return;
		}

		$this->render_html( $lines, true );
	}

	/**
	 * Remove the shop's existing one-line attendee row.
	 *
	 * The markup it produces is a single paragraph:
	 *   <p style="..."><strong>Attendee:</strong> John Doe</p>
	 *
	 * Only a paragraph whose bolded label matches the configured word is
	 * touched. Anything else on that hook — the Course Date line, and every
	 * other plugin's output — passes through untouched. If the wording ever
	 * changes, nothing matches and nothing is removed: the email keeps its
	 * original line rather than losing anything.
	 */
	private function strip_legacy_line( $html, $plain_text = false ) {
		$s     = cq_attendee_get_settings();
		$label = preg_quote( trim( (string) $s['legacy_label'] ), '/' );

		if ( '' === $label ) {
			return $html;
		}

		if ( $plain_text ) {
			$out = preg_replace( '/^[ \t]*' . $label . '\s*:.*\R?/mi', '', $html );
			return ( null === $out ) ? $html : $out;
		}

		$patterns = array(
			// <p ...><strong>Attendee:</strong> Name</p>
			'/<p\b[^>]*>\s*<(strong|b)\b[^>]*>\s*' . $label . '\s*:?\s*<\/\1>.*?<\/p>/is',
			// <strong>Attendee:</strong> Name<br> with no wrapping paragraph
			'/<(strong|b)\b[^>]*>\s*' . $label . '\s*:?\s*<\/\1>[^<]*(<br\s*\/?>)?/is',
		);

		foreach ( $patterns as $pattern ) {
			$out = preg_replace( $pattern, '', $html );
			if ( null !== $out && $out !== $html ) {
				return $out;
			}
		}

		return $html;
	}

	/**
	 * The same table on the order-received page and under My account → Orders.
	 *
	 * @param WC_Order $order
	 */
	public function page_table( $order ) {
		if ( ! $this->table_layout() ) {
			return;
		}

		$lines = $this->collect( $order );
		if ( ! $lines ) {
			return;
		}

		$this->render_html( $lines, false );
	}

	/**
	 * Inline styles only, and no background or text colours.
	 *
	 * Email clients strip stylesheets, and this shop's template is dark — a
	 * table that set its own white background would be unreadable. Borrowing
	 * the surrounding colours means it looks native in both the dark email and
	 * the light website.
	 */
	private function render_html( $lines, $in_email ) {
		$s     = cq_attendee_get_settings();
		$title = $s['email_heading'] ? $s['email_heading'] : 'Attendee details';

		$cell   = 'padding:8px 10px;border:1px solid #7f8285;text-align:left;vertical-align:top;font-size:14px;';
		$header = $cell . 'font-weight:bold;';

		echo '<div class="cq-attendee-order-table" style="margin:0 0 24px;">';
		echo '<h2 style="margin:0 0 8px;font-size:16px;">' . esc_html( $title ) . '</h2>';

		foreach ( $lines as $line ) {

			if ( count( $lines ) > 1 ) {
				echo '<p style="margin:12px 0 4px;font-weight:bold;font-size:14px;">' . esc_html( $line['course'] ) . '</p>';
			}

			echo '<table cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;margin:0 0 10px;">';
			echo '<thead><tr>';
			echo '<th style="' . esc_attr( $header ) . 'width:34px;">#</th>';
			echo '<th style="' . esc_attr( $header ) . '">Name</th>';
			echo '<th style="' . esc_attr( $header ) . '">Phone</th>';
			echo '<th style="' . esc_attr( $header ) . '">Email</th>';
			echo '</tr></thead><tbody>';

			$n = 0;
			foreach ( $line['people'] as $person ) {
				$n++;
				echo '<tr>';
				echo '<td style="' . esc_attr( $cell ) . '">' . esc_html( $n ) . '</td>';
				echo '<td style="' . esc_attr( $cell ) . '">' . esc_html( $this->part( $person, 'name' ) ) . '</td>';
				echo '<td style="' . esc_attr( $cell ) . '">' . esc_html( $this->part( $person, 'mobile' ) ) . '</td>';
				echo '<td style="' . esc_attr( $cell ) . 'word-break:break-all;">' . esc_html( $this->part( $person, 'email' ) ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';

			// The venue, directly under the table it belongs to.
			$venue = $this->venue_text( $line );
			if ( '' !== $venue ) {
				echo '<p style="margin:0 0 16px;font-size:14px;"><strong>'
					. esc_html( $s['location_label'] ) . ':</strong> '
					. esc_html( $venue ) . '</p>';
			}
		}

		echo '</div>';
	}

	/**
	 * "Lucan Spa Hotel Dublin — K78 X3H3", or '' when the setting is off or the
	 * booked date carries no venue.
	 */
	private function venue_text( $line ) {
		$s = cq_attendee_get_settings();

		if ( 'yes' !== $s['show_location'] || empty( $line['venue']['name'] ) ) {
			return '';
		}

		$text = $line['venue']['name'];
		if ( ! empty( $line['venue']['code'] ) ) {
			$text .= ' — ' . $line['venue']['code'];
		}

		return $text;
	}

	/**
	 * Plain-text emails are not HTML, so nothing is escaped here — running
	 * esc_html() over this would print &#039; where an apostrophe belongs.
	 * The values were sanitised on the way in.
	 */
	private function render_plain( $lines ) {
		$s     = cq_attendee_get_settings();
		$title = $s['email_heading'] ? $s['email_heading'] : 'Attendee details';

		echo "\n" . wp_strip_all_tags( strtoupper( $title ) ) . "\n\n";

		foreach ( $lines as $line ) {
			echo wp_strip_all_tags( $line['course'] ) . "\n";

			$n = 0;
			foreach ( $line['people'] as $person ) {
				$n++;
				$bits = array(
					$this->part( $person, 'name' ),
					$this->part( $person, 'mobile' ),
					$this->part( $person, 'email' ),
				);
				echo '  ' . wp_strip_all_tags( $n . '. ' . implode( ' | ', $bits ) ) . "\n";
			}

			$venue = $this->venue_text( $line );
			if ( '' !== $venue ) {
				echo wp_strip_all_tags( $s['location_label'] . ': ' . $venue ) . "\n";
			}

			echo "\n";
		}
	}

	/**
	 * A value, or an em dash so an empty cell never looks like a rendering fault.
	 * Note the empty-string check: a field that was collected but left blank is
	 * "set" yet still has nothing in it.
	 */
	private function part( $person, $field ) {
		if ( is_array( $person ) && isset( $person[ $field ] ) && is_scalar( $person[ $field ] ) ) {
			$value = trim( (string) $person[ $field ] );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '—';
	}
}
