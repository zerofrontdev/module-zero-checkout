<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Checkout\Model\RedirectPayment;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * The consumer zero.checkout.webhook: a queued webhook handled for its order.
 * When the provider can't be asked, the call goes back in the queue after a
 * pause, up to ATTEMPTS times; the order then keeps waiting, with a comment
 * for the shop (and the provider's dashboard has the answer).
 */
class WebhookConsumer
{
    public const ATTEMPTS = 5;

    /** @var callable(int): void seconds to wait before the next try */
    private $pause;

    public function __construct(
        private readonly Webhooks $webhooks,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderPayment $orderPayment,
        private readonly LoggerInterface $logger,
        ?callable $pause = null
    ) {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $this->pause = $pause ?? static fn (int $seconds) => sleep($seconds);
    }

    /**
     * @param string $body {"order_id": 12, "method": "zero_mollie", "data": {...}, "attempt": 1}
     */
    public function process(string $body): void
    {
        $message = json_decode($body, true);
        $orderId = (int) ($message['order_id'] ?? 0);
        $method = (string) ($message['method'] ?? '');
        $data = (array) ($message['data'] ?? []);
        $attempt = max(1, (int) ($message['attempt'] ?? 1));
        try {
            /** @var Order $order */
            $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException) {
            $this->logger->warning(sprintf('Zero Checkout: webhook for order %d, which no longer exists.', $orderId));
            return;
        }
        try {
            $this->webhooks->settle($order, $method, $data);
        } catch (NoWebhookHandlerException $e) {
            // Retrying can't help. Anything else, a lock held by another process included, is tried again.
            $this->logger->error($e->getMessage());
        } catch (\Throwable $e) {
            if ($attempt < self::ATTEMPTS) {
                ($this->pause)(min(5 * $attempt, 30));
                $this->webhooks->publish($order, $method, $data, $attempt + 1);
                return;
            }
            $this->orderPayment->flag(
                $order,
                (string) __(
                    'The payment provider could not be asked after its notification (%1 tries): %2. Check the payment in its dashboard.',
                    $attempt,
                    $e->getMessage()
                ),
                sprintf('webhook for %s not handled after %d tries: %s', $method, $attempt, $e->getMessage())
            );
        }
    }
}
