<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Model\RedirectPayment;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * The customer is back from the provider's page (a return controller or the
 * GraphQL mutation): runs the order's update under its lock and turns
 * whatever happens into a ReturnResult state.
 *
 * - lock held by another process: PENDING;
 * - cancelled and flagged as paid after the cancellation: PENDING, the
 *   provider isn't asked and the cart stays as it is (no second payment);
 * - provider can't be asked, or anything unexpected: UNCONFIRMED.
 */
class ReturnFlow
{
    public function __construct(
        private readonly OrderLock $lock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param callable(Order): string $update returns a ReturnResult state
     * @param callable(Order): void|null $onNotStarted runs when the state is NOT_STARTED
     *     (e.g. to make the provider's link unpayable); the state stays NOT_STARTED
     * @return string a ReturnResult state
     */
    public function run(Order $order, callable $update, ?callable $onNotStarted = null): string
    {
        try {
            return $this->lock->run($order, function (Order $fresh) use ($update, $onNotStarted): string {
                if ($fresh->getState() === Order::STATE_CANCELED && $this->paidAfterCancel($fresh)) {
                    return ReturnResult::PENDING;
                }
                $state = $update($fresh);
                if ($state === ReturnResult::NOT_STARTED && $onNotStarted !== null) {
                    $onNotStarted($fresh);
                }

                return $state;
            });
        } catch (OrderLockedException) {
            return ReturnResult::PENDING;
        } catch (LocalizedException) {
            return ReturnResult::UNCONFIRMED;
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Zero Checkout: return of order %s: %s: %s',
                $order->getIncrementId(),
                $e::class,
                $e->getMessage()
            ));

            return ReturnResult::UNCONFIRMED;
        }
    }

    private function paidAfterCancel(Order $order): bool
    {
        return (bool) $order->getPayment()?->getAdditionalInformation(OrderPayment::LATE_MARKER);
    }
}
