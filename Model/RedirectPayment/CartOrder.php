<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Model\RedirectPayment;

use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;

/**
 * The order a headless storefront placed from a (masked) cart: the cart id is
 * the storefront's proof it may see and finish the order, the order number
 * says which one. A mismatch is "no such order", never a hint.
 */
class CartOrder
{
    public function __construct(
        private readonly MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId,
        private readonly CollectionFactory $orders,
        private readonly CartRepositoryInterface $quotes,
        private readonly ManagerInterface $eventManager
    ) {
    }

    /**
     * @throws NoSuchEntityException
     */
    public function get(string $maskedCartId, string $orderNumber, int $storeId): Order
    {
        try {
            $quoteId = $this->maskedQuoteIdToQuoteId->execute($maskedCartId);
        } catch (NoSuchEntityException) {
            $quoteId = 0;
        }
        /** @var Order $order */
        $order = $this->orders->create()
            ->addFieldToFilter('increment_id', $orderNumber)
            ->addFieldToFilter('store_id', $storeId)
            ->setPageSize(1)
            ->getFirstItem();
        if (!$order->getId() || (int) $order->getQuoteId() !== $quoteId) {
            throw new NoSuchEntityException(__('Could not find an order "%1" placed from this cart.', $orderNumber));
        }

        return $order;
    }

    /**
     * The cart is active again (same id) after a failed or abandoned payment,
     * as Magento\Checkout\Model\Session::restoreQuote() does for the theme.
     */
    public function restoreQuote(Order $order): void
    {
        try {
            $quote = $this->quotes->get((int) $order->getQuoteId());
        } catch (NoSuchEntityException) {
            return;
        }
        $quote->setIsActive(true)->setReservedOrderId(null);
        $this->quotes->save($quote);
        $this->eventManager->dispatch('restore_quote', ['order' => $order, 'quote' => $quote]);
    }
}
