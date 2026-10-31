<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Controller\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Result\Layout;
use Magento\Framework\View\Result\LayoutFactory;
use Zero\Checkout\Model\AddressLookup\Pool;
use Zero\Core\Controller\PrivateFragmentActionInterface;

/**
 * Address suggestions under the street field (HTMX, as the shopper types):
 * the configured provider's matches for `street` in `country_id`, rendered
 * by checkout/address-suggestions.phtml. Nothing below 4 characters, or
 * when autocomplete is off. Answers are cached for a day per provider,
 * country and text: instant on repeats, fewer requests to the provider
 * (Photon's fair use, Google's billing).
 */
class AddressSuggest implements PrivateFragmentActionInterface
{
    public const TOKEN_KEY = 'zero_address_lookup_token';
    private const MIN_LENGTH = 4;
    private const BLOCK_NAME = 'zero.checkout.addresssuggestions';
    private const CACHE_LIFETIME = 86400;

    public function __construct(
        private readonly LayoutFactory $resultLayoutFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly Pool $pool,
        private readonly RequestInterface $request,
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * @return Layout
     */
    public function execute()
    {
        $query = $this->request->getParam('street', '');
        $query = trim(is_scalar($query) ? (string) $query : '');
        $country = $this->request->getParam('country_id', '');
        $country = strtoupper(trim(is_scalar($country) ? (string) $country : ''));
        $provider = $this->pool->get();
        $suggestions = [];
        if ($provider && mb_strlen($query) >= self::MIN_LENGTH && preg_match('/^[A-Z]{0,2}$/', $country)) {
            $query = mb_substr($query, 0, 200);
            $key = 'zero_address_suggest_' . sha1(get_class($provider) . '|' . $country . '|' . mb_strtolower($query));
            $cached = $this->cache->load($key);
            if ($cached !== false) {
                $suggestions = (array) json_decode((string) $cached, true);
            } else {
                $suggestions = $provider->suggest($query, $country, $this->token());
                if ($suggestions) {
                    $this->cache->save((string) json_encode($suggestions), $key, [], self::CACHE_LIFETIME);
                }
            }
        }

        $result = $this->resultLayoutFactory->create();
        $result->addDefaultHandle();
        $block = $result->getLayout()->getBlock(self::BLOCK_NAME);
        if ($block) {
            $block->setData('suggestions', $suggestions);
        }
        $result->getLayout()->addOutputElement(self::BLOCK_NAME);

        return $result;
    }

    /** One token per shopper until an address is picked (Google bills a session per token). */
    private function token(): string
    {
        $token = (string) $this->checkoutSession->getData(self::TOKEN_KEY);
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            $this->checkoutSession->setData(self::TOKEN_KEY, $token);
        }

        return $token;
    }
}
