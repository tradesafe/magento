<?php
namespace TradeSafe\PaymentGateway\Model\Adminhtml\Source;


use Magento\Framework\Option\ArrayInterface;

class Industry implements ArrayInterface
{
    /**
     * Possible industry types
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => 'AGRICULTURE_LIVESTOCK_GAME',
                'label' => 'Agriculture, Livestock & Game',
            ],
            [
                'value' => 'ART_ANTIQUES_COLLECTIBLES',
                'label' => 'Art, Antiques & Collectibles',
            ],
            [
                'value' => 'VEHICLES_WATERCRAFT',
                'label' => 'Cars, Bikes, Planes & Boats',
            ],
            [
                'value' => 'CELLPHONES_COMPUTERS',
                'label' => 'Cell phones, Computers & Electronics',
            ],
            [
                'value' => 'CONSTRUCTION',
                'label' => 'Construction',
            ],
            [
                'value' => 'FUEL',
                'label' => 'Diesel, Petroleum & Lubricating Oils',
            ],
            [
                'value' => 'EVENTS',
                'label' => 'Events, Weddings & Functions',
            ],
            [
                'value' => 'FILMS_PRODUCTION',
                'label' => 'Films, Production & Photography',
            ],
            [
                'value' => 'CONTRACT_WORK_FREELANCING',
                'label' => 'Freelancing & Contract Work',
            ],
            [
                'value' => 'GENERAL_GOODS_SERVICES',
                'label' => 'General Goods & Services',
            ],
            [
                'value' => 'MERGERS_ACQUISITIONS',
                'label' => 'Mergers & Acquisitions',
            ],
            [
                'value' => 'MINING',
                'label' => 'Mining & Metals',
            ],
            [
                'value' => 'PROPERTY',
                'label' => 'Property (Residential & Commercial)',
            ],
            [
                'value' => 'RENEWABLES',
                'label' => 'Renewables',
            ],
            [
                'value' => 'RENTAL',
                'label' => 'Rental Deposits & Holiday Rentals',
            ],
            [
                'value' => 'SOFTWARE_DEV_WEB_DOMAINS',
                'label' => 'Web Domain Purchases & Transfers',
            ],
        ];
    }
}
