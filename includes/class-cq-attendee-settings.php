<?php
/**
 * Admin settings screen: WooCommerce → Attendee Details.
 *
 * This is the only place the plugin is configured. Nothing here affects the
 * front end except the values it saves.
 */

defined( 'ABSPATH' ) || exit;

class CQ_Attendee_Settings {

	const SLUG  = 'cq-attendee-details';
	const NONCE = 'cq_attendee_save';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_action( 'admin_post_cq_attendee_save', array( $this, 'save' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CQ_ATTENDEE_FILE ), array( $this, 'action_links' ) );
	}

	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::SLUG );
		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">Settings</a>',
			'<a href="' . esc_url( CQ_ATTENDEE_SUPPORT_URL ) . '" target="_blank" rel="noopener noreferrer">Support</a>'
		);
		return $links;
	}

	public function menu() {
		add_submenu_page(
			'woocommerce',
			'Attendee Details',
			'Attendee Details',
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'page' )
		);
	}

	public function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'You do not have permission to change these settings.' );
		}
		check_admin_referer( self::NONCE );

		$in  = wp_unslash( $_POST );
		$out = cq_attendee_default_settings();

		$out['enabled']          = ( ! empty( $in['enabled'] ) ) ? 'yes' : 'no';
		$out['required']         = ( ! empty( $in['required'] ) ) ? 'yes' : 'no';
		$out['collect_mobile']   = ( ! empty( $in['collect_mobile'] ) ) ? 'yes' : 'no';
		$out['collect_email']    = ( ! empty( $in['collect_email'] ) ) ? 'yes' : 'no';
		$out['hide_wallet_cart'] = ( ! empty( $in['hide_wallet_cart'] ) ) ? 'yes' : 'no';
		$out['prefill_billing']  = ( ! empty( $in['prefill_billing'] ) ) ? 'yes' : 'no';

		$out['trigger'] = ( isset( $in['trigger'] ) && 'multiple_only' === $in['trigger'] ) ? 'multiple_only' : 'always';

		$out['email_layout']  = ( isset( $in['email_layout'] ) && 'rows' === $in['email_layout'] ) ? 'rows' : 'table';
		$out['email_heading'] = isset( $in['email_heading'] ) ? sanitize_text_field( $in['email_heading'] ) : '';

		$out['hide_legacy']  = ( ! empty( $in['hide_legacy'] ) ) ? 'yes' : 'no';
		$out['legacy_label'] = isset( $in['legacy_label'] ) ? sanitize_text_field( $in['legacy_label'] ) : '';

		$out['show_location']  = ( ! empty( $in['show_location'] ) ) ? 'yes' : 'no';
		$out['location_label'] = isset( $in['location_label'] ) ? sanitize_text_field( $in['location_label'] ) : '';
		$out['venue_meta_key'] = isset( $in['venue_meta_key'] ) ? sanitize_text_field( $in['venue_meta_key'] ) : '';
		$out['venue_code_key'] = isset( $in['venue_code_key'] ) ? sanitize_text_field( $in['venue_code_key'] ) : '';

		$allowed_notes            = array( 'leave', 'reset', 'hide' );
		$out['order_notes_mode']  = ( isset( $in['order_notes_mode'] ) && in_array( $in['order_notes_mode'], $allowed_notes, true ) ) ? $in['order_notes_mode'] : 'leave';

		$out['product_ids']  = isset( $in['product_ids'] ) && is_array( $in['product_ids'] ) ? array_values( array_filter( array_map( 'absint', $in['product_ids'] ) ) ) : array();
		$out['category_ids'] = isset( $in['category_ids'] ) && is_array( $in['category_ids'] ) ? array_values( array_filter( array_map( 'absint', $in['category_ids'] ) ) ) : array();

		$out['max_per_line'] = isset( $in['max_per_line'] ) ? max( 1, min( 50, absint( $in['max_per_line'] ) ) ) : 10;

		$out['heading'] = isset( $in['heading'] ) ? sanitize_text_field( $in['heading'] ) : '';
		$out['intro']   = isset( $in['intro'] ) ? sanitize_textarea_field( $in['intro'] ) : '';
		$out['notice']  = isset( $in['notice'] ) ? sanitize_textarea_field( $in['notice'] ) : '';

		update_option( CQ_ATTENDEE_OPTION, $out );

		wp_safe_redirect( add_query_arg( 'cq_saved', '1', admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}

	public function page() {
		// add_submenu_page already gates this, but a capability check at the top
		// of the callback costs nothing and survives someone later calling this
		// method directly or re-registering the page with a weaker capability.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'You do not have permission to view these settings.' );
		}

		$s = cq_attendee_get_settings();

		$products = wc_get_products(
			array(
				'limit'   => 200,
				'status'  => array( 'publish', 'private' ),
				'orderby' => 'title',
				'order'   => 'ASC',
				'return'  => 'objects',
			)
		);

		$cats = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $cats ) ) {
			$cats = array();
		}

		$live = cq_attendee_is_active();
		?>
		<div class="wrap">
			<h1>Attendee Details</h1>

			<?php if ( isset( $_GET['cq_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
			<?php endif; ?>

			<div class="notice <?php echo $live ? 'notice-warning' : 'notice-info'; ?>" style="margin-top:14px">
				<p>
					<strong>Status:</strong>
					<?php if ( $live ) : ?>
						Running. Attendee fields appear on the checkout for the products selected below.
						<?php echo ( 'yes' === $s['required'] ) ? '<strong>Answers are required</strong> — an incomplete form will stop the order.' : 'Answers are optional — nobody is blocked from paying.'; ?>
					<?php else : ?>
						Doing nothing. The checkout is completely unchanged. Tick “Enable” and choose at least one product or category to switch it on.
					<?php endif; ?>
				</p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cq_attendee_save" />
				<?php wp_nonce_field( self::NONCE ); ?>

				<table class="form-table" role="presentation">

					<tr>
						<th scope="row">Enable</th>
						<td>
							<label><input type="checkbox" name="enabled" value="1" <?php checked( 'yes', $s['enabled'] ); ?> /> Collect attendee details on the checkout</label>
							<p class="description">While unticked this plugin registers nothing on the front end. Your checkout behaves exactly as it did before.</p>
						</td>
					</tr>

					<tr>
						<th scope="row">Apply to products</th>
						<td>
							<select name="product_ids[]" multiple size="12" style="min-width:420px">
								<?php foreach ( $products as $p ) : ?>
									<option value="<?php echo esc_attr( $p->get_id() ); ?>" <?php selected( in_array( $p->get_id(), array_map( 'absint', $s['product_ids'] ), true ) ); ?>>
										<?php echo esc_html( $p->get_name() . ' (#' . $p->get_id() . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">Hold Ctrl (or Cmd) to pick more than one. Anything not selected is untouched.</p>
						</td>
					</tr>

					<tr>
						<th scope="row">…or whole categories</th>
						<td>
							<select name="category_ids[]" multiple size="8" style="min-width:420px">
								<?php foreach ( $cats as $c ) : ?>
									<option value="<?php echo esc_attr( $c->term_id ); ?>" <?php selected( in_array( (int) $c->term_id, array_map( 'absint', $s['category_ids'] ), true ) ); ?>>
										<?php echo esc_html( $c->name . ' (' . $c->count . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">Easier to maintain than listing products one by one — picking “Safe Pass” covers every venue at once.</p>
						</td>
					</tr>

					<tr>
						<th scope="row">When to ask</th>
						<td>
							<label><input type="radio" name="trigger" value="always" <?php checked( 'always', $s['trigger'] ); ?> /> On every booking</label><br />
							<label><input type="radio" name="trigger" value="multiple_only" <?php checked( 'multiple_only', $s['trigger'] ); ?> /> Only when more than one place is booked</label>
							<p class="description">“Every booking” also catches the common case where the person paying is not the person attending.</p>
						</td>
					</tr>

					<tr>
						<th scope="row">Required</th>
						<td>
							<label><input type="checkbox" name="required" value="1" <?php checked( 'yes', $s['required'] ); ?> /> Stop the order if details are incomplete</label>
							<p class="description">
								<strong>Worth leaving off for the first few real orders</strong> so you can see what people actually type before you start blocking anyone from paying.
								Once it is on, also tick the express wallet guard below — otherwise an Apple&nbsp;Pay or Google&nbsp;Pay order still gets through without any details,
								and "required" is not really required.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">Fields</th>
						<td>
							<label><input type="checkbox" checked disabled /> Full name <em>(always collected)</em></label><br />
							<label><input type="checkbox" name="collect_mobile" value="1" <?php checked( 'yes', $s['collect_mobile'] ); ?> /> Mobile number</label><br />
							<label><input type="checkbox" name="collect_email" value="1" <?php checked( 'yes', $s['collect_email'] ); ?> /> Email address</label>
						</td>
					</tr>

					<tr>
						<th scope="row">First attendee</th>
						<td>
							<label><input type="checkbox" name="prefill_billing" value="1" <?php checked( 'yes', $s['prefill_billing'] ); ?> /> Start the first attendee off with the billing name, phone and email</label>
							<p class="description">The booking already carries one name — whoever is paying. This copies it into the first box so it isn't typed twice. It is only a starting value: the customer can overwrite it, which they will when the buyer is an office manager who isn't attending.</p>
						</td>
					</tr>

					<tr>
						<th scope="row">In order emails</th>
						<td>
							<label><input type="radio" name="email_layout" value="table" <?php checked( 'table', $s['email_layout'] ); ?> /> As a table — one row per attendee, with Name, Phone and Email columns</label><br />
							<label><input type="radio" name="email_layout" value="rows" <?php checked( 'rows', $s['email_layout'] ); ?> /> Inside the product rows, under the course name</label>
							<p class="description">
								The table is printed just below the order totals, where your “Course Date:” and “Attendee:” lines already appear, and the same table
								shows on the customer's order-received page. Choosing the table also keeps the individual attendee rows out of the product table, so
								nobody is listed twice. Your order screen in admin shows them either way.
							</p>
							<p class="description">
								It borrows the surrounding colours instead of setting its own, so it stays readable in your dark email template.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">Existing one-name line</th>
						<td>
							<label><input type="checkbox" name="hide_legacy" value="1" <?php checked( 'yes', $s['hide_legacy'] ); ?> /> Remove the single “Attendee:” line the email already prints</label>
							<p class="description">
								Your emails currently print one attendee — the billing name — because that is all there was. Once the table below it lists everybody,
								that line is a duplicate. This takes it out of the email as it is being built. <strong>No email template, theme file or other plugin is edited</strong>,
								and it is only removed on orders that actually have attendee details to show instead. Untick this and the line comes straight back.
							</p>
							<p>
								Label to match:
								<input type="text" name="legacy_label" value="<?php echo esc_attr( $s['legacy_label'] ); ?>" class="regular-text" style="max-width:180px" />
								<span class="description">The bold word before the colon. If it ever stops matching, nothing is removed — the email simply keeps its old line.</span>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">Course location</th>
						<td>
							<label><input type="checkbox" name="show_location" value="1" <?php checked( 'yes', $s['show_location'] ); ?> /> Show the venue under the table, and as a column on the Orders list</label>
							<p class="description">
								Nobody has to type this. Each course date already knows its venue, so booking the date has already chosen it —
								this reads it back and puts it where you can see it.
							</p>
							<p>
								Wording: <input type="text" name="location_label" value="<?php echo esc_attr( $s['location_label'] ); ?>" class="regular-text" style="max-width:180px" />
							</p>
							<p>
								Venue field: <input type="text" name="venue_meta_key" value="<?php echo esc_attr( $s['venue_meta_key'] ); ?>" class="regular-text" style="max-width:180px" />
								&nbsp; Postcode field: <input type="text" name="venue_code_key" value="<?php echo esc_attr( $s['venue_code_key'] ); ?>" class="regular-text" style="max-width:180px" />
							</p>
							<p class="description">
								These are the custom fields on the course date. On this shop they are <code>_venue_location</code> (which holds the ID of a
								Course Location entry) and <code>_venue_eircode</code>. A field holding the venue name as plain text works just as well.
								Leave the postcode field empty to show the name alone.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">Order notes box</th>
						<td>
							<label><input type="radio" name="order_notes_mode" value="leave" <?php checked( 'leave', $s['order_notes_mode'] ); ?> /> Leave it exactly as it is</label><br />
							<label><input type="radio" name="order_notes_mode" value="reset" <?php checked( 'reset', $s['order_notes_mode'] ); ?> /> Put WooCommerce's standard wording back</label><br />
							<label><input type="radio" name="order_notes_mode" value="hide" <?php checked( 'hide', $s['order_notes_mode'] ); ?> /> Remove the box from the checkout</label>
							<p class="description">
								If the notes box has been relabelled to ask for attendee details, it is now asking twice. “Standard wording” turns it back into an
								ordinary notes box; “remove” takes it off the checkout altogether. Either only applies when a selected product is in the basket,
								and switching back to “leave it” restores whatever wording was there before — nothing is deleted.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">Email table heading</th>
						<td>
							<input type="text" name="email_heading" value="<?php echo esc_attr( $s['email_heading'] ); ?>" class="regular-text" />
							<p class="description">Shown above the table in emails and on the order page.</p>
						</td>
					</tr>

					<tr>
						<th scope="row">Express wallet guard</th>
						<td>
							<label><input type="checkbox" name="hide_wallet_cart" value="1" <?php checked( 'yes', $s['hide_wallet_cart'] ); ?> /> Hide Apple&nbsp;Pay / Google&nbsp;Pay buttons on the basket page when a selected product is in the basket</label>
							<p class="description">
								Those buttons place an order without ever loading the checkout, so the attendee fields are skipped entirely.
								This hides them <em>only</em> on the basket and <em>only</em> when a selected product is present — the wallets still work
								normally inside the checkout, and on every other basket. Without this, “required” cannot be guaranteed.
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">Maximum per line</th>
						<td>
							<input type="number" name="max_per_line" min="1" max="50" value="<?php echo esc_attr( $s['max_per_line'] ); ?>" class="small-text" />
							<p class="description">There is no fixed limit of three. The number of blocks always equals the quantity booked — 1, 3, 12, whatever they choose — and this is only a ceiling so a mis-typed quantity of 400 cannot render 1,200 empty boxes. Anything above the ceiling still gets the ceiling's worth of boxes and is flagged in the Attendees column as short.</p>
						</td>
					</tr>

					<tr>
						<th scope="row">Heading</th>
						<td><input type="text" name="heading" value="<?php echo esc_attr( $s['heading'] ); ?>" class="regular-text" /></td>
					</tr>

					<tr>
						<th scope="row">Wording</th>
						<td>
							<textarea name="intro" rows="3" class="large-text"><?php echo esc_textarea( $s['intro'] ); ?></textarea>
							<p class="description">Shown once above the fields.</p>
						</td>
					</tr>

					<tr>
						<th scope="row">Rules of entry notice</th>
						<td>
							<textarea name="notice" rows="4" class="large-text"><?php echo esc_textarea( $s['notice'] ); ?></textarea>
							<p class="description">
								Shown in a highlighted box directly above the fields. This is what stops people typing the booker's name three times —
								it tells them plainly that the names given are the names admitted. Leave it empty to show no notice at all.
							</p>
						</td>
					</tr>

				</table>

				<?php submit_button( 'Save settings' ); ?>
			</form>

			<hr />
			<h2>Where the answers go</h2>
			<p>Each attendee is saved onto the order line, so they appear on the order screen and inside your order emails automatically — the same way the course date already does. Nothing is written to a separate table, so removing this plugin never loses an order's data.</p>
			<p>The <strong>Attendees</strong> column on the Orders list shows how many were captured against how many were expected. A red figure means an order arrived without details — normally a phone booking or an express wallet payment.</p>

			<hr />
			<h2>About this plugin</h2>
			<table class="widefat striped" style="max-width:720px">
				<tbody>
					<tr>
						<td style="width:160px"><strong>Version</strong></td>
						<td><?php echo esc_html( CQ_ATTENDEE_VERSION ); ?></td>
					</tr>
					<tr>
						<td><strong>Author</strong></td>
						<td>
							<a href="<?php echo esc_url( CQ_ATTENDEE_AUTHOR_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( CQ_ATTENDEE_AUTHOR ); ?></a>
							&nbsp;<span class="description"><?php echo esc_html( CQ_ATTENDEE_AUTHOR_LOCATION ); ?></span>
						</td>
					</tr>
					<tr>
						<td><strong>Find me</strong></td>
						<td>
							<?php
							$links = cq_attendee_author_links();
							$out   = array();
							foreach ( $links as $label => $href ) {
								$out[] = '<a href="' . esc_url( $href ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a>';
							}
							echo wp_kses_post( implode( ' &middot; ', $out ) );
							?>
						</td>
					</tr>
					<tr>
						<td><strong>Project</strong></td>
						<td><a href="<?php echo esc_url( CQ_ATTENDEE_PROJECT_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( CQ_ATTENDEE_PROJECT_URL ); ?></a></td>
					</tr>
					<tr>
						<td><strong>Support</strong></td>
						<td><a href="<?php echo esc_url( CQ_ATTENDEE_SUPPORT_URL ); ?>" target="_blank" rel="noopener noreferrer">Report a problem or request a feature</a></td>
					</tr>
					<tr>
						<td><strong>Licence</strong></td>
						<td>GPL-2.0-or-later</td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}
}
