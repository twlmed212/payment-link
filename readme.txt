=== SysSell Payment Link ===
Contributors: twlmed212
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 8.6
WC tested up to: 11.1
Stable tag: 1.0.1
License: GPL-2.0-or-later

"Credit / Debit Card – Pay by Invoice": customers order now and pay by card through an invoice you email them (for example a Stripe invoice).

== Description ==

* Checkout method (classic checkout and the Checkout block) with card logos. Nothing is charged at checkout and no card data is collected: the order goes On hold.
* Order screen → "Card payment link" box: paste the link, Save & email link. The customer gets it at once; the clock starts.
* Reminders with the same link (default days 2, 6, 13, 20, 27 — the last one names the cancel date), then the unpaid order is cancelled on day 30 (stock released) and the customer is told. Every step is an order note; planned steps are in Tools → Scheduled Actions (group "syssell-payment-link").
* Changing the order to Processing or Completed (payment received) ends the reminders; License Manager then delivers the key.
* Orders list → "Payment link" column: Link needed / Sent · n/5 reminders / Paid / Expired.
* Settings: WooCommerce → Settings → Payments → Card Payment by Invoice. Emails: WooCommerce → Settings → Emails → "Card invoice" and "Card invoice expired".
* With the License Shop theme: the order status card says "Your invoice is on its way" / "Your invoice is ready" with a "Pay invoice" button (filter `license_shop_order_state`), and the Meta Pixel sends Lead instead of Purchase (filter `license_shop_meta_pixel_order_event`).
