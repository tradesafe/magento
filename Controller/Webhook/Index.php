<?php
namespace TradeSafe\PaymentGateway\Controller\Webhook;

use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\DB\Transaction;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;

class Index extends \Magento\Framework\App\Action\Action implements CsrfAwareActionInterface
{
    public \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $_orderCollectionFactory;

    public function __construct(
        public \Magento\Framework\App\Action\Context $context,
        public \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory,
        public \Magento\Sales\Model\OrderFactory $order,
        public \Magento\Sales\Api\RefundInvoiceInterface $refund,
        public \Psr\Log\LoggerInterface $logger,
        public \Magento\Framework\Serialize\Serializer\Json $json
    )
    {
        $this->_orderCollectionFactory = $orderCollectionFactory;
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
            $request = $this->getRequest();
            $jsonContent = $request->getContent();
            $arrData = $this->json->unserialize((string)$jsonContent);

            if (array_key_exists("data", $arrData)) {
                $innerData = $arrData['data'];
                $transactionId = $innerData['id'];
                $transactionState = $innerData['state'];

                $orderCollection = $this->_orderCollectionFactory->create()
                    ->addAttributeToSelect('*')
                    ->addFieldToFilter('tradesafe_buyertoken', $transactionId); //Add condition if you wish

                $orderIncrementID = $orderCollection->getData()[0]['increment_id'];

                // allocation start delivery
                if ($transactionState == 'FUNDS_RECEIVED') {
                    $status = 'processing';
                    $this->setOrderStatus($orderIncrementID,$status);

                }
                // transaction in processing
                elseif ($transactionState == 'INITIATED') {
                    $status = 'payment_authorised';
                    $this->setOrderStatus($orderIncrementID,$status);

                }
                // transaction is done payment_auth represent payment was succesfully deducted
                elseif ($transactionState == 'DELIVERED') {
                    $status = 'complete';
                    $this->setOrderStatus($orderIncrementID,$status);

                }
                // order was dispatched and delivered
                elseif ($transactionState == 'FUNDS_RELEASED') {
                    $status = 'complete';
                    $this->setOrderStatus($orderIncrementID,$status);

                }
            }


        } catch (\Exception $e) {
            $this->logger->info('Error in TradeSafe Webhook');
            $this->logger->info($e->getMessage());
        }

    }

    public function createCsrfValidationException(RequestInterface $request): ? InvalidRequestException
    {
        return null;
    }
    private function orderRefund($orderIncrementID)
    {
        $orderdetails = $this->order->create()->loadByIncrementId($orderIncrementID);

        foreach ($orderdetails->getInvoiceCollection() as $invoice)
        {
            $invoiceId = $invoice->getId();
        }

        $this->refund->execute($invoiceId,[],true);
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {

        return true;

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
