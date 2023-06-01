<?php

namespace TradeSafe\PaymentGateway\Controller\Checkout;

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

    const XML_CLIENT_ID_SANDBOX = 'payment/tradesafe/sandbox_client_id';
    const XML_CLIENT_SECRET_SANDBOX = 'payment/tradesafe/sandbox_client_secret';
    const XML_CLIENT_ID_lIVE = 'payment/tradesafe/client_id';
    const XML_CLIENT_SECRET_LIVE = 'payment/tradesafe/client_secret';
    const XML_ENVIRONMENT = 'payment/tradesafe/environment';
    const GRAPHQL_URL = 'https://api.tradesafe.test/graphql';
    const GRAPHQL_URL_SANDBOX = 'https://api-developer.tradesafe.dev/graphql';
    const GRAPHQL_URL_PRODUCTION = 'https://api.tradesafe.co.za/graphql';
    const OAUTH_TOKEN_URL_PROD = 'https://auth.tradesafe.co.za/oauth/token';
    const OAUTH_TOKEN_URL = 'https://auth.tradesafe.test/oauth/token';

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
        \Magento\Framework\Message\ManagerInterface        $messageManager
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
        return parent::__construct($context);
    }

    /**
     * View page action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        try {
            $cartTotal = $this->getRequest()->getParam('cartTotal');
            $bearerToken = $this->getAccessToken();

            if ($bearerToken) {
                // TradeSafe Escrow Authentication Validated

                // Getting Customer details
                $quote = $this->_checkoutSession->getQuote();
                $quoteData = $this->quoteFactory->create()->load($quote->getId());
                $customerEmail = $quote->getBillingAddress()->getEmail();
                $customerPhone = $quote->getShippingAddress()->getTelephone();
                if (!$quote->getReservedOrderId()) {
                    $quote->reserveOrderId();
                    $quoteReservedOrderId = $quote->getReservedOrderId();
                } else {
                    $quoteReservedOrderId = $quote->getReservedOrderId();
                }

                $quoteData->setReservedOrderId($quoteReservedOrderId)->save();

                // Creating Transaction Details Array of products
                $orderedProductsArray = '';
                $counter = 0;
                foreach ($quoteData->getAllItems() as $product) {

                    if ($counter < $quoteData->getItemsCount()) {
                        $single_product = $product->getName() . " x" . $product->getQty() . ",\\\\n";
                    } else {
                        $single_product = $product->getName() . " x" . $product->getQty();
                    }
                    $counter++;
                    $orderedProductsArray .= $single_product;
                }

                // Now Generating Buyer Token
                $buyerToken = $this->generateNewBuyerToken($customerEmail, $customerPhone, $bearerToken);
                $quoteData->setTradesafeBuyertoken($buyerToken)->save();


                // Creating Transaction and generating Checkout Url
                $transactionID = $this->createTransaction($bearerToken, $cartTotal, $orderedProductsArray, $buyerToken, $quoteReservedOrderId);
                if (is_array($transactionID)) {
                    if (str_contains($transactionID['errors'][0]['extensions']['validation']['input.allocations.create.0.value'][0], 'must be at least 50')) {
                        $this->messageManager->addErrorMessage(__("Something Went Wrong"));
                        $this->messageManager->addErrorMessage(__("Total must be at least 50"));
                        return $this->jsonFactory->create()->setData(['error' => 'Minimum Value not 50']);
                    }
                } else {
                    $checkoutLink = $this->getCheckoutLink($bearerToken, $transactionID);
                    return $this->jsonFactory->create()->setData(['redirectUrl' => $checkoutLink]);
                }

            }
            // TradeSafe Escrow Authentication Failed
            $this->messageManager->addErrorMessage(__("Something Went Wrong"));
            $this->logger->info('TradeSafe Escrow Authentication Failed');
            return $this->jsonFactory->create()->setData(['error' => 'TradeSafe Escrow Authentication Failed']);

        } catch (\Exception $e) {

            $this->messageManager->addErrorMessage(__($e->getMessage()));
            $this->logger->info($e->getMessage());
            return $this->jsonFactory->create()->setData(['error' => $e->getMessage()]);
        }
    }

    public function getAccessToken()
    {
        try {
            $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
            $enviroment = $this->scopeConfig->getValue(self::XML_ENVIRONMENT, $storeScope);
            if ($enviroment == 'sandbox') {
                $clientId = $this->scopeConfig->getValue(self::XML_CLIENT_ID_SANDBOX, $storeScope);
                $clientSecretEncrypted = $this->scopeConfig->getValue(self::XML_CLIENT_SECRET_SANDBOX, $storeScope);
                $clientSecret = $this->_encryptor->decrypt($clientSecretEncrypted);
            } else {
                $clientId = $this->scopeConfig->getValue(self::XML_CLIENT_ID_lIVE, $storeScope);
                $clientSecretEncrypted = $this->scopeConfig->getValue(self::XML_CLIENT_SECRET_LIVE, $storeScope);
                $clientSecret = $this->_encryptor->decrypt($clientSecretEncrypted);
            }

            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => self::OAUTH_TOKEN_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => array('client_id' => $clientId, 'client_secret' => $clientSecret, 'grant_type' => 'client_credentials'),
            ));

            $response = curl_exec($curl);
            $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $jsonContent = $this->json->unserialize($response ?? '');

            $token = $jsonContent['access_token'];
            curl_close($curl);

            return $token;
        } catch (\Exception $e) {
            $this->logger->info('Error in Getting TradeSafe Access Token');
            $this->logger->info($e->getMessage());
            return '';
        }

    }

    public function createTransaction($bearerToken, $cartTotal, $serializeDataForOrderedProductsArray, $buyerToken, $quoteReservedOrderId)
    {
        $cartTotal = floatval($cartTotal);

        try {
            $sellerToken = $this->getSellerToken($bearerToken);
            $quoteReservedOrderId = 'Order # ' . $quoteReservedOrderId;
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => self::GRAPHQL_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => '{"query":"mutation transactionCreate {\\n  transactionCreate(input: {\\n    title: \"' . $quoteReservedOrderId . '\",\\n    description : \"' . $serializeDataForOrderedProductsArray . '\",\\n    industry: GENERAL_GOODS_SERVICES,\\n    currency: ZAR,\\n    feeAllocation: SELLER,\\n    allocations: {\\n      create: [\\n        {\\n          title: \\"Allocation Title\\",\\n          description: \\"Allocation Description\\",\\n          value: ' . $cartTotal . ',\\n          daysToDeliver: 7,\\n          daysToInspect: 7\\n        }\\n      ]\\n    },\\n    parties: {\\n      create: [\\n        {\\n          token: \\"' . $buyerToken . '\\",\\n          role: BUYER\\n        }, {\\n          token: \\"' . $sellerToken . '\\",\\n          role: SELLER\\n        }\\n      ]\\n    }\\n  }) {\\n    id\\n    title\\n    createdAt\\n  }\\n}","variables":{}}',
                CURLOPT_HTTPHEADER => array(
                    'Authorization: Bearer ' . $bearerToken,
                    'Content-Type: application/json'
                ),
            ));

            $response = curl_exec($curl);
            $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $jsonContent = $this->json->unserialize($response);
            $this->logger->info('Error in Creating Transaction catch');
            $this->logger->info(print_r($jsonContent, true));
            $str = '{"query":"mutation transactionCreate {\\n  transactionCreate(input: {\\n    title: \"' . $quoteReservedOrderId . '\",\\n    description : \"' . $serializeDataForOrderedProductsArray . '\",\\n    industry: GENERAL_GOODS_SERVICES,\\n    currency: ZAR,\\n    feeAllocation: SELLER,\\n    allocations: {\\n      create: [\\n        {\\n          title: \\"Allocation Title\\",\\n          description: \\"Allocation Description\\",\\n          value: ' . $cartTotal . ',\\n          daysToDeliver: 7,\\n          daysToInspect: 7\\n        }\\n      ]\\n    },\\n    parties: {\\n      create: [\\n        {\\n          token: \\"' . $buyerToken . '\\",\\n          role: BUYER\\n        }, {\\n          token: \\"' . $sellerToken . '\\",\\n          role: SELLER\\n        }\\n      ]\\n    }\\n  }) {\\n    id\\n    title\\n    createdAt\\n  }\\n}","variables":{}}';
            $this->logger->info($str);
            if (array_key_exists("errors", $jsonContent)) {
                return $jsonContent;
            } elseif (array_key_exists("data", $jsonContent) && array_key_exists("transactionCreate", $jsonContent['data'])) {
                $tId = $jsonContent['data']['transactionCreate']['id'];
                curl_close($curl);
                return $tId;
            }


        } catch (\Exception $e) {
            $this->logger->info('Error in Creating Transaction catch');
            $this->logger->info($e->getMessage());
            return '';
        }
    }

    public function getCheckoutLink($bearerToken, $transactionId)
    {
        try {
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => self::GRAPHQL_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => '{"query":"mutation checkoutLink {\\n  checkoutLink(transactionId: \\"' . $transactionId . '\\")\\n}","variables":{}}',
                CURLOPT_HTTPHEADER => array(
                    'Authorization: Bearer ' . $bearerToken,
                    'Content-Type: application/json'
                ),
            ));

            $response = curl_exec($curl);
            $jsonContent = $this->json->unserialize($response);
            if (array_key_exists("data", $jsonContent) && array_key_exists("checkoutLink", $jsonContent['data'])) {
                $checkoutLink = $jsonContent['data']['checkoutLink'];
                return $checkoutLink;
                curl_close($curl);
            } else {
                $this->logger->info('Error in Getting Checkout Link');
                $this->logger->info($jsonContent);
            }

        } catch (\Exception $e) {
            $this->logger->info('Error in Getting Checkout Link');
            $this->logger->info($e->getMessage());
            return '';
        }

    }

    public function generateNewBuyerToken($customerEmail, $customerPhone, $bearerToken)
    {
        try {

            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => self::GRAPHQL_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => '{"query":"mutation tokenCreate {\\n    tokenCreate(input: {\\n        user: {\\n            givenName: \\"Ahmed\\",\\n            familyName: \\"Riaz\\",\\n            email: \\"' . $customerEmail . '\\",\\n            mobile: \\"' . $customerPhone . '\\",   \\n        }\\n    }) {\\n        id\\n        name\\n    }\\n}","variables":{}}',
                CURLOPT_HTTPHEADER => array(
                    'Authorization: Bearer ' . $bearerToken,
                    'Content-Type: application/json'
                ),
            ));

            $response = curl_exec($curl);
            $jsonContent = $this->json->unserialize($response);
            $buyerToken = $jsonContent['data']['tokenCreate']['id'];

            curl_close($curl);

            return $buyerToken;
        } catch (\Exception $e) {
            $this->logger->info('Error in Getting Generating Buyer Token');
            $this->logger->info($e->getMessage());
            return '';
        }

    }

    public function getSellerToken($bearerToken)
    {
        try {
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => self::GRAPHQL_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => '{"query":"query profile {\\n  apiProfile {\\n    token\\n  }\\n}","variables":{}}',
                CURLOPT_HTTPHEADER => array(
                    'Authorization: Bearer ' . $bearerToken,
                    'Content-Type: application/json'
                ),
            ));

            $response = curl_exec($curl);
            $jsonContent = $this->json->unserialize($response);
            $sellerToken = $jsonContent['data']['apiProfile']['token'];
            curl_close($curl);

            return $sellerToken;
        } catch (\Exception $e) {
            $this->logger->info('Error in Getting Generating Seller Token');
            $this->logger->info($e->getMessage());
            return '';
        }

    }
}
