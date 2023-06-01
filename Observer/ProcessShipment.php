<?php
namespace TradeSafe\PaymentGateway\Observer;

use Magento\Framework\Event\ObserverInterface;

class ProcessShipment implements ObserverInterface
{
    const XML_CLIENT_ID_SANDOX= 'payment/tradesafe/sandbox_client_id';
    const XML_CLIENT_SECRET_SANDOX= 'payment/tradesafe/sandbox_client_secret';
    const XML_CLIENT_ID_lIVE= 'payment/tradesafe/client_id';
    const XML_CLIENT_SECRET_LIVE= 'payment/tradesafe/client_secret';
    const XML_ENVIROMENT= 'payment/tradesafe/environment';
    const GRAPHQL_URL = 'https://api-developer.tradesafe.dev/graphql';
    const OAUTH_TOKEN_URL= 'https://auth.tradesafe.co.za/oauth/token';


    public function __construct(
        \Magento\Sales\Model\OrderFactory $order,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Encryption\EncryptorInterface $encryptor,
        \Magento\Framework\Serialize\Serializer\Json $json,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\Message\ManagerInterface $messageManager
    )
    {
        $this->order = $order;
        $this->scopeConfig = $scopeConfig;
        $this->_encryptor = $encryptor;
        $this->json = $json;
        $this->logger = $logger;
        $this->messageManager = $messageManager;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        try {
            $shipment = $observer->getEvent()->getShipment();
            $order = $shipment->getOrder();
            $payment = $order->getPayment();
            $method = $payment->getMethodInstance();
            $orderIncrementID = $order->getIncrementId();
            $methodTitle = $method->getTitle();
            $transactionId = $order->getTradesafeBuyertoken();

            if ($methodTitle == "TradeSafe Escrow" && $transactionId) {
                $return = $this->allocationSetToTransitForCurrentTransaction($transactionId);

                if ($return == "IN_TRANSIT") {
                    $status = 'payment_authorised';
                    $this->setOrderStatus($orderIncrementID,$status);

                }
            }
        } catch (\Exception $e) {
            $this->logger->info('Error in Creating Tradesafe shipment');
            $this->logger->info($e->getMessage());

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

    public function allocationSetToTransitForCurrentTransaction($transactionId)
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
            CURLOPT_POSTFIELDS =>'{"query":"mutation allocationStartDelivery {\\n  allocationInTransit(id: \\"'.$allocationID.'\\") {\\n    id\\n    state\\n  }\\n}","variables":{}}',
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer '.$bearerToken,
                'Content-Type: application/json'
            ),
            ));

            $response = curl_exec($curl);
            $jsonContent = $this->json->unserialize($response);

            if($jsonContent && array_key_exists("state",$jsonContent['data']['allocationInTransit'])) {
                $currentStatOfAllocation = $jsonContent['data']['allocationInTransit']['state'];
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

            if(array_key_exists("allocations",$jsonContent['data']['transaction'])) {
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

    private function setOrderStatus($orderIncrementID,$status)
    {
        $orderdetails = $this->order->create()->loadByIncrementId($orderIncrementID);

        if($orderdetails->getState() === 'processing')
        {
            $orderdetails->setStatus($status);
            $orderdetails->save();
        }
    }
}
