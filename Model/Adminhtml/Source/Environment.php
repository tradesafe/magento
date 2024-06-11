<?php
namespace TradeSafe\PaymentGateway\Model\Adminhtml\Source;


use Magento\Framework\Option\ArrayInterface;

class Environment implements ArrayInterface
{
    /**
     * Possible environment types
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => 'sandbox',
                'label' => 'Sandbox',
            ],
            [
                'value' => 'live',
                'label' => 'Live'
            ]
        ];
    }
}
