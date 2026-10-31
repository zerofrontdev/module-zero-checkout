# Changelog: zerofront/module-zero-checkout

## 1.3.6

- Redirect payments (the customer pays on the provider's page) are settled one way for every provider: the return, the webhook and the new recheck cron all update the order under one lock per order, on the order read again once the lock is held, so a return and a webhook arriving together no longer both change it.
- A payment that arrives for an order already cancelled is flagged, never booked: the order stays cancelled, gets a history comment, and the admin gets a major notice "Payment received for cancelled order #…" (once per order). Refund it in the provider's account.
- New setting Stores > Configuration > Zero > Checkout > Payment Webhooks > **Recheck Pending Payments** (off by default): every 15 minutes the cron `zero_checkout_recheck_pending` asks the provider about redirect-payment orders still pending payment (10 minutes to 7 hours old, 200 per run). It never cancels an order just because nobody has paid yet.
- An unpaid order no longer gets cancelled by a return that only says "not finished yet"; only a provider's definitive failure cancels it.
- Amounts sent to providers use each currency's minor units (JPY, KRW and ISK whole, KWD, BHD and OMR with three decimals), and refunds carry a key derived from the credit memo, so a retried credit memo never refunds twice.
- "Create an account" on the success page respects Require Emails Confirmation: the customer gets Magento's confirmation email and signs in after confirming, instead of being signed in at once.
- When placing an order fails, the event `zero_checkout_place_order_failed` lets payment modules release what they reserved; a failed after-order step is noted on the order's history.
- A form field sent as an array (`payment_method[]=x`) is treated as missing instead of causing a 500, and the checkout sign-in form shows Magento's reCAPTCHA when it is on for customer login.

Earlier releases (1.3.5 and before): see the Git tags.
