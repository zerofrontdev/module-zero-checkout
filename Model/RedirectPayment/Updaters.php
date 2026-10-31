<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Model\RedirectPayment;

/**
 * The redirect-payment methods whose provider can be asked "was this order
 * paid?" by the recheck cron, by payment method code. A payment module adds
 * its OrderUpdaterInterface in its own global etc/di.xml.
 */
class Updaters
{
    /**
     * @param array<string, OrderUpdaterInterface> $updaters payment method code => updater
     */
    public function __construct(
        private readonly array $updaters = []
    ) {
    }

    public function get(string $method): ?OrderUpdaterInterface
    {
        $updater = $this->updaters[$method] ?? null;

        return $updater instanceof OrderUpdaterInterface ? $updater : null;
    }

    /**
     * @return array<string, OrderUpdaterInterface>
     */
    public function all(): array
    {
        return array_filter($this->updaters, static fn ($updater) => $updater instanceof OrderUpdaterInterface);
    }
}
