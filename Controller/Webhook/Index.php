<?php

namespace TradeSafe\PaymentGateway\Controller\Webhook;

use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Exception\NotFoundException;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\DB\Transaction;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use TradeSafe\PaymentGateway\Helper\TradeSafeAPI;

class Index extends \Magento\Framework\App\Action\Action implements CsrfAwareActionInterface
{
    public \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $_orderCollectionFactory;

    public function __construct(
        public \Magento\Framework\App\Action\Context                      $context,
        public \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory,
        public \Magento\Sales\Model\OrderFactory                          $order,
        public \Magento\Sales\Model\ResourceModel\Order                   $orderResource,
        public \Magento\Sales\Api\RefundInvoiceInterface                  $refundInvoice,
        public \Magento\Sales\Api\RefundOrderInterface                    $refundOrder,
        public \Psr\Log\LoggerInterface                                   $logger,
        public \Magento\Framework\Serialize\Serializer\Json               $json,
        public \Magento\Framework\Controller\Result\JsonFactory           $jsonFactory,
        public \Magento\Quote\Model\QuoteFactory                          $quoteFactory,
        public \Magento\Quote\Model\ResourceModel\Quote                   $quoteResource,
        public \Magento\Framework\App\Config\ScopeConfigInterface         $scopeConfig,
        protected \Magento\Framework\Encryption\EncryptorInterface        $encryptor,
        public \Magento\Framework\App\CacheInterface                      $cache,
        public \Magento\Quote\Api\CartManagementInterface                 $cartManagementInterface,
        public \Magento\Sales\Model\Service\InvoiceService                $invoiceService,
        public \Magento\Sales\Model\Order\Email\Sender\InvoiceSender      $invoiceSender,
        public \Magento\Framework\DB\Transaction                          $transaction,
    )
    {
        $this->_orderCollectionFactory = $orderCollectionFactory;
        return parent::__construct($context);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * View page action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $request = $this->getRequest();
        $data = $this->json->unserialize((string)$request->getContent());

        $tradeSafe = new TradeSafeAPI($this->scopeConfig, $this->encryptor, $this->cache);
        $transaction = $tradeSafe->getTransaction($data['id']);

        if (empty($transaction['customReference'])) {
            throw new NotFoundException(__('Invalid Request.'));
        }

        $reference = str_replace('Order#', '', $transaction['customReference']);

        $orderCollection = $this->_orderCollectionFactory->create()
            ->addAttributeToSelect('*')
            ->addFieldToFilter('increment_id', $reference);

        $order = $this->order->create();
        $orders = $orderCollection->getData();

        if (empty($orders)) {
            $quote = $this->quoteFactory->create();
            $this->quoteResource->load($quote, $reference, 'reserved_order_id');
            $quote->setPaymentMethod('tradesafe');
            $this->quoteResource->save($quote);

            $quote->getPayment()->importData(['method' => 'tradesafe']);
            $quote->collectTotals()->save();

            $orderId = $this->cartManagementInterface->placeOrder($quote->getId());

            $this->orderResource->load($order, $orderId);

            $order->setStatus(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);

            if ($order->canInvoice() && $transaction['state'] === 'FUNDS_RECEIVED' && $order->hasInvoices() === false) {
                $invoice = $this->invoiceService->prepareInvoice($order);
                $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                $invoice->register();

                $deliveryStatus = $tradeSafe->startDelivery($transaction);

                if ($deliveryStatus) {
                    $order->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);
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

            if (empty($order->getTradesafeTransactionId())) {
                $order->setTradesafeTransactionId($transaction['id']);
                $order->addCommentToStatusHistory('TradeSafe Transaction ID: ' . $transaction['id']);
            }

            $this->orderResource->save($order);
        } else {
            $this->orderResource->load($order, $orders[0]['entity_id']);
        }

        if ($data['id'] !== $order['tradesafe_transaction_id']) {
            throw new NotFoundException(__('Invalid Request.'));
        }

        switch ($transaction['state']) {
            case 'PAYMENT_PENDING':
            case 'FUNDS_DEPOSITED':
                if ($order->getStatus() !== 'pending_payment') {
                    $order->setStatus(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
                }
                break;

            case 'PAYMENT_FAILED':
                if ($order->getStatus() !== 'holded') {
                    $order->setStatus(\Magento\Sales\Model\Order::STATE_HOLDED);
                    $order->addCommentToStatusHistory('Payment for transaction (' . $transaction['id'] . ') failed.');
                }
                break;

            case 'FUNDS_RECEIVED':
            case 'INITIATED':
                $deliveryStatus = $tradeSafe->startDelivery($transaction);

                if ($deliveryStatus) {
                    if ($order->getStatus() !== 'processing') {
                        $order->addCommentToStatusHistory('Delivery started for transaction (' . $transaction['id'] . ').');
                        $order->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);
                    }

                    if ($order->canInvoice()) {
                        if ($order->hasInvoices() === false) {
                            $invoice = $this->invoiceService->prepareInvoice($order);
                            $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                            $invoice->register();

                            if ($deliveryStatus) {
                                $order->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);
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
                        } else {
                            foreach ($order->getInvoiceCollection() as $invoice) {
                                $invoice->setTransactionId($transaction['id']);
                                $invoice->setState(\Magento\Sales\Model\Order\Invoice::STATE_PAID);
                                $invoice->save();
                            }
                        }
                    }
                }
                break;
            case 'DELIVERED':
            case 'FUNDS_RELEASED':
                if ($order->getStatus() !== 'complete') {
                    $order->addCommentToStatusHistory('Transaction (' . $transaction['id'] . ') Complete.');
                    $order->setStatus(\Magento\Sales\Model\Order::STATE_COMPLETE);
                }
                break;
            case 'CANCELED':
            case 'ADMIN_CANCELED':
                if ($order->getStatus() !== 'closed') {
                    $order->addCommentToStatusHistory('Transaction (' . $transaction['id'] . ') cancelled.');
                    $order->setStatus(\Magento\Sales\Model\Order::STATE_CLOSED);
                }
                break;
            case 'REFUNDED':
            case 'ADMIN_REFUNDED':
                if ($order->getStatus() !== 'canceled') {
                    $order->addCommentToStatusHistory('Refund issued for transaction (' . $transaction['id'] . ').');
                    $order->setStatus(\Magento\Sales\Model\Order::STATE_CANCELED);

                    foreach ($order->getInvoiceCollection() as $invoice) {
                        $invoiceId = $invoice->getId();

                        $this->refundInvoice->execute($invoiceId, [], true);
                    }

                    $this->refundOrder->execute($order->getId(), [], 'Refund issued for transaction (' . $transaction['id'] . ').');
                }
                break;
            default:
                break;
        }

        $this->orderResource->save($order);
    }
}
