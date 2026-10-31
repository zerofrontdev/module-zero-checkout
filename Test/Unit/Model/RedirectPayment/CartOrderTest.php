<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Test\Unit\Model\RedirectPayment;

use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Collection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\Checkout\Model\RedirectPayment\CartOrder;
use Zero\Checkout\Model\RedirectPayment\ReturnResult;

/**
 * The headless storefront's order: found only from the cart it was placed
 * from (an unknown cart never matches an order without a quote), and its cart
 * made active again with zero_checkout_quote_restored only when the cart was
 * really inactive.
 */
#[AllowMockObjectsWithoutExpectations]
class CartOrderTest extends TestCase
{
    /** @var array<string, int> masked id => quote id */
    private array $masked = ['masked-7' => 7];
    /** @var array<string, mixed> field => value the order collection was filtered by */
    private array $filters = [];
    private Order $found;
    private CartRepositoryInterface&MockObject $quotes;
    private ManagerInterface&MockObject $events;
    /** @var list<string> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->found = $this->order(12, 7);
        $this->quotes = $this->createMock(CartRepositoryInterface::class);
        $this->events = $this->createMock(ManagerInterface::class);
        $this->events->method('dispatch')->willReturnCallback(function (string $name): void {
            $this->dispatched[] = $name;
        });
    }

    public function testTheOrderOfThisCart(): void
    {
        $this->assertSame($this->found, $this->cartOrder()->get('masked-7', '100000012', 3));
        $this->assertSame(['increment_id' => '100000012', 'store_id' => 3], $this->filters);
    }

    public function testUnknownCartIsNoSuchOrderEvenForAnOrderWithoutQuote(): void
    {
        $this->found = $this->order(12, 0);

        $this->expectException(NoSuchEntityException::class);
        $this->cartOrder()->get('unknown', '100000012', 3);
    }

    public function testAnotherCartsOrderIsNoSuchOrder(): void
    {
        $this->masked['masked-8'] = 8;

        $this->expectException(NoSuchEntityException::class);
        $this->cartOrder()->get('masked-8', '100000012', 3);
    }

    public function testNoOrderIsNoSuchOrder(): void
    {
        $this->found = $this->order(0, 7);

        $this->expectException(NoSuchEntityException::class);
        $this->cartOrder()->get('masked-7', '100000099', 3);
    }

    public function testRestoreOfAnInactiveCartDispatchesTheEvent(): void
    {
        $quote = $this->quote(false);
        $this->quotes->method('get')->with(7)->willReturn($quote);
        $this->quotes->expects($this->once())->method('save')->with($quote);
        $this->events->expects($this->exactly(2))->method('dispatch')->with(
            $this->anything(),
            ['order' => $this->found, 'quote' => $quote]
        );

        $this->cartOrder()->restoreQuote($this->found);

        $this->assertSame(['restore_quote', ReturnResult::QUOTE_RESTORED_EVENT], $this->dispatched);
        $this->assertTrue($quote->getIsActive());
    }

    public function testRestoreOfAnActiveCartDoesNotRepeatTheEvent(): void
    {
        $this->quotes->method('get')->willReturn($this->quote(true));

        $this->cartOrder()->restoreQuote($this->found);

        $this->assertSame(['restore_quote'], $this->dispatched);
    }

    public function testRestoreWithoutTheCartDoesNothing(): void
    {
        $this->quotes->method('get')->willThrowException(new NoSuchEntityException());
        $this->quotes->expects($this->never())->method('save');

        $this->cartOrder()->restoreQuote($this->found);

        $this->assertSame([], $this->dispatched);
    }

    private function cartOrder(): CartOrder
    {
        $masked = $this->createStub(MaskedQuoteIdToQuoteIdInterface::class);
        $masked->method('execute')->willReturnCallback(function (string $id): int {
            if (!isset($this->masked[$id])) {
                throw new NoSuchEntityException();
            }

            return $this->masked[$id];
        });
        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $value) use ($collection) {
            $this->filters[$field] = $value;

            return $collection;
        });
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturnCallback(fn (): DataObject => $this->found);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return new CartOrder($masked, $factory, $this->quotes, $this->events);
    }

    private function order(int $id, int $quoteId): Order
    {
        $order = $this->createStub(Order::class);
        $order->method('getId')->willReturn($id ?: null);
        $order->method('getQuoteId')->willReturn($quoteId);

        return $order;
    }

    private function quote(bool $active): Quote
    {
        $quote = $this->createStub(Quote::class);
        $quote->method('getIsActive')->willReturnCallback(static function () use (&$active) {
            return $active;
        });
        $quote->method('setIsActive')->willReturnCallback(static function ($value) use (&$active, &$quote) {
            $active = (bool) $value;

            return $quote;
        });
        $quote->method('setReservedOrderId')->willReturnSelf();

        return $quote;
    }
}
