<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Controller\Checkout;

use Magento\Customer\Model\Vat as VatService;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Result\Layout;
use Magento\Framework\View\Result\LayoutFactory;
use Zero\Core\Controller\PrivateFragmentActionInterface;

/**
 * Under the checkout's VAT number (HTMX, when it or the country changes):
 * the number checked in the EU's VIES service (Magento's own client), as
 * checkout/vat-check.phtml shows it: valid, not valid, or VIES unreachable.
 * Nothing for an empty number or a country outside the EU. A VIES answer is
 * kept for a day per country and number.
 */
class Vat implements PrivateFragmentActionInterface
{
    private const BLOCK_NAME = 'zero.checkout.vatcheck';
    private const CACHE_LIFETIME = 86400;

    public function __construct(
        private readonly LayoutFactory $resultLayoutFactory,
        private readonly RequestInterface $request,
        private readonly VatService $vat,
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * @return Layout
     */
    public function execute()
    {
        $country = $this->request->getParam('country_id', '');
        $country = strtoupper(trim(is_scalar($country) ? (string) $country : ''));
        $vatIdParam = $this->request->getParam('vat_id', '');
        $number = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', is_scalar($vatIdParam) ? (string) $vatIdParam : ''));
        $status = '';
        if ($number !== '' && strlen($number) <= 20 && preg_match('/^[A-Z]{2}$/', $country)
            && $this->vat->isCountryInEU($country)
        ) {
            $status = $this->check($country, $number);
        }

        $result = $this->resultLayoutFactory->create();
        $result->addDefaultHandle();
        $block = $result->getLayout()->getBlock(self::BLOCK_NAME);
        if ($block) {
            $block->setData('status', $status);
        }
        $result->getLayout()->addOutputElement(self::BLOCK_NAME);

        return $result;
    }

    /**
     * @return string valid, invalid or unavailable
     */
    private function check(string $country, string $number): string
    {
        $key = 'zero_checkout_vat_' . sha1($country . '|' . $number);
        $cached = $this->cache->load($key);
        if ($cached !== false) {
            return (string) $cached;
        }
        $response = $this->vat->checkVatNumber($country, $number);
        if (!$response->getRequestSuccess()) {
            return 'unavailable';
        }
        $status = $response->getIsValid() ? 'valid' : 'invalid';
        $this->cache->save($status, $key, [], self::CACHE_LIFETIME);

        return $status;
    }
}
