<?php
namespace TradeSafe\PaymentGateway\Model\Adminhtml\Source;


use Magento\Framework\Option\ArrayInterface;

class PaymentOptions implements ArrayInterface
{
    /**
     * Possible payment options
     *
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' =>'WALLET',
                'label' => 'Wallet',
            ],
            [
                'value' =>'IMMEDIATE',
                'label' => 'Immediate',
            ],
            [
                'value' =>'DAILY',
                'label' => 'Once a Day',
            ],
            [
                'value' =>'WEEKLY',
                'label' => 'Once a week',
            ],
            [
                'value' =>'BIMONTHLY',
                'label' => 'Twice a month',
            ],
            [
                'value' =>'MONTHLY',
                'label' => 'Once a month',
            ],
        ];
    }
}
