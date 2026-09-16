=== Attendee Details for WooCommerce ===
Contributors: rumanhossain
Tags: woocommerce, checkout, attendees, courses, bookings
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Collects a name, mobile and email for every place booked, driven by the quantity ordered. Choose exactly which products it applies to.

== Description ==

When someone books three places on a course, WooCommerce captures one name — the
person paying. This plugin asks for the details of each person attending,
on the checkout page, with one block of fields per place booked.

Answers are saved onto the order line item, so they appear on the order screen
and inside every order email automatically. No templates are edited and no
database tables are created.

Key points:

* Off by default. While disabled it registers no front-end hooks at all.
* Scoped to the products or categories you choose. Everything else is untouched.
* Collecting and requiring are separate switches, so you can gather details for
  a week without ever blocking a customer from paying.
* Orders that arrive without details (phone bookings, express wallet payments)
  are flagged in an Attendees column on the Orders list.

== Author ==

Built and maintained by **Md Ruman Hossain**, Rangpur, Bangladesh.

* Website: https://rumancsebrur.blogspot.com/
* GitHub: https://github.com/Ruman-Hossain
* LinkedIn: https://www.linkedin.com/in/ruman-hossain/
* Facebook: https://www.facebook.com/ruman.hossain.988/
* WordPress.org: https://profiles.wordpress.org/rumanhossain/

Project: https://github.com/Ruman-Hossain/attendee-details-for-woocommerce

== Support ==

Please open an issue on the project's tracker:
https://github.com/Ruman-Hossain/attendee-details-for-woocommerce/issues

Bug reports, feature requests and pull requests are all welcome. Licensed
GPL-2.0-or-later.

== Compatibility ==

* WooCommerce 6.0 and later, tested to 11.0.
* High-Performance Order Storage (HPOS): compatible, declared.
* Classic checkout. The block-based checkout is declared *not* supported, so
  WooCommerce will warn you rather than let the fields vanish silently.

== Changelog ==

= 1.0.0 =
* First release.
* Collects a name, mobile and email for every place booked, driven by the
  quantity ordered, on the products or categories you choose.
* Attendees shown as a table in order emails and on the customer's order page,
  with the course location read from the booked date.
* Optionally removes a shop's existing single-attendee line, without editing any
  template, theme or other plugin.
* Attendees and Location columns on the Orders list.
* Order-screen box for entering or correcting attendees on phone bookings.
* Optional validation, express-wallet guard, and order-notes handling.
