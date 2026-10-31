<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Test\Unit\Fake;

use Magento\Framework\Lock\LockManagerInterface;

/**
 * An in-memory lock manager for unit tests: the locks this process holds,
 * names "held by another process" (holdByOther), and every lock/unlock call
 * in order, so a test can assert how often and with which name it locked.
 */
class FakeLockManager implements LockManagerInterface
{
    /** @var array<string, true> names held by this process */
    public array $held = [];
    /** @var array<string, true> names another process holds: lock() refuses them */
    private array $other = [];
    /** @var list<array{0: string, 1: string, 2?: int}> ['lock', name, timeout] / ['unlock', name] */
    public array $calls = [];

    public function holdByOther(string $name): void
    {
        $this->other[$name] = true;
    }

    public function lock(string $name, int $timeout = -1): bool
    {
        $this->calls[] = ['lock', $name, $timeout];
        if (isset($this->other[$name]) || isset($this->held[$name])) {
            return false;
        }
        $this->held[$name] = true;

        return true;
    }

    public function unlock(string $name): bool
    {
        $this->calls[] = ['unlock', $name];
        $was = isset($this->held[$name]);
        unset($this->held[$name]);

        return $was;
    }

    public function isLocked(string $name): bool
    {
        return isset($this->held[$name]) || isset($this->other[$name]);
    }

    /**
     * @return list<array{0: string, 1: string, 2?: int}> only the lock() calls
     */
    public function locks(): array
    {
        return array_values(array_filter($this->calls, static fn (array $call) => $call[0] === 'lock'));
    }

    /**
     * @return list<array{0: string, 1: string}> only the unlock() calls
     */
    public function unlocks(): array
    {
        return array_values(array_filter($this->calls, static fn (array $call) => $call[0] === 'unlock'));
    }
}
