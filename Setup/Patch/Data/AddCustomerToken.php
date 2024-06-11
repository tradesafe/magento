<?php

declare(strict_types=1);

namespace TradeSafe\PaymentGateway\Setup\Patch\Data;

use Magento\Customer\Model\Customer;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

class AddCustomerToken implements DataPatchInterface, PatchRevertableInterface
{
    /**
     * Test attribute code.
     */
    public const TRADESAFE_TOKEN_CODE = 'tradesafe_customer_token';

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param CustomerSetupFactory $customerSetupFactory
     */
    public function __construct(
        private ModuleDataSetupInterface $moduleDataSetup,
        private CustomerSetupFactory     $customerSetupFactory,
    )
    {
    }

    /**
     * Add cutomer token attribute.
     *
     * @return void
     */
    public function apply()
    {
        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $customerSetup->addAttribute(Customer::ENTITY, self::TRADESAFE_TOKEN_CODE, [
            'default' => null,
            'group' => 'TradeSafe Escrow',
            'input' => 'text',
            'type' => 'varchar',
            'unique' => true,
            'label' => 'TradeSafe Escrow Customer Token',
            'note' => 'The customer token allows users to access their saved cards and manage funds in their wallet.',
            'position' => 999,
            'sort_order' => 999,
            'required' => false,
            'syetem' => false,
            'searchable' => false,
            'visible' => false,
        ]);
    }

    /**
     * Remove cutomer token attribute.
     *
     * @return void
     */
    public function revert()
    {
        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $customerSetup->removeAttribute(Customer::ENTITY, self::TRADESAFE_TOKEN_CODE);
    }

    /**
     * @ingeritdoc
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * @ingeritdoc
     */
    public function getAliases()
    {
        return [];
    }
}
