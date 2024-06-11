<?php
namespace TradeSafe\PaymentGateway\Controller\Failure;
use Magento\Framework\App\RequestInterface;

class Index extends \Magento\Framework\App\Action\Action
{
    public \Magento\Framework\View\Result\PageFactory $_pageFactory;

    public function __construct(
       public \Magento\Framework\App\Action\Context $context,
       public \Magento\Framework\View\Result\PageFactory $pageFactory,
       public \Psr\Log\LoggerInterface $logger,
       public $messageManager
    )
    {
        $this->_pageFactory = $pageFactory;
        return parent::__construct($context);
    }
    /**
     * View page action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $reason = $this->getRequest()->getParam('reason');

        $errorMessage = match($reason) {
            'error' => __('An error occurred while processing your payment.'),
            'canceled' => __('Payment for your order was cancelled.'),
            default => __('Sorry, but something went wrong during payment process.')
        };

        $this->messageManager->addErrorMessage($errorMessage);
        $this->logger->info('Error Payment Failed on TradeSafe for transaction # '.$this->getRequest()->getParam('transactionId'));
        $this->_redirect('checkout/cart');
    }
}
