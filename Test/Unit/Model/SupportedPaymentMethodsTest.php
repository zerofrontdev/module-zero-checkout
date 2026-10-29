<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Test\Unit\Model;

use Magento\Framework\Phrase;
use Magento\Framework\Phrase\RendererInterface;
use PHPUnit\Framework\TestCase;
use Zero\Checkout\Model\SupportedPaymentMethods;

class SupportedPaymentMethodsTest extends TestCase
{
    private ?RendererInterface $renderer = null;

    protected function setUp(): void
    {
        $this->renderer = Phrase::getRenderer();
        $translator = $this->createMock(RendererInterface::class);
        $translator->method('render')->willReturnCallback(
            fn (array $source) => ['Pay on our page.' => 'Оплата на нашій сторінці.'][$source[0]] ?? $source[0]
        );
        Phrase::setRenderer($translator);
    }

    protected function tearDown(): void
    {
        Phrase::setRenderer($this->renderer);
    }

    public function testARegisteredDescriptionIsShownTranslated(): void
    {
        // di.xml values are plain strings: the line under the method is translated when shown.
        $methods = new SupportedPaymentMethods(['acme_pay' => 'Pay on our page.']);

        $this->assertSame('Оплата на нашій сторінці.', $methods->getDescription('acme_pay'));
        $this->assertTrue($methods->isSupported('acme_pay'));
    }

    public function testAMethodWithoutADescriptionHasNone(): void
    {
        $methods = new SupportedPaymentMethods(['acme_pay' => '']);

        $this->assertSame('', $methods->getDescription('acme_pay'));
        $this->assertSame('', $methods->getDescription('unknown'));
    }
}
