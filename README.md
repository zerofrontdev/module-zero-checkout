# Zero_Checkout

A one-page checkout for Magento Open Source that replaces the Knockout
checkout: server-rendered HTML, one native `<form>`, HTMX for the parts that
refresh (shipping rates, totals, regions, saved addresses, discount code).
Offline payment methods work with JavaScript turned off.

## Install

Requires Magento Open Source 2.4.7–2.4.9 and PHP 8.2–8.4 with the GD extension built with WebP (`php -r 'var_dump(function_exists("imagewebp"));'` says `true`; Magento's own PHP requirements cover the rest). Without WebP in GD, Zero skips its WebP image copies and serves the originals.

```bash
composer require zerofront/module-zero-checkout
bin/magento setup:upgrade
bin/magento setup:di:compile           # production mode
bin/magento setup:static-content:deploy # production mode
bin/magento cache:flush
```

Composer installs `zerofront/module-zero-core` with it.

Part of [`zerofront/zero`](https://zerofront.dev), which installs the Zero theme and every Zero module at once.

## Features

| Feature | Where |
| --- | --- |
| Contact, shipping and billing addresses, saved-address picker | `checkout/index.phtml`, `shipping-address-fields.phtml`, `billing-address-fields.phtml` |
| Default shipping address prefilled for signed-in customers; new addresses saved to the address book | `Controller/Checkout/Index`, `Model/Checkout/ShippingAddressUpdater` |
| Browser autofill (`autocomplete` tokens per section) | address templates |
| Live shipping rates and totals | `Controller/Checkout/Shippingmethods` |
| Discount code in the order summary | `Controller/Checkout/Coupon`, `coupon.phtml` |
| Order comment, saved on the order | `Model/PlaceOrder/OrderComment`, `order-comment.phtml` |
| Terms and Conditions, checked on the server | `Model/PlaceOrder/AgreementsValidator`, `agreements.phtml` |
| Sign in from the checkout page | `Controller/Checkout/Login`, `login.phtml` |
| Order summary with item options | `ViewModel/Checkout/CheckoutPage` |
| Payment: offline methods, purchase order; Braintree, Stripe, Authorize.Net, PayPal Standard from their own Zero modules | `zero.checkout.payment.*` containers |

## Settings

Stores > Configuration > **Zero** > **Checkout**:

| Group | Setting | Path | Default |
| --- | --- | --- | --- |
| Zero Checkout | Enabled | `checkout/zero_checkout/enabled` | Yes |
| Checkout Page | Discount Code Field | `checkout/zero_checkout/coupon` | Yes |
| Checkout Page | Order Comment | `checkout/zero_checkout/order_comment` | Yes |
| Checkout Page | Sign In at Checkout | `checkout/zero_checkout/login` | Yes |
| Terms and Conditions | Enable Terms and Conditions | `checkout/options/enable_agreements` (Magento's) | No |
| Payment Webhooks | Process Through the Queue | `checkout/zero_checkout/webhook_queue` | No |
| Payment Webhooks | Recheck Pending Payments | `checkout/zero_checkout/recheck_pending` | No |

The agreements are edited in Stores > Terms and Conditions. Payment methods
are configured in Sales > Payment Methods.

**Payment webhooks through the queue.** Mollie, Klarna, Adyen, monobank and
LiqPay tell the store about a payment with a webhook or callback. The
controller checks that the call is the provider's and about one of its orders
(signature, the order's own payment id), then hands it to
`Model\RedirectPayment\Webhooks`: with the setting off, the handler runs while
the provider waits for the answer (503 when the provider can't be asked, so it
calls again); with it on, the call is published to the topic
`zero.checkout.webhook` and answered at once, and the consumer of the same name
runs the handler. Magento's queue framework carries it: RabbitMQ when
`env.php` has `queue/amqp`, the `queue_message` table otherwise. Cron starts
the consumer (`cron_consumers_runner`, on by default); it can also run on its
own: `bin/magento queue:consumers:start zero.checkout.webhook`. When the
provider can't be asked from the consumer, the call goes back in the queue
after a pause, five tries in all, then the order gets a comment and keeps
waiting. Each payment module registers its handler
(`Model\RedirectPayment\WebhookHandlerInterface`) by method code in its
`di.xml`; a module adding a redirect payment does the same. Run
`bin/magento setup:upgrade` after installing: it creates the queue.

## Settling a redirect payment

A redirect payment (the customer pays on the provider's page) is settled by
whichever comes first: the customer's return, the provider's webhook, a cron
recheck. All of them go through `Model\RedirectPayment`:

**One order, one update at a time (`OrderLock`).** Every update of an order
runs under the lock `zero_redirect_payment_<increment id>` on the order read
again after the lock is taken (never a copy cached earlier in the request).
The lock is re-entrant within one process. A webhook and a return wait up to
10 seconds for it, the cron not at all. Lock busy: the webhook controller
answers 503 (`OrderLockedException` is a `LocalizedException`), so the
provider calls again; the queue consumer tries again later; the customer sees
"processing"; the cron skips the order.

**The return (`ReturnFlow`).** A return controller calls
`ReturnFlow::run($order, $update, $onNotStarted)`. It locks the order,
runs the provider update (`OrderUpdaterInterface::update()`, which returns a
`ReturnResult` state) and turns failures into a state: lock busy is
`PENDING`, the provider not answering (`LocalizedException`) is
`UNCONFIRMED`, anything else is logged and `UNCONFIRMED`. `$onNotStarted`
runs only on `NOT_STARTED`, for the providers whose link can be made
unpayable (monobank, Klarna, Mollie, Adyen, Amazon Pay): the link is
withdrawn and the order cancelled. LiqPay passes it too, but only cancels
(its link is not made unpayable; P6-3, see below). `ReturnResult` then sends the customer to
the success page or back to the checkout with the cart restored.

**A payment for a cancelled order is flagged, never booked
(`LatePaymentInterface`).** `Webhooks::settle` checks the fresh order first:
when it is cancelled and the method's handler implements
`LatePaymentInterface`, the handler only asks the provider's status
(`latePayment()`, read-only: no capture, no authorization, no save) and, when
the provider holds a payment, `OrderPayment::paidAfterCancel` adds a history
comment, logs an error and raises a major admin notice ("Payment received for
cancelled order #…"), once per order (the marker `zero_paid_after_cancel` in
the payment's additional information). The order stays cancelled; it is never
reopened or invoiced: refund the payment in the provider's account. A
customer coming back to such an order sees "processing" and the cart is not
restored, so they aren't invited to pay twice. A handler without the
interface still gets the cancelled order in `webhook()`, as before.

**Recheck of pending orders (`Cron\RecheckPending`, `Updaters`).** With
Payment Webhooks > Recheck Pending Payments on (it is **off by default**), the
cron job `zero_checkout_recheck_pending` (every 15 minutes) asks the provider
about orders in `pending_payment` 10 minutes to 7 hours old, at most 200 per
run, oldest first, for the methods registered in
`Zero\Checkout\Model\RedirectPayment\Updaters` (each payment module adds
its `OrderUpdaterInterface` in its global `etc/di.xml`). The cron never
cancels by itself; an updater cancels only when the provider reports a
definitive failure. Only updaters that never cancel because nobody has paid
yet are registered: LiqPay and WayForPay are not, because they still cancel
on a declined or unstarted payment (see below), and neither is Portmone,
which cancels an order whose only documents are failed attempts while the
customer may still be retrying on Portmone's page. Afterpay and Zip, which
capture on the store's own call, are not registered either: their own crons
recheck, and their expiry asks the provider before cancelling
(`OrderPayment::cancelUnlessPaid`; a provider that can't be asked leaves the
order alone).

**What Magento does on its own.** An order still in `pending_payment` after
Stores > Configuration > Sales > Sales > Orders Cron Settings > **Pending
Payment Order Lifetime** (`sales/orders/delete_pending_after`, 480 minutes by
default) is cancelled by Magento's hourly cron `sales_clean_orders`, without
asking the provider. A payment that arrives after that is flagged as above.

**LiqPay and WayForPay still cancel on a decline.** A declined attempt
(LiqPay `failure`/`error`, WayForPay `Declined`), and a LiqPay return with
`payment_not_found`, cancel the order as before, until a sandbox test proves
the provider's link stays payable after a decline. Money arriving for such an
order later is flagged by the late-payment check.

**The cart restored (`zero_checkout_quote_restored`).** Whenever the cart of
a still-unpaid redirect order is made active again (`ReturnResult` on the
storefront, `CartOrder::restoreQuote`), the event
`zero_checkout_quote_restored` is dispatched with `order` and `quote`, so a
module that tied the cart to the order can let go of it (Zero_QuoteRequest
releases its quote claim). It may fire more than once for the same order
(a return checked again): a listener checks that the order is the one it
cares about.

**Money claims at placement.** What must be taken or reserved atomically
when an order is placed (a company's credit, a quote request's claim) sits
in around plugins on `Magento\Sales\Api\OrderManagementInterface::place()`
(Zero_QuoteRequest sortOrder 10 outside Zero_NetTerms 20; MSI's
`inventory_reservations_placement` has no sortOrder and wraps outside both), the
one point the theme, GraphQL, REST and the admin share; each plugin undoes
its own work when placing fails. `PlaceOrderValidatorInterface` checks are
form checks only: they run before
the totals are collected and have no release path.

**Amounts and refund keys (`Model\Money`).** `MinorUnits` turns an amount
into a currency's smallest unit and back (`toMinor`, `fromMinor`,
`format`), with ISO 4217 exponents and each provider's exceptions (`STRIPE`,
`ADYEN`, `KLARNA`, `AMAZON`, `PAYPAL`). `RefundKey::for($payment,
$baseAmount, $prefix, $maxLength)` gives a refund's idempotency key or
reference from what is persisted (the payment's amount refunded so far and
this refund's amount): the same when Magento retries the same credit memo,
different for the next partial refund. Both are static, no DI.

## Extending the checkout

Four extension points, all wired with layout XML or `di.xml`; nothing in
Zero_Checkout needs to change.

### 1. Add a field or a block to the page

Layout containers in `zero_checkout_index.xml`:

| Container | Position | Inside the checkout form? |
| --- | --- | --- |
| `zero.checkout.login` (block) | above the form | no |
| `zero.checkout.shipping.after` | right after the shipping methods (a carrier's own fields) | yes |
| `zero.checkout.payment.express` | above the payment methods | yes |
| `zero.checkout.payment.forms` | below the payment methods (card fields) | yes |
| `zero.checkout.summary.totals.before` | order summary, above the totals | yes |
| `zero.checkout.place-order.before` | right above Place Order | yes |

Fields inside the form are posted with the order.

```xml
<!-- Acme/GiftWrap/view/frontend/layout/zero_checkout_index.xml -->
<page xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
      xsi:noNamespaceSchemaLocation="urn:magento:framework:View/Layout/etc/page_configuration.xsd">
    <body>
        <referenceContainer name="zero.checkout.place-order.before">
            <block class="Magento\Framework\View\Element\Template" name="acme.giftwrap"
                   template="Acme_GiftWrap::checkout/gift-wrap.phtml"/>
        </referenceContainer>
    </body>
</page>
```

```php
<!-- Acme/GiftWrap/view/frontend/templates/checkout/gift-wrap.phtml -->
<label class="flex items-center gap-2 mt-4 text-sm">
    <input type="checkbox" name="acme_gift_wrap" value="1" class="checkbox checkbox-sm" />
    <?= $escaper->escapeHtml(__('Gift-wrap this order')) ?>
</label>
```

Style with the classes Zero's templates already use, or the theme's
Tailwind build won't include them (see the theme's `docs/child-themes.md`).

### 2. Check something before the order is placed

Implement `Zero\Checkout\Api\PlaceOrderValidatorInterface`. It runs after the
form's addresses, shipping method and payment are on the quote; throw
`LocalizedException` to stop the order, and the message is shown on the
checkout page.

A validator is a form check: it runs before the totals are collected and
nothing undoes it when placing fails later. Claiming money or a limited
resource belongs in an around plugin on
`Magento\Sales\Api\OrderManagementInterface::place()` (see "Money claims at
placement" above).

```php
class GiftWrapValidator implements \Zero\Checkout\Api\PlaceOrderValidatorInterface
{
    public function validate(\Magento\Quote\Model\Quote $quote, array $request): void
    {
        if (!empty($request['acme_gift_wrap']) && $quote->getIsVirtual()) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Virtual items can\'t be gift-wrapped.'));
        }
    }
}
```

### 3. Do something once the order exists

Implement `Zero\Checkout\Api\AfterPlaceOrderInterface`. A failure here is
logged and the shopper still reaches the success page.

```php
class SaveGiftWrap implements \Zero\Checkout\Api\AfterPlaceOrderInterface
{
    public function execute(\Magento\Sales\Api\Data\OrderInterface $order, array $request): void
    {
        if (!empty($request['acme_gift_wrap'])) {
            $order->addCommentToStatusHistory('Gift wrap requested.');
            $this->orderRepository->save($order);
        }
    }
}
```

Register both:

```xml
<!-- Acme/GiftWrap/etc/frontend/di.xml -->
<type name="Zero\Checkout\Controller\Checkout\Placeorder">
    <arguments>
        <argument name="validators" xsi:type="array">
            <item name="acme_gift_wrap" xsi:type="object">Acme\GiftWrap\Model\GiftWrapValidator</item>
        </argument>
        <argument name="afterPlaceOrder" xsi:type="array">
            <item name="acme_gift_wrap" xsi:type="object">Acme\GiftWrap\Model\SaveGiftWrap</item>
        </argument>
    </arguments>
</type>
```

Items are keyed by name, so a module can also replace or remove a built-in
one (`agreements`, `order_comment`) by reusing its key.

### 4. Add a payment method

Zero Checkout lists only the payment methods it knows how to complete. A
payment module registers its method code (and an optional one-line
description) with `Zero\Checkout\Model\SupportedPaymentMethods`:

```xml
<!-- Acme/Pay/etc/frontend/di.xml -->
<type name="Zero\Checkout\Model\SupportedPaymentMethods">
    <arguments>
        <argument name="methods" xsi:type="array">
            <item name="acme_pay" xsi:type="string" translate="true">Pay with your Acme wallet.</item>
        </argument>
    </arguments>
</type>
```

Then, depending on the method:

- **Offline / redirect after placing** — nothing else; a method that needs an
  off-site step puts the payment page's URL in the order payment's
  additional information as `zero_redirect_url`
  (`Placeorder::REDIRECT_URL`) while the order is placed, and the customer
  goes there instead of the success page (see `Zero_Monobank`,
  `Zero_LiqPay`).
- **Card fields or a client SDK** — add the form to
  `zero.checkout.payment.forms` and show it only while the method is selected
  (`#payment-methods:has(input[value="acme_pay"]:checked) ~ #acme-pay-panel`
  in CSS, as Zero_Braintree, Zero_Stripe and Zero_AuthorizeNet do). The
  script tokenizes on submit into a hidden field named
  `payment_additional_data[<key>]`; Placeorder puts every such field into the
  payment's `additional_data`, where your method's `assignData()` (or an
  observer) reads it.

Zero_Braintree is the smallest complete example: a view model, a template, a
script and one layout file.

## What not to change

- The checkout `<form>` posts to `zero/checkout/placeorder`; don't nest other
  `<form>` elements inside it (use `type="button"` + `hx-post`, like the
  discount code).
- `hx-sync="#shipping-methods:replace"` on address fields keeps the newest
  rate request winning.
- `form_key` is in the form; HTMX posts include it with
  `hx-include="[name='form_key']"`.

What changed in each release: [CHANGELOG.md](CHANGELOG.md).

## License

OSL-3.0 or AFL-3.0, your choice: see `NOTICE.md`.

---

<a href="https://vilmate.com"><picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/zerofrontdev/zero/main/docs/images/vilmate-logo-dark.svg">
  <img src="https://raw.githubusercontent.com/zerofrontdev/zero/main/docs/images/vilmate-logo.svg" alt="Vilmate" height="34" align="right">
</picture></a>

Zero is made by [Fixstacks](https://fixstacks.com) in partnership with [Vilmate](https://vilmate.com), a software development company.
Documentation: https://zerofront.dev/docs. Demo store: https://demo.zerofront.dev.
