<?php
namespace TradeSafe\PaymentGateway\Controller\Success;

use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\DB\Transaction;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;

class Index extends \Magento\Framework\App\Action\Action
{
    const XML_CLIENT_ID_SANDOX= 'payment/tradesafe/sandbox_client_id';
    const XML_CLIENT_SECRET_SANDOX= 'payment/tradesafe/sandbox_client_secret';
    const XML_CLIENT_ID_lIVE= 'payment/tradesafe/client_id';
    const XML_CLIENT_SECRET_LIVE= 'payment/tradesafe/client_secret';
    const XML_ENVIROMENT= 'payment/tradesafe/environment';
    const GRAPHQL_URL = 'https://api-developer.tradesafe.dev/graphql';
    const OAUTH_TOKEN_URL= 'https://auth.tradesafe.co.za/oauth/token';

    protected \Magento\Checkout\Model\Session $_checkoutSession;
    protected \Magento\Framework\Encryption\EncryptorInterface $_encryptor;

    /**
     * @param \Magento\Framework\App\Action\Context $context
     */
    public function __construct(
       public \Magento\Framework\App\Action\Context                 $context,
       public                                                       $resultRedirectFactory,
       public \Magento\Checkout\Model\Session                       $checkoutSession,
       public \Magento\Quote\Model\QuoteFactory                     $quoteFactory,
       public \Magento\Sales\Model\OrderFactory                     $order,
       public \Magento\Checkout\Model\Type\Onepage                  $onePage,
       public \Magento\Sales\Model\Service\InvoiceService           $invoiceService,
       public \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender,
       public \Magento\Framework\DB\Transaction                     $transaction,
       public \Magento\Framework\App\Config\ScopeConfigInterface    $scopeConfig,
       public \Magento\Framework\Encryption\EncryptorInterface      $encryptor,
       public \Magento\Framework\Serialize\Serializer\Json          $json,
       public \Psr\Log\LoggerInterface                              $logger,
       public                                                       $messageManager,
       public \Magento\Quote\Api\CartManagementInterface            $cartManagementInterface
    )
    {
        $this->_checkoutSession = $checkoutSession;
        $this->_encryptor = $encryptor;
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
            $transactionId = $this->getRequest()->getParam('transactionId');

            // Setting Payment Method for the order
            $quote = $this->_checkoutSession->getQuote();
            $quote->setPaymentMethod('tradesafe');
            $quote->save();
            $quote->getPayment()->importData(['method' => 'tradesafe']);
            $quote->collectTotals()->save();
            $quoteData = $this->quoteFactory->create()->load($quote->getId());
            if(!$quote->getReservedOrderId())
            {
                $quote->reserveOrderId();
                $quoteReservedOrderId = $quote->getReservedOrderId();
            }
            else{
                $quoteReservedOrderId = $quote->getReservedOrderId();
            }



            // Placing order in Magento
            $orderId = $this->cartManagementInterface->placeOrder($quote->getId());

            // Getting order Id and creating an Invoice
            $orderIncrementId = $this->_checkoutSession->getData('last_real_order_id');
            $orderdetails = $this->order->create()->loadByIncrementId($orderIncrementId);
            if ($orderdetails->canInvoice()) {
                $invoice = $this->invoiceService->prepareInvoice($orderdetails);
                $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                $invoice->register();
                $invoice->getOrder()->setIsInProcess(true);
                $invoice->save();
                $transactionSave = $this->transaction->addObject(
                    $invoice
                )->addObject(
                    $invoice->getOrder()
                );
                $transactionSave->save();
                $this->invoiceSender->send($invoice);

            }
            $orderdetails->setTradesafeBuyertoken($transactionId)->save();
            $orderdetails->addStatusHistoryComment($transactionId.' :: Transaction Id For TradeSafe')->save();

            // Setting TradeSafe transaction Id in Invoice
            foreach ($orderdetails->getInvoiceCollection() as $invoice)
            {
                $invoice->setTransactionId($transactionId);
                $invoice->save();
            }

            // Start delivery of transaction(allocation)
            $state = $this->allocationStartDeliveryForCurrentTransaction($transactionId);

            // Redirect Customer to Success Page
            $resultRedirect = $this->resultRedirectFactory->create();
            $resultRedirect->setPath('checkout/onepage/success');
            return $resultRedirect;

        } catch (\Exception $e) {
            // Error in Placing Order in Magento, Redirecting Customer to Cart Page
            $this->logger->info('Error in Placing Order via TradeSafe');
            $this->logger->info($e->getMessage());
            $this->messageManager->addErrorMessage(__("Something Went Wrong Placing Order"));
            $this->logger->info($e->getMessage());

            $resultRedirect = $this->resultRedirectFactory->create();
            $resultRedirect->setPath('checkout/cart');
            return $resultRedirect;
        }
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
            CURLOPT_URL => self::OAUTH_TOKEN_URL,
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
            $this->logger->info('Error in Getting TradeSafe Access Token');
            $this->logger->info($e->getMessage());
            return '';

        }

    }

    public function allocationStartDeliveryForCurrentTransaction($transactionId)
    {
        try {
            $bearerToken = $this->getAccessToken();
            $allocationID = $this->getAllocationIdFromTransactionId($bearerToken,$transactionId);

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
            CURLOPT_POSTFIELDS =>'{"query":"mutation allocationStartDelivery {\\n  allocationStartDelivery(id: \\"'.$allocationID.'\\") {\\n    id\\n    state\\n  }\\n}","variables":{}}',
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer '.$bearerToken,
                'Content-Type: application/json'
            ),
            ));

            $response = curl_exec($curl);
            $jsonContent = $this->json->unserialize($response);
            if(array_key_exists("data",$jsonContent) && array_key_exists("state",$jsonContent['data']['allocationStartDelivery'])) {
                $currentStatOfAllocation = $jsonContent['data']['allocationStartDelivery']['state'];
                return $currentStatOfAllocation;
                curl_close($curl);
            }
        } catch (\Exception $e) {
            $this->logger->info('Error in Allocation Start Delivery For Current Transaction');
            $this->logger->info($e->getMessage());
            return '';
        }
    }

    public function getAllocationIdFromTransactionId($bearerToken,$transactionId)
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
              CURLOPT_POSTFIELDS =>'{"query":"query transaction {\\n  transaction(id: \\"'.$transactionId.'\\") {\\n    id\\n    allocations {\\n      id\\n    }\\n  }\\n}","variables":{}}',
              CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer '.$bearerToken,
                'Content-Type: application/json'
              ),
            ));

            $response = curl_exec($curl);

            $jsonContent = $this->json->unserialize($response);
            if(array_key_exists('allocations',$jsonContent['data']['transaction'])) {
                $allocationId = $jsonContent['data']['transaction']['allocations'][0]['id'];
                curl_close($curl);
                return $allocationId;
            }

        } catch (\Exception $e) {
            $this->logger->info('Error in Getting Allocation Id From Transaction Id');
            $this->logger->info($e->getMessage());
            return '';
        }
    }
}
