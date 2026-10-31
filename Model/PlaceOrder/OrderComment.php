<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Model\PlaceOrder;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Zero\Checkout\Api\AfterPlaceOrderInterface;
use Zero\Checkout\Api\PlaceOrderValidatorInterface;
use Zero\Checkout\Model\Config;

/**
 * The optional "order comment" field (checkout/order-comment.phtml, form field
 * `order_comment`). Before the order: at most MAX_LENGTH characters. After it:
 * stored as the order's customer_note (order e-mails read it) and as a
 * status-history comment visible on the storefront, which is what shows the
 * text to staff (order view, Comments History) and to the shopper (My Orders).
 */
class OrderComment implements PlaceOrderValidatorInterface, AfterPlaceOrderInterface
{
    public const FIELD = 'order_comment';
    public const MAX_LENGTH = 1000;

    public function __construct(
        private readonly Config $config,
        private readonly OrderRepositoryInterface $orderRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function validate(Quote $quote, array $request): void
    {
        if ($this->config->isOrderCommentEnabled($quote->getStoreId())
            && mb_strlen($this->comment($request)) > self::MAX_LENGTH
        ) {
            throw new LocalizedException(
                __('The order comment can be at most %1 characters long.', self::MAX_LENGTH)
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function execute(OrderInterface $order, array $request): void
    {
        $comment = $this->comment($request);
        if ($comment === '' || !$this->config->isOrderCommentEnabled($order->getStoreId())) {
            return;
        }

        /** @var Order $order */
        $order->setCustomerNote($comment);
        $order->addCommentToStatusHistory(__('Comment from the customer: %1', $comment)->render(), false, true);
        $this->orderRepository->save($order);
    }

    /**
     * @param array<string, mixed> $request
     */
    private function comment(array $request): string
    {
        $comment = $request[self::FIELD] ?? '';

        // An array (order_comment[]=x) counts as no comment.
        return is_scalar($comment) ? trim((string) $comment) : '';
    }
}
