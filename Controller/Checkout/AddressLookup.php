<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Checkout\Controller\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Result\Layout;
use Magento\Framework\View\Result\LayoutFactory;
use Zero\Checkout\Model\AddressLookup\Pool;
use Zero\Checkout\Model\Checkout\ShippingAddressUpdater;
use Zero\Core\Controller\PrivateFragmentActionInterface;

/**
 * A picked address suggestion (HTMX): its street, city, region, postcode and
 * country go into the quote's shipping address, together with what the
 * shopper already typed in the form (name, phone, email), and the answer is
 * the same as for a saved address: the address fields re-rendered, plus
 * shipping rates and totals out of band (zero_checkout_address handle).
 */
class AddressLookup implements PrivateFragmentActionInterface
{
    private const FORM_FIELDS = ['firstname', 'lastname', 'company', 'telephone', 'email'];

    public function __construct(
        private readonly LayoutFactory $resultLayoutFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly Pool $pool,
        private readonly ShippingAddressUpdater $addressUpdater,
        private readonly RegionFactory $regionFactory,
        private readonly RequestInterface $request
    ) {
    }

    /**
     * @return Layout
     */
    public function execute()
    {
        $provider = $this->pool->get();
        $id = $this->request->getParam('lookup_id', '');
        $id = is_scalar($id) ? (string) $id : '';
        $token = (string) $this->checkoutSession->getData(AddressSuggest::TOKEN_KEY);
        $address = $provider && $id !== '' && strlen($id) < 4096 ? $provider->details($id, $token) : null;

        if ($address !== null) {
            $data = [];
            foreach (self::FORM_FIELDS as $field) {
                $data[$field] = $this->request->getParam($field, '');
                $data[$field] = trim(is_scalar($data[$field]) ? (string) $data[$field] : '');
            }
            $data = array_merge($data, [
                'street' => $address['street'],
                'city' => $address['city'],
                'postcode' => $address['postcode'],
                'country_id' => $address['country_id'],
            ], $this->region($address));
            $this->addressUpdater->apply($this->checkoutSession->getQuote(), $data);
            // A new search after a pick is a new session.
            $this->checkoutSession->unsetData(AddressSuggest::TOKEN_KEY);
        }

        $result = $this->resultLayoutFactory->create();
        $result->addHandle('zero_checkout_address');
        $layout = $result->getLayout();
        foreach (['zero.checkout.addressfields', 'zero.checkout.shippingmethods', 'zero.checkout.ordersummarytotals'] as $name) {
            $layout->addOutputElement($name);
        }

        return $result;
    }

    /**
     * region_id when the country has a region list and it matches, else the region as text.
     *
     * @param array $address
     * @return array{region_id?: string, region: string}
     */
    private function region(array $address): array
    {
        foreach (['region_code' => 'loadByCode', 'region' => 'loadByName'] as $key => $method) {
            if (($address[$key] ?? '') === '') {
                continue;
            }
            $region = $this->regionFactory->create()->$method($address[$key], $address['country_id']);
            if ($region->getId()) {
                return ['region_id' => (string) $region->getId(), 'region' => (string) $region->getName()];
            }
        }

        return ['region_id' => '', 'region' => (string) ($address['region'] ?? '')];
    }
}
