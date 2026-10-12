# Changelog

## 1.0.1 — 2026-10-12

- **"Invoice" wording for customers.** "Payment link" can read like a phishing message, so every customer text now speaks of an invoice: checkout title **Credit / Debit Card – Pay by Invoice**, "we’ll email you an invoice", "Your invoice is on its way / is ready", **Pay invoice – $X**, email subjects "Your invoice for order #…" and "Reminder: your invoice for order #… is still open". The emails are now called "Card invoice" / "Card invoice expired" in WooCommerce → Settings → Emails. Settings still holding a 1.0.0 default text are switched to the new one on update (edited texts stay).
- **Checkout note.** The method's description shows as a green note with an envelope icon (theme success colours, light and dark), on the Checkout block and the classic checkout.

## 1.0.0 — 2026-10-12

- First release: "Credit / Debit Card – Secure Payment Link" gateway (classic + block checkout, HPOS), the order-screen "Card payment link" box, emailed link with reminders (days 2, 6, 13, 20, 27) and auto-cancel on day 30, "Card payment link" / "Card payment link expired" emails, orders-list "Payment link" column, License Shop theme status card and Meta Pixel Lead integration.
