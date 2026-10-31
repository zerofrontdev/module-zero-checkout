<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Checkout\Model\RedirectPayment;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Sales\Model\Order;
use Zero\Checkout\Model\Config;

/**
 * A provider's verified webhook or callback, handled now, or from the queue
 * (Payment Webhooks > Process Through the Queue): the provider gets its
 * answer at once and the consumer zero.checkout.webhook brings the order in
 * line (WebhookConsumer). Handlers by payment method code, from di.xml.
 */
class Webhooks
{
    public const TOPIC = 'zero.checkout.webhook';

    /**
     * @param array<string, WebhookHandlerInterface> $handlers payment method code => handler
     */
    public function __construct(
        private readonly Config $config,
        private readonly PublisherInterface $publisher,
        private readonly OrderLock $orderLock,
        private readonly OrderPayment $orderPayment,
        private readonly array $handlers = []
    ) {
    }

    /**
     * @param array<string, mixed> $data what the call carried, verified
     * @throws LocalizedException when handled now and the provider can't be asked, or another process holds
     *     the order (OrderLockedException): answer 503, the provider calls again
     * @throws NoWebhookHandlerException for a method without a handler
     */
    public function handle(Order $order, array $data): void
    {
        $method = (string) $order->getPayment()?->getMethod();
        if ($this->config->isWebhookQueued()) {
            $this->publish($order, $method, $data, 1);
            return;
        }
        $this->settle($order, $method, $data);
    }

    /**
     * Brings the order in line with the provider, under the order's lock and
     * on the order as it is once the lock is held. For a cancelled order a
     * handler that is a LatePaymentInterface only asks whether the customer
     * paid anyway, and the order is flagged, never reopened.
     *
     * @param array<string, mixed> $data what the call carried, verified
     * @throws NoWebhookHandlerException for a method without a handler (before any lock)
     * @throws LocalizedException when the provider can't be asked or the lock is held by another process
     */
    public function settle(Order $order, string $method, array $data): void
    {
        $handler = $this->handler($method);
        $this->orderLock->run($order, function (Order $fresh) use ($handler, $method, $data): void {
            if ($fresh->getState() === Order::STATE_CANCELED && $handler instanceof LatePaymentInterface) {
                $late = $handler->latePayment($fresh);
                if ($late !== null) {
                    $this->orderPayment->paidAfterCancel(
                        $fresh,
                        $late['id'],
                        sprintf('%s %s %s paid', $method, $late['amount'], $late['currency'])
                    );
                }
                return;
            }
            $handler->webhook($fresh, $data);
        });
    }

    /**
     * @throws NoWebhookHandlerException for a method without a handler
     */
    public function handler(string $method): WebhookHandlerInterface
    {
        $handler = $this->handlers[$method] ?? null;
        if (!$handler instanceof WebhookHandlerInterface) {
            throw new NoWebhookHandlerException(sprintf('Zero Checkout: no webhook handler for payment method "%s".', $method));
        }
        return $handler;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function publish(Order $order, string $method, array $data, int $attempt): void
    {
        $this->publisher->publish(self::TOPIC, json_encode([
            'order_id' => (int) $order->getId(),
            'method' => $method,
            'data' => $data,
            'attempt' => $attempt,
        ]));
    }
}
