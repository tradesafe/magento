<?php
namespace TradeSafe\PaymentGateway\Controller\Fail;
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
        $this->messageManager->addErrorMessage(__('Sorry, but something went wrong during payment process(Payment Declined)'));
        $this->logger->info('Error in Getting TradeSafe Payment');
        $this->logger->info('Error Payment Failed on TradeSafe for transaction # '.$this->getRequest()->getParam('transactionId'));
        $this->_redirect('checkout/cart');
    }
}
