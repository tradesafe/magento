<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace TradeSafe\PaymentGateway\Model\Payment;

class Tradesafe extends \Magento\Payment\Model\Method\AbstractMethod
{

    protected $_code = "tradesafe";
    protected $_isOffline = false;
    protected $_canAuthorize = true;
    protected $_isGateway = true;
    protected $_canCapture = true;
    protected $_canUseInternal   = false;
    protected $_canRefund   = true;
    protected $_canRefundInvoicePartial = true;

    const XML_CLIENT_ID_SANDOX= 'payment/tradesafe/sandbox_client_id';
    const XML_CLIENT_SECRET_SANDOX= 'payment/tradesafe/sandbox_client_secret';
    const XML_CLIENT_ID_lIVE= 'payment/tradesafe/client_id';
    const XML_CLIENT_SECRET_LIVE= 'payment/tradesafe/client_secret';
    const XML_ENVIROMENT= 'payment/tradesafe/environment';
    const LOGO_DIR = 'payments/logo/';

    protected \Magento\Framework\Encryption\EncryptorInterface $_encryptor;

    public function __construct(
        public \Magento\Framework\Model\Context                        $context,
        public \Magento\Framework\Registry                             $registry,
        public \Magento\Framework\Api\ExtensionAttributesFactory       $extensionFactory,
        public                                                         $customAttributeFactory,
        public \Magento\Payment\Helper\Data                            $paymentData,
        public \Magento\Framework\App\Config\ScopeConfigInterface      $scopeConfig,
        public                                                         $logger,
        public \Magento\Framework\Encryption\EncryptorInterface        $encryptor,
        public \Magento\Framework\Serialize\Serializer\Json            $json,
        public \Psr\Log\LoggerInterface                                $loggerPsr,
        public \Magento\Framework\Model\ResourceModel\AbstractResource $resource,
        public \Magento\Framework\Data\Collection\AbstractDb           $resourceCollection,

        array                                                          $data = []
    ) {
        $this->_encryptor = $encryptor;
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
    }

    public function isAvailable(
        \Magento\Quote\Api\Data\CartInterface $quote = null
    ) {
        return parent::isAvailable($quote);
    }

    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {

        try {
            if (!$this->canRefund()) {
                throw new \Magento\Framework\Exception\LocalizedException(__('The refund action is not available.'));
            }
            $transactionID = $payment->getCreditmemo()->getInvoice()->getTransactionId();

            if (!$transactionID || !$this->cancelTransaction($transactionID)) {
                throw new \Magento\Framework\Exception\LocalizedException(__('Something went wrong from TradeSafe'));
            }
        } catch (\Exception $e) {
            throw new \Magento\Framework\Exception\LocalizedException(__($e->getMessage()));
        }
        return $this;
    }

    public function getAccessToken()
    {
        try {
            $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
            $enviroment = $this->scopeConfig->getValue(self::XML_ENVIROMENT, $storeScope);
            if ($enviroment == 'sandbox') {
                $clientId = $this->scopeConfig->getValue(self::XML_CLIENT_ID_SANDOX, $storeScope);
                $clientSecretEncripted = $this->scopeConfig->getValue(self::XML_CLIENT_SECRET_SANDOX, $storeScope);
                $clientSecret = $this->_encryptor->decrypt($clientSecretEncripted);
            }
            else {
                $clientId = $this->scopeConfig->getValue(self::XML_CLIENT_ID_lIVE, $storeScope);
                $clientSecretEncripted = $this->scopeConfig->getValue(self::XML_CLIENT_SECRET_LIVE, $storeScope);
                $clientSecret = $this->_encryptor->decrypt($clientSecretEncripted);
            }

            $curl = curl_init();
            curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://auth.tradesafe.co.za/oauth/token',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => array('client_id' => $clientId,'client_secret' => $clientSecret,'grant_type' => 'client_credentials'),
            ));

            $response = curl_exec($curl);
            $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $jsonContent = $this->json->unserialize($response);

            $token = $jsonContent['access_token'];
            curl_close($curl);

            return $token;
        } catch (\Exception $e) {

            $this->loggerPsr->info($e->getMessage());
            return '';

        }

    }

    public function cancelTransaction($transactionID)
    {
        try {
            $bearerToken = $this->getAccessToken();
            $curl = curl_init();
            curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://api-developer.tradesafe.dev/graphql',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS =>'{"query":"\\nmutation {\\n  transactionCancel(id: \\"'.$transactionID.'\\", comment: \\"string\\") {\\n    id\\n    uuid\\n    reference\\n    privacy\\n    title\\n    description\\n    auxiliaryData\\n    state\\n    industry\\n    currency\\n    feeAllocation\\n    workflow\\n    createdAt\\n    updatedAt\\n    deletedAt\\n  }\\n}\\n","variables":{}}',
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer '.$bearerToken,
                'Content-Type: application/json'
            ),
            ));

            $response = curl_exec($curl);
            $jsonContent = $this->json->unserialize($response);
            return true;
            curl_close($curl);

        } catch (\Exception $th) {

            $this->loggerPsr->info($e->getMessage());
            return false;
        }
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        if($this->isValidRequest($request))
        {
            return true;
        }
        return false;
    }

    /**
     * Get logo image from config
     *
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     *
     * @return string
    */
    public function getLogo()
    {
        $logoUrl = false;

        if ($file = trim($this->getConfigData('logo'))) {
            $fileUrl = self::LOGO_DIR . $file;
            $mediaUrl = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
            $logoUrl = $mediaUrl . $fileUrl;
        }

        return $logoUrl;
    }

    /**
     * Display Title next to Logo
     *
     * @return int
     */
    public function displayTitleLogo()
    {
        return (int) $this->getConfigData('display_logo_title');
    }
}

