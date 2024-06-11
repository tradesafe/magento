<?php

namespace TradeSafe\PaymentGateway\Controller\Success;

use TradeSafe\PaymentGateway\Helper\TradeSafeAPI;

class Index extends \Magento\Framework\App\Action\Action
{
    protected \Magento\Checkout\Model\Session $_checkoutSession;
    protected \Magento\Quote\Model\QuoteFactory $quoteFactory;
    protected \Magento\Quote\Model\ResourceModel\Quote $quoteResource;
    protected \Magento\Sales\Model\OrderFactory $order;
    protected \Magento\Checkout\Model\Type\Onepage $onePage;
    protected \Magento\Sales\Model\Service\InvoiceService $invoiceService;
    protected \Magento\Framework\DB\Transaction $transaction;
    protected \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender;
    protected \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig;
    protected \Magento\Framework\Encryption\EncryptorInterface $_encryptor;
    protected \Magento\Framework\Serialize\Serializer\Json $json;
    protected \Psr\Log\LoggerInterface $logger;
    protected \Magento\Quote\Api\CartManagementInterface $cartManagementInterface;
    public \Magento\Framework\App\CacheInterface $cache;
    public \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $_orderCollectionFactory;

    /**
     * @param \Magento\Framework\App\Action\Context $context
     */
    public function __construct(
        \Magento\Framework\App\Action\Context                      $context,
        \Magento\Framework\Controller\Result\RedirectFactory       $resultRedirectFactory,
        \Magento\Checkout\Model\Session                            $checkoutSession,
        \Magento\Quote\Model\QuoteFactory                          $quoteFactory,
        \Magento\Quote\Model\ResourceModel\Quote                   $quoteResource,
        \Magento\Sales\Model\OrderFactory                          $order,
        \Magento\Checkout\Model\Type\Onepage                       $onePage,
        \Magento\Sales\Model\Service\InvoiceService                $invoiceService,
        \Magento\Sales\Model\Order\Email\Sender\InvoiceSender      $invoiceSender,
        \Magento\Framework\DB\Transaction                          $transaction,
        \Magento\Framework\App\Config\ScopeConfigInterface         $scopeConfig,
        \Magento\Framework\Encryption\EncryptorInterface           $encryptor,
        \Magento\Framework\Serialize\Serializer\Json               $json,
        \Psr\Log\LoggerInterface                                   $logger,
        \Magento\Framework\Message\ManagerInterface                $messageManager,
        \Magento\Quote\Api\CartManagementInterface                 $cartManagementInterface,
        \Magento\Framework\App\CacheInterface                      $cache,
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory,
    )
    {
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->_checkoutSession = $checkoutSession;
        $this->quoteFactory = $quoteFactory;
        $this->quoteResource = $quoteResource;
        $this->order = $order;
        $this->onePage = $onePage;
        $this->invoiceService = $invoiceService;
        $this->transaction = $transaction;
        $this->invoiceSender = $invoiceSender;
        $this->scopeConfig = $scopeConfig;
        $this->_encryptor = $encryptor;
        $this->json = $json;
        $this->logger = $logger;
        $this->messageManager = $messageManager;
        $this->cartManagementInterface = $cartManagementInterface;
        $this->cache = $cache;
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
            $tradeSafe = new TradeSafeAPI($this->scopeConfig, $this->_encryptor, $this->cache);

            $transactionId = $this->getRequest()->getParam('transactionId');

            $transaction = $tradeSafe->getTransaction($transactionId);

            // Setting Payment Method for the order
            $quote = $this->_checkoutSession->getQuote();

            $reference = str_replace('Order#', '', $transaction['customReference']);

            $orderCollection = $this->_orderCollectionFactory->create()
                ->addAttributeToSelect('*')
                ->addFieldToFilter('increment_id', $reference);

            $orders = $orderCollection->getData();

            if (empty($orders)) {
                // Verify transaction is for this quote
                if (!$quote->getReservedOrderId()) {
                    $quote->reserveOrderId();
                    $quoteReservedOrderId = $quote->getReservedOrderId();
                } else {
                    $quoteReservedOrderId = $quote->getReservedOrderId();
                }

                if (!str_contains($transaction['customReference'], $quoteReservedOrderId)) {
                    $this->messageManager->addErrorMessage(__("Transaction does not match current order"));
                    $resultRedirect = $this->resultRedirectFactory->create();
                    $resultRedirect->setPath('checkout/cart');
                    return $resultRedirect;
                }

                $quote->setPaymentMethod('tradesafe');
                $quote->save();
                $quote->getPayment()->importData(['method' => 'tradesafe']);
                $quote->collectTotals()->save();

                // Placing order in Magento
                $orderId = $this->cartManagementInterface->placeOrder($quote->getId());

                // Getting order Id and creating an Invoice
                $orderIncrementId = $this->_checkoutSession->getData('last_real_order_id');

            } else {
                $orderIncrementId = $orders[0]['increment_id'];
            }

            $orderDetails = $this->order->create()->loadByIncrementId($orderIncrementId);

            if ($orderDetails->canInvoice() && $transaction['state'] === 'FUNDS_RECEIVED' && $orderDetails->hasInvoices() === false) {
                $invoice = $this->invoiceService->prepareInvoice($orderDetails);
                $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                $invoice->register();

                $deliveryStatus = $tradeSafe->startDelivery($transaction);

                if ($deliveryStatus) {
                    $invoice->getOrder()->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);
                    $invoice->setState(\Magento\Sales\Model\Order\Invoice::STATE_PAID);
                }

                $invoice->save();
                $transactionSave = $this->transaction->addObject(
                    $invoice
                )->addObject(
                    $invoice->getOrder()
                );
                $transactionSave->save();
                $this->invoiceSender->send($invoice);
            }

            $orderDetails->setTradesafeTransactionId($transactionId);
            $orderDetails->addCommentToStatusHistory('TradeSafe Transaction ID: ' . $transactionId);
            $orderDetails->save();

            // Setting TradeSafe transaction Id in Invoice
            foreach ($orderDetails->getInvoiceCollection() as $invoice) {
                $invoice->setTransactionId($transactionId);
                $invoice->save();
            }

            $this->_checkoutSession->setLastSuccessQuoteId($orderDetails->getQuoteId());
            $this->_checkoutSession->setLastQuoteId($orderDetails->getQuoteId());
            $this->_checkoutSession->setLastOrderId($orderDetails->getEntityId());
            $this->_checkoutSession->setLastRealOrderId($orderDetails->getIncrementId());

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
}
