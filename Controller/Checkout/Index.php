<?php

namespace TradeSafe\PaymentGateway\Controller\Checkout;

use TradeSafe\PaymentGateway\Helper\TradeSafeAPI;

class Index extends \Magento\Framework\App\Action\Action
{
    public \Magento\Checkout\Model\Session $_checkoutSession;
    public \Magento\Quote\Model\QuoteFactory $quoteFactory;
    public \Magento\Framework\Controller\Result\JsonFactory $jsonFactory;
    public \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig;
    protected \Magento\Framework\Encryption\EncryptorInterface $_encryptor;
    public \Magento\Framework\Serialize\Serializer\Json $json;
    public \Magento\Framework\Serialize\SerializerInterface $serializer;
    public \Psr\Log\LoggerInterface $logger;
    public \TradeSafe\PaymentGateway\Helper\PaymentLogger $paymentLogger;
    public $messageManager;
    public \Magento\Framework\App\CacheInterface $cache;
    protected \Magento\Customer\Api\CustomerRepositoryInterface $_customerRepository;
    public \Magento\Quote\Api\CartManagementInterface $cartManagement;

    /**
     * @param \Magento\Framework\App\Action\Context $context
     */
    public function __construct(
        \Magento\Framework\App\Action\Context              $context,
        \Magento\Checkout\Model\Session                    $checkoutSession,
        \Magento\Quote\Model\QuoteFactory                  $quoteFactory,
        \Magento\Framework\Controller\Result\JsonFactory   $jsonFactory,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Encryption\EncryptorInterface   $encryptor,
        \Magento\Framework\Serialize\Serializer\Json       $json,
        \Magento\Framework\Serialize\SerializerInterface   $serializer,
        \Psr\Log\LoggerInterface                           $logger,
        \TradeSafe\PaymentGateway\Helper\PaymentLogger     $paymentLogger,
        \Magento\Framework\Message\ManagerInterface        $messageManager,
        \Magento\Framework\App\CacheInterface              $cache,
        \Magento\Customer\Api\CustomerRepositoryInterface  $customerRepository,
        \Magento\Quote\Api\CartManagementInterface         $cartManagement
    )
    {
        $this->_checkoutSession = $checkoutSession;
        $this->quoteFactory = $quoteFactory;
        $this->jsonFactory = $jsonFactory;
        $this->scopeConfig = $scopeConfig;
        $this->_encryptor = $encryptor;
        $this->json = $json;
        $this->serializer = $serializer;
        $this->logger = $logger;
        $this->paymentLogger = $paymentLogger;
        $this->messageManager = $messageManager;
        $this->cache = $cache;
        $this->_customerRepository = $customerRepository;
        $this->cartManagement = $cartManagement;

        return parent::__construct($context);
    }

    /**
     * View page action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
        $industry = $this->scopeConfig->getValue('payment/tradesafe/industry', $storeScope) ?: 'GENERAL_GOODS_SERVICES';
        $deliveryDays = $this->scopeConfig->getValue('payment/tradesafe/delivery_days', $storeScope) ?: 7;
        $inspectionDays = $this->scopeConfig->getValue('payment/tradesafe/inspection_days', $storeScope) ?: 1;

        $quote = $this->_checkoutSession->getQuote();
        $quoteData = $this->quoteFactory->create()->load($quote->getId());

        try {
            $tradeSafe = new TradeSafeAPI($this->scopeConfig, $this->_encryptor, $this->cache);

            if (!$quote->getReservedOrderId()) {
                $quote->reserveOrderId();
                $quoteReservedOrderId = $quote->getReservedOrderId();
            } else {
                $quoteReservedOrderId = $quote->getReservedOrderId();
            }

            $quoteData->setReservedOrderId($quoteReservedOrderId)->save();

            $cartTotal = $this->getRequest()->getParam('cartTotal');

            if ($cartTotal < 50) {
                $this->messageManager->addErrorMessage(__("Order total is too small"));
                return $this->jsonFactory->create()->setData(['error' => 'Order total is too small']);
            }

            // Creating Transaction Details Array of products
            $orderedProductsArray = '';
            $counter = 1;

            foreach ($quoteData->getAllItems() as $product) {
                $priceFormatted = number_format($product->getPrice(), 2);

                $single_product = $product->getName() . " x " . $product->getQty() . " @ " . $priceFormatted;

                if ($quoteData->getItemsCount() > 1 && $counter < $quoteData->getItemsCount()) {
                    $single_product .= "\n";
                    $counter++;
                }

                $orderedProductsArray .= $single_product;
            }

            if ($quoteData->getShippingAddress()->getBaseShippingAmount()) {
                $orderedProductsArray .= "\nShipping @ " . number_format($quoteData->getShippingAddress()->getBaseShippingAmount(), 2);
            }

            // Now Generating Buyer Token
            $buyerToken = $quoteData->getTradesafeToken() ?: null;

            if (!empty($quote->getCustomer()->getCustomAttribute('tradesafe_customer_token'))) {
                $buyerToken = $quote->getCustomer()->getCustomAttribute('tradesafe_customer_token')->getValue();
            }

            if (empty($buyerToken)) {
                try {
                    $token = $tradeSafe->createCustomerToken(
                        $quote->getBillingAddress()->getFirstname(),
                        $quote->getBillingAddress()->getLastname(),
                        $quote->getBillingAddress()->getEmail(),
                        $quote->getBillingAddress()->getTelephone(),
                        $quote->getBillingAddress()->getCountryId()
                    );

                    $buyerToken = $token['id'];
                } catch (\Throwable $e) {
                    $this->messageManager->addErrorMessage(__("A problem occured while creating the customer token"));
                    $this->messageManager->addErrorMessage($e->getMessage());
                    return $this->jsonFactory->create()->setData(['error' => 'A problem occured while creating the customer token']);
                }
            }

            if ($quote->getCustomerId() && empty($quote->getCustomer()->getCustomAttribute('tradesafe_customer_token'))) {
                $customer = $quote->getCustomer();
                $customer->setCustomAttribute('tradesafe_customer_token', $buyerToken);
                $this->_customerRepository->save($customer);
            }

            $quoteData->setTradesafeToken($buyerToken)->save();

            $transactionData = [
                'title' => sprintf("Order #%s", $quoteReservedOrderId),
                'description' => $orderedProductsArray,
                'industry' => $industry,
                'reference' => sprintf('Order#%s', $quoteReservedOrderId),
                'currency' => $quoteData->getCurrency()->getBaseCurrencyCode()
            ];

            $allocationData = [
                [
                    'title' => sprintf('Order #%s', $quoteReservedOrderId),
                    'description' => $orderedProductsArray,
                    'value' => (float)$cartTotal,
                    'daysToDeliver' => (float)$deliveryDays,
                    'daysToInspect' => (float)$inspectionDays,
                ]
            ];

            $partyData = [
                [
                    'role' => 'BUYER',
                    'token' => $buyerToken,
                ],
                [
                    'role' => 'SELLER',
                    'token' => $tradeSafe->getMerchantTokenId(),
                ],
            ];

            $result = $tradeSafe->createTransaction($transactionData, $allocationData, $partyData);

            $quoteData->setTradesafeTransactionId($result['transaction']['id'])->save();

            return $this->jsonFactory->create()->setData(['redirectUrl' => $result['link']]);
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__($e->getMessage()));
            $this->logger->info($e->getMessage());
            return $this->jsonFactory->create()->setData(['error' => $e->getMessage()]);
        }
    }
}
