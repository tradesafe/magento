<?php

namespace TradeSafe\PaymentGateway\Helper;

use TradeSafe\Client;

class TradeSafeAPI
{
    public readonly Client $client;
    public string $environment;

    public function __construct(
        public \Magento\Framework\App\Config\ScopeConfigInterface  $scopeConfig,
        protected \Magento\Framework\Encryption\EncryptorInterface $encryptor,
        public \Magento\Framework\App\CacheInterface               $cache
    )
    {
        $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
        $environment = $this->scopeConfig->getValue('payment/tradesafe/environment', $storeScope);

        $configPaths = [
            'client_id' => 'payment/tradesafe/sandbox_client_id',
            'client_secret' => 'payment/tradesafe/sandbox_client_secret',
        ];

        if ($environment == 'live') {
            $configPaths = [
                'client_id' => 'payment/tradesafe/client_id',
                'client_secret' => 'payment/tradesafe/client_secret',
            ];
        }

        $clientId = $this->scopeConfig->getValue($configPaths['client_id'], $storeScope);
        $encryptedClientSecret = $this->scopeConfig->getValue($configPaths['client_secret'], $storeScope);
        $clientSecret = $this->encryptor->decrypt($encryptedClientSecret);

        $cachedToken = null;
        $encryptedCachedToken = $this->cache->load('tradesafe_access_token_' . $environment) ?: null;

        if (!empty($encryptedCachedToken)) {
            $cachedToken = $this->encryptor->decrypt($encryptedCachedToken);
        }

        $this->environment = $environment;
        $this->client = new \TradeSafe\Client($clientId, $clientSecret, $cachedToken, $environment);

        if (empty($cachedToken)) {
            $accessToken = $this->client->getAccessToken();

            if (!empty($accessToken['access_token'])) {
                $encryptedToken = $this->encryptor->encrypt($accessToken['access_token']);
                $this->cache->save($encryptedToken, 'tradesafe_access_token_' . $environment, ['config'], 3600);
            }
        }
    }

    public function getMerchantTokenId()
    {
        $merchantTokenId = $this->cache->load('tradesafe_merchant_token_' . $this->environment) ?: null;

        if (empty($merchantTokenId)) {
            $profile = $this->client->getProfile();

            if (!empty($profile['data']['profile']['token'])) {
                $merchantTokenId = $profile['data']['profile']['token'];
                $this->cache->save($merchantTokenId, 'tradesafe_merchant_token_' . $this->environment, ['config']);
            }
        }

        return $merchantTokenId;
    }

    public function createCustomerToken($firstname, $lastname, $email, $phoneNumber, $country): string
    {
        $phoneUtil = \libphonenumber\PhoneNumberUtil::getInstance();

        $phoneData = $phoneUtil->parse($phoneNumber, $country);

        $formattedPhoneNumber = $phoneUtil->format(
            $phoneData,
            \libphonenumber\PhoneNumberFormat::E164
        );

        $user = [
            'givenName' => $firstname,
            'familyName' => $lastname,
            'email' => strtolower($email),
            'mobile' => $formattedPhoneNumber,
        ];

        $response = $this->client->createToken($user);

        return $response['data']['token'];
    }

    public function createTransaction(array $transactionData, array $allocationData, array $partyData): array
    {
        $input = $transactionData;

        $input['feeAllocation'] = 'SELLER';
        $input['allocations']['create'] = $allocationData;
        $input['parties']['create'] = $partyData;

        $transaction = $this->client->createTransaction($input);
        $link = $this->client->getCheckoutLink($transaction['data']['transaction']['id']);

        return [
            'transaction' => $transaction['data']['transaction'],
            'link' => $link['data']['link']
        ];
    }

    public function getTransaction(string $transactionId): array
    {
        $result = $this->client->getTransaction($transactionId);

        return $result['data']['transaction'];
    }

    public function cancelTransaction(string $transactionId): array
    {
        $result = $this->client->cancelTransaction($transactionId);

        return $result['data']['transaction'];
    }

    public function startDelivery(array $transaction): bool
    {
        if ($transaction['allocations'][0]['state'] === 'INITIATED') {
            return true;
        }

        if ($transaction['state'] !== 'FUNDS_RECEIVED' && $transaction['state'] !== 'INITIATED') {
            return false;
        }

        $result = $this->client->allocationStartDelivery($transaction['allocations'][0]['id']);

        if ($result['data']['allocation']['state'] === 'INITIATED') {
            return true;
        }

        return false;
    }

    public function completeDelivery(array $transaction): bool
    {
        if (in_array($transaction['allocations'][0]['state'], ['IN_TRANSIT', 'PENDING_ACCEPTANCE', 'DELIVERED', 'FUNDS_RELEASED'])) {
            return true;
        }

        $result = $this->client->allocationCompleteDelivery($transaction['allocations'][0]['id']);

        if ($result['data']['allocation']['state'] === 'PENDING_ACCEPTANCE') {
            return true;
        }

        return false;
    }
}
