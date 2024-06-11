<?php
namespace TradeSafe\PaymentGateway\Model\Adminhtml\Source;


use Magento\Framework\Option\ArrayInterface;

class InspectionDays implements ArrayInterface
{
    /**
     * Possible days for inspection
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => 1,
                'label' => '1 Day',
            ],
            [
                'value' => 2,
                'label' => '2 Days',
            ],
            [
                'value' => 3,
                'label' => '3 Days',
            ],
            [
                'value' => 4,
                'label' => '4 Days',
            ],
            [
                'value' => 5,
                'label' => '5 Days',
            ],
            [
                'value' => 6,
                'label' => '6 Days',
            ],
            [
                'value' => 7,
                'label' => '1 Week',
            ],
        ];
    }
}
