# Mage2 Module TradeSafe Payment Gateway

    ``tradesafe/magento2``

 - [Main Functionalities](#markdown-header-main-functionalities)
 - [Installation](#markdown-header-installation)
 - [Configuration](#markdown-header-configuration)
 - [Specifications](#markdown-header-specifications)
 - [Attributes](#markdown-header-attributes)


## Main Functionalities
TradeSafe, backed by Standard Bank, provides an escrow payments-based solution that integrates seamlessly with your existing Magento store.

## Installation
\* = in production please use the `--keep-generated` option

### Type 1: Zip file

 - Unzip the zip file in `app/code/TradeSafe/PaymentGateway`
 - Enable the module by running `php bin/magento module:enable TradeSafe_PaymentGateway`
 - Apply database updates by running `php bin/magento setup:upgrade`\*
 - Flush the cache by running `php bin/magento cache:flush`

### Type 2: Composer

 - Make the module available in a composer repository for example:
    - private repository `repo.magento.com`
    - public repository `packagist.org`
    - public GitHub repository as vcs
 - Add the composer repository to the configuration by running `composer config repositories.repo.magento.com composer https://repo.magento.com/`
 - Install the module composer by running `composer require tradesafe/magento2`
 - enable the module by running `php bin/magento module:enable TradeSafe_PaymentGateway`
 - apply database updates by running `php bin/magento setup:upgrade`\*
 - Flush the cache by running `php bin/magento cache:flush`


## Configuration

 - tradesafe - payment/tradesafe/*


## Specifications

 - Payment Method
	- tradesafe


## Attributes



