<?php
/**
 * Copyright ©  All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace TradeSafe\PaymentGateway\Model\Payment;

use Magento\Framework\UrlInterface;
use TradeSafe\PaymentGateway\Helper\TradeSafeAPI;

class Tradesafe extends \Magento\Payment\Model\Method\AbstractMethod
{
    const LOGO_DIR = 'payments/logo/';

    protected $_code = "tradesafe";
    protected $_isOffline = false;
    protected $_canAuthorize = true;
    protected $_isGateway = true;
    protected $_canCapture = true;
    protected $_canUseInternal = false;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;

    protected $cache;

    protected \Magento\Framework\Encryption\EncryptorInterface $_encryptor;
    private $storeManager;

    public function __construct(
        \Magento\Framework\Model\Context                        $context,
        \Magento\Framework\Registry                             $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory       $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory            $customAttributeFactory,
        \Magento\Payment\Helper\Data                            $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface      $scopeConfig,
        \Magento\Payment\Model\Method\Logger                    $logger,
        \Magento\Framework\Encryption\EncryptorInterface        $encryptor,
        \Magento\Framework\Serialize\Serializer\Json            $json,
        \Psr\Log\LoggerInterface                                $loggerPsr,
        \Magento\Framework\App\CacheInterface                   $cache,
        \Magento\Store\Model\StoreManagerInterface              $storeManager,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb           $resourceCollection = null,

        array                                                   $data = []
    )
    {
        $this->scopeConfig = $scopeConfig;
        $this->_encryptor = $encryptor;
        $this->json = $json;
        $this->loggerPsr = $loggerPsr;
        $this->cache = $cache;
        $this->storeManager = $storeManager;
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
    )
    {
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

    public function cancelTransaction($transactionID): array
    {
        $tradeSafe = new TradeSafeAPI($this->scopeConfig, $this->_encryptor, $this->cache);

        return $tradeSafe->cancelTransaction($transactionID);
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        if ($this->isValidRequest($request)) {
            return true;
        }
        return false;
    }

    /**
     * Get logo image from config
     *
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     *
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
        return (int)$this->getConfigData('display_logo_title');
    }
}

