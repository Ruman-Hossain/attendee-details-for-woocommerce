<?php
/**
 * Plugin Name: Attendee Details for WooCommerce
 * Plugin URI:  https://github.com/Ruman-Hossain/attendee-details-for-woocommerce
 * Description: Collects a name (and optionally mobile and email) for every place booked, on the checkout page, based on the quantity ordered. Choose exactly which products it applies to. Does nothing at all until enabled.
 * Version:     1.0.0
 * Author:      Md Ruman Hossain
 * Author URI:  https://rumancsebrur.blogspot.com/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: attendee-details-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 5.8
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 6.0
 * WC tested up to: 11.0
 *
 * WHAT THIS PLUGIN DOES
 * ---------------------
 * On the checkout page it renders one block of attendee fields per place booked.
 * Book 3 places on a course and you get 3 blocks for that line. The answers are
 * saved onto the order line item, so they appear on the order screen and in the
 * order emails automatically.
 *
 * SAFETY DESIGN (read this before changing anything)
 * --------------------------------------------------
 * 1. Master switch is OFF by default. While off, no checkout hooks are
 *    registered at all — the checkout runs exactly as it did before.
 * 2. No database tables are created. Data is stored in WooCommerce's own
 *    line item meta, so deleting this plugin never loses an order's data.
 * 3. Every entry point bails out early if WooCommerce is missing, the cart is
 *    empty, or no selected product is in the basket.
 * 4. Validation can be turned off independently, so you can collect data
 *    without ever blocking a customer from paying.
 *
 * TO SWITCH EVERYTHING OFF: WooCommerce → Attendee Details → untick "Enable".
 * TO REMOVE COMPLETELY: deactivate and delete. Order data stays intact.
 */

defined( 'ABSPATH' ) || exit;

define( 'CQ_ATTENDEE_VERSION', '1.0.0' );
define( 'CQ_ATTENDEE_FILE', __FILE__ );
define( 'CQ_ATTENDEE_PATH', plugin_dir_path( __FILE__ ) );
define( 'CQ_ATTENDEE_URL', plugin_dir_url( __FILE__ ) );
define( 'CQ_ATTENDEE_OPTION', 'cq_attendee_settings' );

/* Author / project details, in one place so they are never out of step. */
define( 'CQ_ATTENDEE_AUTHOR', 'Md Ruman Hossain' );
define( 'CQ_ATTENDEE_AUTHOR_URL', 'https://rumancsebrur.blogspot.com/' );
define( 'CQ_ATTENDEE_AUTHOR_LOCATION', 'Rangpur, Bangladesh' );
define( 'CQ_ATTENDEE_PROJECT_URL', 'https://github.com/Ruman-Hossain/attendee-details-for-woocommerce' );
define( 'CQ_ATTENDEE_SUPPORT_URL', 'https://github.com/Ruman-Hossain/attendee-details-for-woocommerce/issues' );

/**
 * The author's public profiles, shown on the settings screen.
 *
 * Deliberately no email address: support goes through the project's issue
 * tracker, which keeps a private inbox out of a file every customer can read.
 */
function cq_attendee_author_links() {
	return array(
		'Website'       => 'https://rumancsebrur.blogspot.com/',
		'GitHub'        => 'https://github.com/Ruman-Hossain',
		'LinkedIn'      => 'https://www.linkedin.com/in/ruman-hossain/',
		'Facebook'      => 'https://www.facebook.com/ruman.hossain.988/',
		'WordPress.org' => 'https://profiles.wordpress.org/rumanhossain/',
	);
}

/**
 * Default settings. Deliberately inert: enabled = no, no products selected.
 */
function cq_attendee_default_settings() {
	return array(
		'enabled'          => 'no',
		'product_ids'      => array(),
		'category_ids'     => array(),
		'trigger'          => 'always',   // always | multiple_only
		'required'         => 'no',       // no | yes
		'collect_mobile'   => 'yes',
		'collect_email'    => 'yes',
		'hide_wallet_cart' => 'no',
		'prefill_billing'  => 'yes',
		'email_layout'     => 'table',    // table | rows
		'email_heading'    => 'Attendee details',

		// Removing the single-attendee line the shop already prints.
		'hide_legacy'      => 'yes',
		'legacy_label'     => 'Attendee',

		// Course location, read from the booked date rather than typed again.
		'show_location'    => 'yes',
		'location_label'   => 'Course Location',
		'venue_meta_key'   => '_venue_location',
		'venue_code_key'   => '_venue_eircode',

		// What to do about a checkout field that already asks the same thing.
		'order_notes_mode' => 'leave',    // leave | reset | hide

		'max_per_line'     => 20,
		'heading'          => 'Who is attending?',
		'intro'            => 'Please give the details of each person attending. These must match the details they write on the attendance sheet on the day.',
		'notice'           => 'Important: only the people named here can attend. Please give each attendee\'s real name, mobile number and email address. Anyone whose details do not match this booking may be refused entry on the day, or charged again.',
	);
}

/**
 * Read settings, merged over defaults so a missing key can never fatal.
 */
function cq_attendee_get_settings() {
	$saved = get_option( CQ_ATTENDEE_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return wp_parse_args( $saved, cq_attendee_default_settings() );
}

/**
 * Is the plugin switched on AND pointed at at least one product or category?
 * Everything else in the plugin checks this first.
 */
function cq_attendee_is_active() {
	$s = cq_attendee_get_settings();
	if ( 'yes' !== $s['enabled'] ) {
		return false;
	}
	return ! empty( $s['product_ids'] ) || ! empty( $s['category_ids'] );
}

/**
 * Does this product need attendee details?
 */
function cq_attendee_product_applies( $product_id ) {
	$s = cq_attendee_get_settings();

	$product_id = absint( $product_id );
	if ( ! $product_id ) {
		return false;
	}

	if ( ! empty( $s['product_ids'] ) && in_array( $product_id, array_map( 'absint', $s['product_ids'] ), true ) ) {
		return true;
	}

	if ( ! empty( $s['category_ids'] ) ) {
		$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return false;
		}
		$wanted = array_map( 'absint', $s['category_ids'] );
		if ( array_intersect( $wanted, array_map( 'absint', $terms ) ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Build the list of lines needing attendees, straight from the live cart.
 * Never trust what was posted — the customer can change the cart in another tab.
 *
 * @return array [ cart_item_key => [ 'product_id'=>int,'name'=>string,'places'=>int ] ]
 */
function cq_attendee_lines_from_cart() {
	$lines = array();

	if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
		return $lines;
	}

	$s   = cq_attendee_get_settings();
	$cap = max( 1, absint( $s['max_per_line'] ) );

	foreach ( WC()->cart->get_cart() as $key => $item ) {
		$product_id = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
		if ( ! cq_attendee_product_applies( $product_id ) ) {
			continue;
		}

		$places = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 0;
		if ( $places < 1 ) {
			continue;
		}
		if ( 'multiple_only' === $s['trigger'] && $places < 2 ) {
			continue;
		}

		$places = min( $places, $cap );

		$label = '';
		if ( ! empty( $item['data'] ) && is_object( $item['data'] ) && method_exists( $item['data'], 'get_name' ) ) {
			$label = $item['data']->get_name();
		}
		if ( '' === $label ) {
			$label = get_the_title( $product_id );
		}

		// Append the chosen course date if the store records one on the line.
		foreach ( array( 'course-dates', 'Course Dates:', 'course_date' ) as $meta_key ) {
			if ( ! empty( $item[ $meta_key ] ) && is_scalar( $item[ $meta_key ] ) ) {
				$label .= ' — ' . $item[ $meta_key ];
				break;
			}
		}

		$lines[ $key ] = array(
			'product_id' => $product_id,
			'name'       => $label,
			'places'     => $places,
		);
	}

	return $lines;
}

/**
 * Work out the venue for a booked line.
 *
 * On this shop each course *date* is a product variation, and the variation
 * carries the venue: `_venue_location` holds the ID of a `course_locations`
 * post, and `_venue_eircode` holds its postcode. That is why the location never
 * needs to be asked for — choosing the date has already chosen the venue.
 *
 * Both meta keys are settings, and a key holding plain text rather than an ID
 * works just as well, so this is not tied to one shop's setup.
 *
 * @return array [ 'name' => string, 'code' => string ] or [] if there is none.
 */
function cq_attendee_venue_lookup( $variation_id = 0, $product_id = 0 ) {
	$s = cq_attendee_get_settings();

	$key = trim( (string) $s['venue_meta_key'] );
	if ( '' === $key ) {
		return array();
	}

	// The variation is asked first: the date is what pins down the venue.
	$candidates = array_filter( array( absint( $variation_id ), absint( $product_id ) ) );

	foreach ( $candidates as $id ) {
		$raw = get_post_meta( $id, $key, true );
		if ( '' === $raw || null === $raw || array() === $raw ) {
			continue;
		}

		$name = '';
		if ( is_numeric( $raw ) ) {
			$post = get_post( absint( $raw ) );
			$name = ( $post && ! is_wp_error( $post ) ) ? $post->post_title : '';
		} elseif ( is_scalar( $raw ) ) {
			$name = (string) $raw;
		}

		if ( '' === trim( $name ) ) {
			continue;
		}

		$code = '';
		if ( ! empty( $s['venue_code_key'] ) ) {
			$code_raw = get_post_meta( $id, trim( (string) $s['venue_code_key'] ), true );
			if ( is_scalar( $code_raw ) ) {
				$code = trim( (string) $code_raw );
			}
		}

		return array(
			'name' => trim( $name ),
			'code' => $code,
		);
	}

	return array();
}

/**
 * Does the current cart contain anything we care about?
 */
function cq_attendee_cart_has_applicable() {
	return (bool) cq_attendee_lines_from_cart();
}

/* -------------------------------------------------------------------------
 * Boot
 * ---------------------------------------------------------------------- */

add_action( 'plugins_loaded', 'cq_attendee_boot', 20 );

function cq_attendee_boot() {

	// Admin settings load whether or not the feature is enabled, so it can be
	// configured and switched on. They add nothing to the front end.
	if ( is_admin() ) {
		require_once CQ_ATTENDEE_PATH . 'includes/class-cq-attendee-settings.php';
		new CQ_Attendee_Settings();
	}

	// Hard stop: no WooCommerce, or feature off — register nothing else.
	if ( ! class_exists( 'WooCommerce' ) || ! cq_attendee_is_active() ) {
		return;
	}

	require_once CQ_ATTENDEE_PATH . 'includes/class-cq-attendee-checkout.php';
	require_once CQ_ATTENDEE_PATH . 'includes/class-cq-attendee-order.php';
	require_once CQ_ATTENDEE_PATH . 'includes/class-cq-attendee-display.php';

	new CQ_Attendee_Checkout();
	new CQ_Attendee_Order();
	new CQ_Attendee_Display();

	// Order-screen entry, for phone bookings and corrections. Admin only.
	if ( is_admin() ) {
		require_once CQ_ATTENDEE_PATH . 'includes/class-cq-attendee-admin.php';
		new CQ_Attendee_Admin();
	}
}

/**
 * Tell WooCommerce which of its features this plugin is known to work with.
 *
 * Without this, WooCommerce → Settings → Advanced → Features lists the plugin
 * as "incompatible" with High-Performance Order Storage purely because it never
 * said otherwise. The plugin reads and writes orders only through the WC_Order
 * API, which is what HPOS requires, so the declaration is honest.
 *
 * The checkout *blocks* declaration is deliberately false: this plugin renders
 * into the classic checkout form. Saying so means WooCommerce warns the shop
 * owner if they ever switch to the block checkout, instead of the fields
 * silently disappearing.
 */
add_action( 'before_woocommerce_init', 'cq_attendee_declare_compatibility' );

function cq_attendee_declare_compatibility() {
	if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		return;
	}
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, false );
}

/**
 * Tell the shop owner if WooCommerce is missing rather than failing silently.
 */
add_action( 'admin_notices', 'cq_attendee_dependency_notice' );

function cq_attendee_dependency_notice() {
	if ( class_exists( 'WooCommerce' ) ) {
		return;
	}
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p><strong>Attendee Details</strong> needs WooCommerce to be active. It is doing nothing at the moment.</p></div>';
}
