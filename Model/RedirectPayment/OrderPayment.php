<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Model\RedirectPayment;

use Magento\Framework\Notification\NotifierInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Psr\Log\LoggerInterface;

/**
 * What a provider's answer does to an order that waited in pending_payment
 * (Monobank, LiqPay, Mollie, PayPal, Klarna, Adyen):
 * - captured: paid, invoice registered;
 * - authorized: reserved, captured later; processing without an invoice;
 * - cancelled.
 * Captured and authorized send the order email. Each is done once (settled()).
 * A payment that arrives for an order already cancelled is flagged, never
 * booked (paidAfterCancel()).
 */
class OrderPayment
{
    /** Payment additional information: the provider's transaction id of a payment received after the cancellation. */
    public const LATE_MARKER = 'zero_paid_after_cancel';

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderSender $orderSender,
        private readonly NotifierInterface $notifier,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * ReturnResult::PAID or ReturnResult::FAILED once the order has been
     * settled; null while it waits for the payment.
     */
    public function settled(Order $order): ?string
    {
        return match ($order->getState()) {
            Order::STATE_PENDING_PAYMENT, Order::STATE_NEW => null,
            Order::STATE_CANCELED => ReturnResult::FAILED,
            default => ReturnResult::PAID,
        };
    }

    public function capture(Order $order, string $transactionId): void
    {
        $order->getPayment()
            ->setTransactionId($transactionId)
            ->setIsTransactionClosed(true)
            ->registerCaptureNotification((float) $order->getBaseGrandTotal(), true);
        $this->save($order);
    }

    public function authorize(Order $order, string $transactionId): void
    {
        $order->getPayment()
            ->setTransactionId($transactionId)
            ->setIsTransactionClosed(false)
            ->registerAuthorizationNotification((float) $order->getBaseGrandTotal());
        $this->save($order);
    }

    public function cancel(Order $order, string $reason): void
    {
        if ($order->canCancel()) {
            $order->registerCancellation($reason);
            $this->orderRepository->save($order);
        }
    }

    /**
     * The provider reports something the order doesn't match (another amount
     * or currency): the order keeps waiting, with a comment for the shop.
     */
    public function flag(Order $order, string $comment, string $details): void
    {
        $this->logger->error('Zero Checkout: order ' . $order->getIncrementId() . ': ' . $details);
        $order->addCommentToStatusHistory($comment);
        $this->orderRepository->save($order);
    }

    /**
     * The provider holds a payment for an order that was cancelled: the order
     * stays cancelled (stock and totals were released), the shop is told once
     * (history comment, error log, admin notice) to refund or release it
     * (a held payment) at the provider.
     *
     * @return bool false when there is nothing to flag: the order is not cancelled or was flagged before
     */
    public function paidAfterCancel(Order $order, string $transactionId, string $details): bool
    {
        $payment = $order->getPayment();
        if ($order->getState() !== Order::STATE_CANCELED || $payment === null
            || $payment->getAdditionalInformation(self::LATE_MARKER)
        ) {
            return false;
        }
        $payment->setAdditionalInformation(self::LATE_MARKER, $transactionId);
        $order->addCommentToStatusHistory(
            (string) __(
                'Payment %1 was received after this order was cancelled. The order stays cancelled: refund or release it in the provider\'s account.',
                $transactionId
            )
        );
        $this->orderRepository->save($order);
        $this->logger->error('Zero Checkout: order ' . $order->getIncrementId() . ': paid after cancel: ' . $details);
        $this->notifier->addMajor((string) __('Payment received for cancelled order #%1', $order->getIncrementId()), $details);

        return true;
    }

    /**
     * The order waited too long: asks the provider once more ($ask may settle
     * the order) and cancels it only when it is still waiting. A provider that
     * can't be asked leaves the order alone.
     *
     * @param callable(Order): mixed $ask
     * @return bool true when the order was cancelled
     */
    public function cancelUnlessPaid(Order $order, string $reason, callable $ask): bool
    {
        if ($this->settled($order) !== null) {
            return false;
        }
        try {
            $ask($order);
        } catch (\Throwable $e) {
            $this->logger->warning('Zero Checkout: order ' . $order->getIncrementId() . ' not cancelled, the provider could not be asked: ' . $e->getMessage());
            return false;
        }
        if ($this->settled($order) !== null) {
            return false;
        }
        $this->cancel($order, $reason);

        return $order->getState() === Order::STATE_CANCELED;
    }

    /**
     * A note on the order, e.g. for a declined attempt: history comment and
     * save, nothing logged as an error.
     */
    public function comment(Order $order, string $comment): void
    {
        $order->addCommentToStatusHistory($comment);
        $this->orderRepository->save($order);
    }

    private function save(Order $order): void
    {
        $this->orderRepository->save($order);
        if (!$order->getEmailSent()) {
            try {
                $this->orderSender->send($order);
            } catch (\Throwable $e) {
                $this->logger->warning('Zero Checkout: order email for ' . $order->getIncrementId() . ': ' . $e->getMessage());
            }
        }
    }
}
