# Attendee Details for WooCommerce

Collects a name — and optionally a mobile number and email address — for **every place booked**, on the checkout page, driven by the quantity ordered.

Built for course and training bookings, where the person paying is often not the person attending, and where booking three places previously captured only one name.

**Author:** Md Ruman Hossain — https://rumancsebrur.blogspot.com/
**Project:** https://github.com/Ruman-Hossain/attendee-details-for-woocommerce
**Version:** 1.0.0
**Licence:** GPL-2.0-or-later
**Requires:** WordPress 5.8+, WooCommerce 6.0+, PHP 7.4+

## Compatibility

Declared in the plugin header and to WooCommerce itself, so both show it
properly rather than guessing:

| | |
|---|---|
| WordPress | 5.8 → 7.1 |
| WooCommerce | 6.0 → 11.0 |
| PHP | 7.4+ |
| HPOS (High-Performance Order Storage) | Compatible — declared |
| Classic checkout | Supported |
| Block checkout | **Not** supported — declared, so WooCommerce warns instead of the fields silently disappearing |
| Requires Plugins | `woocommerce` — WordPress will not let it activate without it |

Orders are read and written only through the `WC_Order` API, never by direct
post meta, which is what makes the HPOS declaration honest rather than hopeful.

## How many attendees?

There is no fixed limit and no "1 to 3". The number of blocks always equals the
quantity on that line — book 1 place and you get one block, book 12 and you get
twelve. **Maximum per line** is only a ceiling, so a mis-typed quantity of 400
cannot render 1,200 empty boxes; it defaults to 20 and can go to 50.

If a quantity ever exceeds the ceiling, the customer fills in the ceiling's
worth and the Orders list flags the line as short — `20/25 ⚠` — so it is
visible rather than silent.

## The name the booking already has

The only name WooCommerce captures today is the **billing name of whoever is
paying**. TrainTrack does not collect an attendee name at all — its per-date
form posts nothing but `add-to-cart`, `product_id`, `variation_id` and
`attribute_course-dates`. There is no second source of names to collide with.

That one name is often also attendee 1, so the plugin starts the first block off
with the billing name, phone and email (setting: **First attendee**). It is a
copy, not a link — the customer can overwrite it, which they will when the buyer
is an office manager who is not attending. For a guest who has not typed their
billing details yet, the "Same as my billing details" tick does the same job,
and keeps following along as they type.

---

## What it does

On the checkout page it renders one block of fields per place booked:

```
Safe Pass Course in Dublin — Sat, 19 Sep 2026
  Attendee 1 of 3   [ full name ] [ mobile ] [ email ]
  Attendee 2 of 3   [ full name ] [ mobile ] [ email ]
  Attendee 3 of 3   [ full name ] [ mobile ] [ email ]
```

Answers are saved onto the **order line item**, so they appear on the order
screen and inside every order email automatically — exactly as the course date
already does. No templates are edited and no database tables are created.

## In the order email

By default the attendees are printed as a table, just below the order totals —
the same place the shop's existing "Course Date:" and "Attendee:" lines appear:

```
Attendee details
┌───┬──────────────────┬──────────────┬───────────────────────────┐
│ # │ Name             │ Phone        │ Email                     │
├───┼──────────────────┼──────────────┼───────────────────────────┤
│ 1 │ Emeka Agulanne   │ 353892377534 │ emenikeagulanne@gmail.com │
│ 2 │ Seán O'Brien     │ 0871234567   │ sean@example.com          │
└───┴──────────────────┴──────────────┴───────────────────────────┘
```

The same table appears on the customer's order-received page and under
My account → Orders. Plain-text emails get a readable list instead.

It is printed onto `woocommerce_email_order_meta`, a hook WooCommerce provides
for exactly this. **No email template, theme file or other plugin is edited** —
including TrainTrack, which is left completely alone.

### The existing one-name line

Order emails on this shop already print a single `Attendee:` line — the billing
name, because that was the only name there was. Once the table lists everybody,
that line is a duplicate.

**Existing one-name line** removes it. The plugin holds the output of
`woocommerce_email_order_meta` in a buffer, drops the one paragraph whose bold
label matches, and hands everything else straight back. No template, theme file
or other plugin is edited, and:

- It only happens on orders that have attendee details to show instead. An order
  without them keeps its original line, untouched.
- If the wording ever changes and stops matching, nothing is removed — the email
  simply keeps its old line. It cannot silently delete the wrong thing.
- Untick the setting and the buffer is never started at all.

`Course Date:` and every other plugin's output on that hook pass through
unchanged.

### Course location

Nobody types this. On this shop each course *date* is a product variation, and
the variation already carries `_venue_location` (the ID of a Course Location
entry) and `_venue_eircode` — so booking the date has already chosen the venue.
The plugin reads it back and prints it under the table:

```
Course Location: Lucan Spa Hotel Dublin — K78 X3H3
```

It is also frozen onto the order line at checkout, so an order still shows the
venue it was actually sold as even if that date is later moved to another hotel,
and it appears as a **Location** column on the Orders list — which answers
"which venue is this order for?" without opening anything.

Both meta keys are settings, and a field holding the venue name as plain text
works just as well, so this is not tied to one shop's setup.

### No duplicate rows

Choosing the table layout also removes the individual attendee rows from the
product table in that email, so nobody is listed twice. Your admin order screen
still shows them both ways. Switch to **Inside the product rows** if you prefer
the old behaviour.

The table sets no background or text colour of its own — it borrows whatever
surrounds it, which is what keeps it readable inside this shop's dark email
template and on the light website.

## Safety design

This plugin runs on a live shop, so it is built to be inert until told otherwise.

1. **Off by default.** With "Enable" unticked, no front-end hooks are registered
   at all. The checkout behaves byte-for-byte as it did before.
2. **Scoped.** It only acts on products or categories you explicitly select.
   Anything else is untouched.
3. **Optional before mandatory.** Validation is a separate switch, so you can
   collect data for a week without ever blocking a customer from paying.
4. **No tables.** Data lives in WooCommerce's own line item meta. Deleting the
   plugin never loses an order's data.
5. **Fails open, not closed.** Every entry point bails out early if WooCommerce
   is missing, the cart is empty, or no selected product is present.
6. **Validation cannot trap a customer.** Rendering and validating happen in two
   different requests, and they can disagree — a theme or page builder might not
   fire the hook the fields are drawn on, leaving a customer with nothing to fill
   in but an order that will not go through. So the form posts a hidden marker,
   and an order is only ever rejected when that marker comes back. No fields on
   the page means no validation, and the customer gets through.

The fields are also drawn on a fallback hook
(`woocommerce_checkout_after_customer_details`) in case the primary one
(`woocommerce_before_order_notes`) is not fired. Whichever runs first wins; the
other does nothing.

## Settings

**WooCommerce → Attendee Details**

| Setting | Purpose |
|---|---|
| Enable | Master switch. Off = plugin does nothing. |
| Apply to products | Specific products. |
| …or whole categories | Easier to maintain — one category covers every venue. |
| When to ask | Every booking, or only when more than one place is booked. |
| Required | Stop the order if incomplete. Leave off to begin with. |
| Fields | Name is always collected; mobile and email optional. |
| First attendee | Start block 1 from the billing name, phone and email. |
| Express wallet guard | See below. |
| Maximum per line | Safety ceiling on how many blocks can render. |
| Heading / Wording | Customer-facing text. |
| Rules of entry notice | The warning shown above the fields. See below. |
| In order emails | Table, or inside the product rows. |
| Existing one-name line | Remove the shop's single `Attendee:` line. |
| Course location | Show the venue, read from the booked date. |
| Order notes box | Leave alone / reset to standard wording / hide. |

## A checkout field that asks the same thing

If the order notes box has been relabelled to ask for attendee details, it is
now asking twice. **Order notes box** handles it without editing whatever did
the relabelling:

- **Leave it exactly as it is** — default, nothing changes.
- **Put WooCommerce's standard wording back** — it becomes an ordinary notes box
  again.
- **Remove the box from the checkout** — gone entirely.

Either action applies only when a selected product is in the basket, and only
while the setting says so. Switch back to "leave it" and the original wording
returns, because nothing was ever deleted — the field is filtered on its way to
the screen.

## Making people give real details

A mandatory field only guarantees that *something* was typed. What stops three
places being booked as "John Smith", "John Smith", "John Smith" is telling
people what the names are for, at the moment they type them.

The **Rules of entry notice** is shown in a highlighted box directly above the
fields. The default wording:

> Important: only the people named here can attend. Please give each attendee's
> real name, mobile number and email address. Anyone whose details do not match
> this booking may be refused entry on the day, or charged again.

A copy of that wording is stored on the order as `_cq_attendees_notice` when the
order is placed. If the policy is reworded later, an old order still shows what
that customer was actually told — which is the difference between a policy you
can enforce and one you cannot.

Only write into that box what you are genuinely prepared to do on the day. A
threat the trainers do not act on teaches customers to ignore it.

## The express wallet problem

Apple Pay and Google Pay buttons on the **basket** page create an order without
ever loading the checkout page. Any field added to the checkout is skipped
entirely, and the resulting order looks completely normal.

The **Express wallet guard** setting hides those buttons on the basket page —
and only when a selected product is in the basket. The wallets keep working
inside the checkout, and on every other basket.

Without this setting on, "Required" cannot be guaranteed.

The guard is CSS-only by design: if a payment plugin ever changes its class
names the buttons simply reappear. Nothing breaks.

## Orders that arrive without details

Phone bookings created in admin, and any express payment that bypasses the
checkout, will have no attendee data. Those orders get:

- a red `0/3 ⚠` figure in the **Attendees** column on the Orders list
- an order note explaining why it is probably missing
- a hidden `_cq_attendees_missing` flag for future reporting

Prevention where possible; visibility where not.

## Hooks used

| Hook | Purpose |
|---|---|
| `woocommerce_before_order_notes` | Render the fields |
| `woocommerce_checkout_process` | Validate when required |
| `woocommerce_checkout_create_order` | Stamp the rules-of-entry wording onto the order |
| `woocommerce_checkout_create_order_line_item` | Save onto the line item |
| `woocommerce_checkout_order_processed` | Flag orders missing details |
| `wp_head` | Wallet guard CSS on the basket page |

Fields are rendered **above the order notes box**, deliberately outside the
order review panel — WooCommerce redraws that panel by AJAX whenever the
payment method or country changes, which would wipe anything typed inside it.

Validation always rebuilds what it expects from the **live cart**, never from
what was posted, because the customer can change the basket in another tab
after the checkout page was drawn.

## Data stored

Per line item:

- `Attendee 1`, `Attendee 2`, … — readable, shown in admin and emails
- `_cq_attendees` — hidden JSON copy, for future reporting

Per order:

- `_cq_attendees_missing` — `yes` when a line needed details and has none
- `_cq_attendees_notice` — the rules-of-entry wording as it stood that day

## Phone bookings and corrections

An order created in admin never passes through a checkout, so it can never be
*forced* to carry attendee details. The **Attendee details** box on the order
screen is how you fill them in afterwards — and how you fix a typo in a name
without touching the database.

It writes exactly the same meta the checkout writes, so a completed phone
booking is indistinguishable from one booked online: same rows on the order,
same table in the emails, and the red `0/3 ⚠` in the Attendees column turns
green as soon as it is saved.

Blank a row to remove that attendee. Saving replaces what is stored for that
order.

## Publishing to WordPress.org

The plugin folder, the main file and the text domain are all
`attendee-details-for-woocommerce`, which is what the directory requires: the
text domain must match the plugin slug, and the slug is taken from the folder.

The name follows the required pattern for third-party integrations — the
trademark goes at the end ("… for WooCommerce"), never at the start, so it
cannot be read as official.

No string uses another plugin's text domain. Where WooCommerce's own wording is
restored on the order-notes field, the strings are this plugin's own, because
borrowing `woocommerce` as a domain is both against the guidelines and fragile.

Before submitting, replace `LICENSE.txt` with the full verbatim GPL-2.0 text
from https://www.gnu.org/licenses/old-licenses/gpl-2.0.txt — the directory
expects the complete licence, not a summary.

## Removing it

Deactivate to stop it. Delete to remove it — that clears only its own settings
row. Every attendee answer already saved stays on its order.

## Not yet built

- Printable attendance sheet per course date and venue
- CSV export of attendees
- Support for gateways other than Payment Plugins' Stripe in the wallet guard
- Compatibility with the newer WooCommerce checkout *blocks* (this targets the
  classic checkout, which is what the site uses)

---

## Author

**Md Ruman Hossain** — Rangpur, Bangladesh

| | |
|---|---|
| Website | https://rumancsebrur.blogspot.com/ |
| GitHub | https://github.com/Ruman-Hossain |
| LinkedIn | https://www.linkedin.com/in/ruman-hossain/ |
| Facebook | https://www.facebook.com/ruman.hossain.988/ |
| WordPress.org | https://profiles.wordpress.org/rumanhossain/ |

- Project — https://github.com/Ruman-Hossain/attendee-details-for-woocommerce
- Support — https://github.com/Ruman-Hossain/attendee-details-for-woocommerce/issues

Support goes through the issue tracker rather than an email address, so no
private inbox sits in a file every customer can read.

Licensed GPL-2.0-or-later, the licence WordPress itself uses. That means anyone
may use, modify and redistribute it, including commercially, as long as the same
freedoms travel with it.

Author details live in one place — the constants at the top of
`cq-attendee-details.php` — so the plugin header, the Plugins-list links and the
About box on the settings screen can never drift out of step.

---

## Security

The site this was built for was compromised in August 2026, so the plugin is
written to add as little attack surface as possible.

**What it does not have.** No database tables. No custom REST routes. No AJAX
endpoints. No shortcodes. No file uploads or file writes. No `$wpdb` queries, so
no SQL to inject into. No `eval`, `unserialize`, `extract`, `create_function` or
shell functions anywhere in the codebase. No remote requests — it never phones
home, so there is nothing to hijack and no third-party host to trust.

**Input.** Only three places read request data:

| Where | Guard |
|---|---|
| Settings save | `current_user_can( 'manage_woocommerce' )` **and** `check_admin_referer()` before anything is read |
| Checkout fields (`cq_att`) | Runs inside WooCommerce's own checkout, after its nonce is verified. Every value passes `sanitize_text_field()`, emails additionally through `is_email()` |
| The rendered marker | Existence check only; the value is never used |

The posted attendee array is never iterated on its own keys. The plugin builds
the list of expected lines from the **live cart**, then looks up each one in the
POST. A crafted request cannot invent a line, exceed the quantity actually
bought, or write meta onto a line it did not pay for.

**Output.** Everything the plugin prints goes through `esc_html()`,
`esc_attr()`, `esc_url()` or `esc_textarea()`. The Orders-list column heading is
escaped when it is registered, because WooCommerce's list table prints headings
unescaped.

**The one deliberate raw echo** is in the email wallet — the buffered output of
`woocommerce_email_order_meta`, handed back after one paragraph is removed. That
is other plugins' finished markup being returned unchanged; escaping it there
would corrupt their output without making anything safer, and every byte of it
was going to be printed anyway.

**Capabilities.** The settings page checks `manage_woocommerce` both at
registration and again at the top of the callback.

**Direct access.** Every PHP file begins `defined( 'ABSPATH' ) || exit;`, and
every directory carries a silence `index.php`.

**Uninstall** deletes exactly one option row and nothing else.

### Two things to be aware of

1. **The venue meta key is a free text field.** Someone with
   `manage_woocommerce` could point it at a different meta key and surface that
   value in customer emails. They are a shop manager who can already read it, so
   this is not an escalation — but on a multi-user shop, treat the setting as
   trusted-role-only.
2. **Attendee details are other people's personal data.** Names, phone numbers
   and email addresses of people who are not the customer. Under GDPR they
   belong in your privacy policy and in any data-subject request. The plugin
   does not yet register WordPress's personal-data exporter or eraser — worth
   adding before this is sold to other shops.
