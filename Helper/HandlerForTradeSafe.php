<?php
namespace TradeSafe\PaymentGateway\Helper;

use Magento\Framework\Logger\Handler\Base;

class HandlerForTradeSafe extends Base
{
    protected $fileName = '/var/log/tradesafe/payment.log';
    protected $loggerType = \Monolog\Logger::DEBUG;
}
