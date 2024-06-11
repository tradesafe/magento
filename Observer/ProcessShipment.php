<?php

namespace TradeSafe\PaymentGateway\Observer;

use Magento\Framework\Event\ObserverInterface;
use TradeSafe\PaymentGateway\Helper\TradeSafeAPI;

class ProcessShipment implements ObserverInterface
{
    public function __construct(
        public \Magento\Sales\Model\OrderFactory                  $order,
        public \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        public \Magento\Framework\Encryption\EncryptorInterface   $encryptor,
        public \Magento\Framework\Serialize\Serializer\Json       $json,
        public \Psr\Log\LoggerInterface                           $logger,
        public \Magento\Framework\Message\ManagerInterface        $messageManager,
        public \Magento\Framework\App\CacheInterface              $cache,
    )
    {
        //
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $shipment = $observer->getEvent()->getShipment();
        $order = $shipment->getOrder();
        $payment = $order->getPayment();
        $method = $payment->getMethod();
        $transactionId = $order->getTradesafeTransactionId();

        if ($method == "tradesafe" && $transactionId) {
            $tradeSafe = new TradeSafeAPI($this->scopeConfig, $this->encryptor, $this->cache);

            $transaction = $tradeSafe->getTransaction($transactionId);
            $deliveryCompleted = $tradeSafe->completeDelivery($transaction);

            if (!$deliveryCompleted) {
                $order->addCommentToStatusHistory('A problem occurred while updated the transaction on TradeSafe');
            }

            $order->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);
            $order->save();
        }
    }
}
