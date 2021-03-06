<?php

/**
 * Sila Api
 * PHP version 7.2
 */

namespace Silamoney\Client\Api;

use Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Response;
use JMS\Serializer\SerializerBuilder;
use Silamoney\Client\Configuration\Configuration;
use Silamoney\Client\Domain\{Account,
    BalanceEnvironments,
    BaseResponse,
    EntityMessage,
    Environments,
    GetAccountBalanceMessage,
    GetAccountsMessage,
    GetTransactionsMessage,
    GetTransactionsResponse,
    HeaderMessage,
    IssueMessage,
    LinkAccountMessage,
    LinkAccountResponse,
    PlaidSamedayAuthMessage,
    PlaidSamedayAuthResponse,
    RedeemMessage,
    SearchFilters,
    SilaBalanceMessage,
    SilaBalanceResponse,
    TransferMessage,
    User,
    SilaWallet,
    GetWalletMessage,
    RegisterWalletMessage,
    Wallet,
    UpdateWalletMessage,
    DeleteWalletMessage,
    GetWalletsMessage};
use Silamoney\Client\Security\EcdsaUtil;

/**
 * Sila Api
 *
 * @category Class
 * @package Silamoney\Client
 * @author José Morales <jmorales@digitalgeko.com>
 */
class SilaApi
{

    /**
     *
     * @var \Silamoney\Client\Configuration\Configuration
     */
    private $configuration;

    /**
     *
     * @var \JMS\Serializer\SerializerBuilder
     */
    private $serializer;

    /**
     *
     * @var string
     */
    private const AUTH_SIGNATURE = "authsignature";

    /**
     *
     * @var string
     */
    private const USER_SIGNATURE = 'usersignature';

    /**
     *
     * @var string
     */
    private const DEFAULT_ENVIRONMENT = Environments::SANDBOX;

    /**
     * @var string
     */
    private const DEFAULT_BALANCE_ENVIRONMENT = BalanceEnvironments::SANDBOX;

    /**
     * Constructor for Sila Api using custom environment.
     *
     * @param string $environment
     * @param string $balanceEnvironment
     * @param string $appHandler
     * @param string $privateKey
     */
    public function __construct(string $environment, string $balanceEnvironment, string $appHandler, string $privateKey)
    {
        \Doctrine\Common\Annotations\AnnotationRegistry::registerLoader('class_exists');
        $this->configuration = new Configuration($environment, $balanceEnvironment, $privateKey, $appHandler);
        $this->serializer = SerializerBuilder::create()->build();
    }

    /**
     * Constructor for Sila Api using specified environment.
     *
     * @param \Silamoney\Client\Domain\Environments $environment
     * @param \Silamoney\Client\Domain\BalanceEnvironments $balanceEnvironment
     * @param string $appHandler
     * @param string $privateKey
     * @return \Silamoney\Client\Api\SilaApi
     */
    public static function fromEnvironment(
        Environments $environment,
        BalanceEnvironments $balanceEnvironment,
        string $appHandler,
        string $privateKey
    ): SilaApi {
        return new SilaApi($environment, $balanceEnvironment, $appHandler, $privateKey);
    }

    /**
     * Constructor for Sila Api using sandbox environment.
     *
     * @param string $appHandler
     * @param string $privateKey
     * @return \Silamoney\Client\Api\SilaApi
     */
    public static function fromDefault(string $appHandler, string $privateKey): SilaApi
    {
        return new SilaApi(self::DEFAULT_ENVIRONMENT, self::DEFAULT_BALANCE_ENVIRONMENT, $appHandler, $privateKey);
    }

    /**
     * Checks if a specific handle is already taken.
     *
     * @param string $handle
     * @return ApiResponse
     * @throws ClientException
     * @throws Exception
     */
    public function checkHandle(string $handle): ApiResponse
    {
        $body = new HeaderMessage($handle, $this->configuration->getAuthHandle());
        $path = "/check_handle";
        $json = $this->serializer->serialize($body, 'json');
        $headers = [
            SilaApi::AUTH_SIGNATURE => EcdsaUtil::sign($json, $this->configuration->getPrivateKey())
        ];
        $response = $this->configuration->getApiClient()->callApi($path, $json, $headers);
        return $this->prepareBaseResponse($response);
    }

    /**
     * Attaches KYC data and specified blockchain address to an assigned handle.
     *
     * @param User $user
     * @return ApiResponse
     * @throws ClientException
     */
    public function register(User $user): ApiResponse
    {
        $body = new EntityMessage($user, $this->configuration->getAuthHandle());
        $path = "/register";
        $json = $this->serializer->serialize($body, 'json');
        $headers = [
            SilaApi::AUTH_SIGNATURE => EcdsaUtil::sign($json, $this->configuration->getPrivateKey())
        ];
        $response = $this->configuration->getApiClient()->callApi($path, $json, $headers);
        return $this->prepareBaseResponse($response);
    }

    /**
     * Starts KYC verification process on a registered user handle.
     *
     * @param string $userHandle
     * @param string $userPrivateKey
     * @param string $kycLevel
     * @return ApiResponse
     * @throws Exception
     */
    public function requestKYC(string $userHandle, string $userPrivateKey, string $kycLevel = ''): ApiResponse
    {
        $body = new HeaderMessage($userHandle, $this->configuration->getAuthHandle());
        if ($kycLevel != '' && $kycLevel != null) {
            $body->setKycLevel($kycLevel);
        }
        $path = '/request_kyc';
        $json = $this->serializer->serialize($body, 'json');
        $headers = [
            SilaApi::AUTH_SIGNATURE => EcdsaUtil::sign($json, $this->configuration->getPrivateKey()),
            SilaApi::USER_SIGNATURE => EcdsaUtil::sign($json, $userPrivateKey)
        ];
        $response = $this->configuration->getApiClient()->callApi($path, $json, $headers);
        return $this->prepareBaseResponse($response);
    }

    /**
     * Returns whether entity attached to user handle is verified, not valid, or still pending.
     *
     * @param string $handle
     * @param string $userPrivateKey
     * @return ApiResponse
     * @throws ClientException
     */
    public function checkKYC(string $handle, string $userPrivateKey): ApiResponse
    {
        $body = new HeaderMessage($handle, $this->configuration->getAuthHandle());
        $path = "/check_kyc";
        $json = $this->serializer->serialize($body, 'json');
        $headers = [
            SilaApi::AUTH_SIGNATURE => EcdsaUtil::sign($json, $this->configuration->getPrivateKey()),
            SilaApi::USER_SIGNATURE => EcdsaUtil::sign($json, $userPrivateKey)
        ];
        $response = $this->configuration->getApiClient()->callApi($path, $json, $headers);
        return $this->prepareBaseResponse($response);
    }

    /**
     * Uses a provided Plaid public token to link a bank account to a verified
     * entity.
     *
     * @param string $userHandle
     * @param string $publicToken
     * @param string $userPrivateKey
     * @param string|null $accountName
     * @param string|null $accountId
     * @return ApiResponse
     */
    public function linkAccount(
        string $userHandle,
        string $userPrivateKey,
        string $publicToken,
        string $accountName = null,
        string $accountId = null
    ): ApiResponse {
        $body = new LinkAccountMessage(
            $userHandle,
            $this->configuration->getAuthHandle(),
            $accountName,
            $publicToken,
            $accountId,
            null,
            null,
            null
        );
        $path = "/link_account";
        $json = $this->serializer->serialize($body, 'json');
        $headers = [
            self::AUTH_SIGNATURE => EcdsaUtil::sign($json, $this->configuration->getPrivateKey()),
            self::USER_SIGNATURE => EcdsaUtil::sign($json, $userPrivateKey)
        ];
        $response = $this->configuration->getApiClient()->callApi($path, $json, $headers);
        return $this->prepareResponse($response, LinkAccountResponse::class);
    }

    /**
     * Uses a provided Plaid public token to link a bank account to a verified
     * entity.
     *
     * @param string $userHandle
     * @param string $userPrivateKey
     * @param string $accountNumber
     * @param string routingNumber
     * @param string|null $accountName
     * @param string|null $accountType
     * @return ApiResponse
     */
    public function linkAccountDirect(string $userHandle,
    string $userPrivateKey,
    string $accountNumber,
    string $routingNumber,
    string $accountName = null,
    string $accountType = null): ApiResponse {
        $body = new LinkAccountMessage(
            $userHandle,
            $this->configuration->getAuthHandle(),
            $accountName,
            null,
            null,
            $accountNumber,
            $routingNumber,
            $accountType
        );
        $path = "/link_account";
        $json = $this->serializer->serialize($body, 'json');
        $headers = [
            self::AUTH_SIGNATURE => EcdsaUtil::sign($json, $this->configuration->getPrivateKey()),
            self::USER_SIGNATURE => EcdsaUtil::sign($json, $userPrivateKey)
        ];
        $response = $this->configuration->getApiClient()->callApi($path, $json, $headers);
        return $this->prepareResponse($response, LinkAccountResponse::class);
    }

    /**
     * Gets basic bank account names linked to user handle.
     *
     * @param string $userHandle
     * @param string $userPrivateKey
     * @return ApiResponse
     * @throws ClientException
     */
    public function getAccounts(string $userHandle, string $userPrivateKey): ApiResponse
    {
        $body = new GetAccountsMessage($userHandle, $this->configuration->getAuthHandle());
        $path = '/get_accounts';
        $json = $this->serializer->serialize($body, 'json');
        $headers = [
            SilaApi::AUTH_SIGNATURE => EcdsaUtil::sign($json, $this->configuration->getPrivateKey()),
            SilaApi::USER_SIGNATURE => EcdsaUtil::sign($json, $userPrivateKey)
        ];
        $response = $this->configuration->getApiClient()->callApi($path, $json, $headers);
        return $this->prepareResponse($response, 'array<' . Account::class . '>');
    }

    public function getAccountBalance(string $userHandle, string $userPrivateKey, string $accontName): ApiResponse
    {
        $body = new GetAccountBalanceMessage($userHandle, $this->configuration->getAuthHandle(), $accontName);
        $path = '/get_account_balance';
        $json = $this->serializer->serialize($body, 'json');
        $headers = [
            SilaApi::AUTH_SIGNATURE => EcdsaUtil::sign($json, $this->configuration->getPrivateKey()),
            SilaApi::USER_SIGNATURE => EcdsaUtil::sign($json, $userPrivateKey)
        ];
        $response = $this->configuration->getApiClient()->callApi($path, $json, $headers);
        return $this->prepareBaseResponse($response);
    }

    /**
     * Debits a specified account and issues tokens to the address belonging to
     * the requested handle.
     *
     * @param string $userHandle
     * @param int $amount
     * @param string $userPrivateKey
     * @param string $accountName
     * @return ApiResponse
     * @throws ClientException
     */
    public function issueSila(string $userHandle, int $amount, string $accountName, string $userPrivateKey): ApiResponse
    {
        $body = new IssueMessage($userHandle, $accountName, $amount, $this->configuration->getAuthHandle());
        $path = '/issue_sila';
        $json = $this->serializer->serialize($body, 'json');
        $headers = [
            SilaApi::AUTH_SIGNATURE => EcdsaUtil::sign($json, $this->configuration->getPrivateKey()),
            SilaApi::USER_SIGNATURE => EcdsaUtil::sign($json, $userPrivateKey)
        ];
        $response = $this->configuration->getApiClient()->callApi($path, $json, $headers);
        return $this->prepareBaseResponse($response);
    }

    /**
     * Starts a transfer of the requested amount of SILA to the requested destination handle.
     *
     * @param string $userHandle
     * @param string $destination
     * @param int $amount
     * @param string $userPrivateKey
     * @param string $destinationAddress
     * @return ApiResponse
     * @throws Exception
     */
    public function transferSila(
        string $userHandle,
        string $destination,
        int $amount,
        string $userPrivateKey,
        string $destinationAddress = ''
    ): ApiResponse {
        $body = new TransferMessage(
            $userHandle,
            $destination,
            $amount,
            $this->configuration->getAuthHandle(),
            ($destinationAddress != '' ? $destinationAddress : null)
        );
        $path = '/transfer_sila';
        $json = $this->serializer->serialize($body, 'json');
        $headers = [
            SilaApi::AUTH_SIGNATURE => EcdsaUtil::sign($json, $this->configuration->getPrivateKey()),
            SilaApi::USER_SIGNATURE => EcdsaUtil::sign($json, $userPrivateKey)
        ];
        $response = $this->configuration->getApiClient()->callApi($path, $json, $headers);
        return $this->prepareBaseResponse($response);
    }

    /**
     * Burns given amount of SILA at the handle's blockchain address and credits
     * their named bank account in the equivalent monetary amount.
     *
     * @param string $userHandle
     * @param int $amount
     * @param string $accountName
     * @param string $userPrivateKey
     * @return ApiResponse
     * @throws ClientException
     */
    public function redeemSila(
        string $userHandle,
        int $amount,
        string $accountName,
        string $userPrivateKey
    ): ApiResponse {
        $body = new RedeemMessage($userHandle, $amount, $accountName, $this->configuration->getAuthHandle());
        $path = '/redeem_sila';
        $json = $this->serializer->serialize($body, 'json');
        $headers = [
            self::AUTH_SIGNATURE => EcdsaUtil::sign($json, $this->configuration->getPrivateKey()),
            self::USER_SIGNATURE => EcdsaUtil::sign($json, $userPrivateKey)
        ];
        $response = $this->configuration->getApiClient()->callApi($path, $json, $headers);
        return $this->prepareBaseResponse($response);
    }

    /**
     * Gets array of user handle's transactions with detailed status information.
     *
     * @param string $userHandle
     * @param \Silamoney\Client\Domain\SearchFilters $filters
     * @param string $userPrivateKey
     * @return ApiResponse
     * @throws ClientException
     * @throws Exception
     */
    public function getTransactions(string $userHandle, SearchFilters $filters, string $userPrivateKey): ApiResponse
    {
        $body = new GetTransactionsMessage($userHandle, $this->configuration->getAuthHandle(), $filters);
        $path = '/get_transactions';
        $json = $this->serializer->serialize($body, 'json');
        $headers = [
            self::AUTH_SIGNATURE => EcdsaUtil::sign($json, $this->configuration->getPrivateKey()),
            self::USER_SIGNATURE => EcdsaUtil::sign($json, $userPrivateKey)
        ];
        $response = $this->configuration->getApiClient()->callApi($path, $json, $headers);
        return $this->prepareResponse($response, GetTransactionsResponse::class);
    }

    /**
     * Gets Sila balance for a given blockchain address.
     *
     * @param string $address
     * @return ApiResponse
     * @throws \GuzzleHttp\Exception\ServerException
     */
    public function silaBalance(string $address): ApiResponse
    {
        $body = new SilaBalanceMessage($address);
        $path = '/get_sila_balance';
        $json = $this->serializer->serialize($body, 'json');
        $headers = ['Content-Type' => 'application/json'];
        $response = $this->configuration->getBalanceClient()->callUnversionedAPI($path, $json, $headers);
        $json_string = $response->getBody()->getContents();
        return $this->prepareJsonResponse($json_string, $response->getStatusCode(), $response->getHeaders());
    }

     /**
     * Gest a public token to complete the second phase of Plaid's Sameday Microdeposit authorization
     *
     * @param string $userHandle
     * @param string $accountName
     * @return ApiResponse
     * @throws ClientException
     */
    public function plaidSamedayAuth(string $userHandle, string $accountName): ApiResponse
    {
        $body = new PlaidSamedayAuthMessage($userHandle, $accountName, $this->configuration->getAuthHandle());
        $path = '/plaid_sameday_auth';
        $json = $this->serializer->serialize($body, 'json');
        $headers = [
            self::AUTH_SIGNATURE => EcdsaUtil::sign($json, $this->configuration->getPrivateKey())
        ];
        $response = $this->configuration->getApiClient()->callAPI($path, $json, $headers);
        return $this->prepareResponse($response, PlaidSamedayAuthResponse::class);
    }

    /**
     * Gets details about the user wallet used to generate the usersignature header..
     *
     * @param string $userHandle
     * @param string $accountName
     * @param string $userPrivateKey
     * @return ApiResponse
     * @throws Exception
     */
    public function getWallet(string $userHandle, string $userPrivateKey): ApiResponse
    {
        $body = new GetWalletMessage($userHandle, $this->configuration->getAuthHandle());
        $path = '/get_wallet';
        $json = $this->serializer->serialize($body, 'json');
        $headers = [
            self::AUTH_SIGNATURE => EcdsaUtil::sign($json, $this->configuration->getPrivateKey()),
            self::USER_SIGNATURE => EcdsaUtil::sign($json, $userPrivateKey)
        ];
        $response = $this->configuration->getApiClient()->callAPI($path, $json, $headers);
        return $this->prepareResponse($response);
    }

    /**
     * Adds another "wallet"/blockchain address to a user handle.
     *
     * @param string $userHandle
     * @param Wallet $wallet
     * @param string $wallet_verification_signature
     * @param string $userPrivateKey
     * @return ApiResponse
     * @throws Exception
     */
    public function registerWallet(
        string $userHandle,
        Wallet $wallet,
        string $wallet_verification_signature,
        string $userPrivateKey
    ): ApiResponse {
        $body = new RegisterWalletMessage(
            $userHandle,
            $this->configuration->getAuthHandle(),
            $wallet,
            $wallet_verification_signature
        );
        $path = '/register_wallet';
        $json = $this->serializer->serialize($body, 'json');
        $headers = [
            self::AUTH_SIGNATURE => EcdsaUtil::sign($json, $this->configuration->getPrivateKey()),
            self::USER_SIGNATURE => EcdsaUtil::sign($json, $userPrivateKey)
        ];
        $response = $this->configuration->getApiClient()->callAPI($path, $json, $headers);
        return $this->prepareResponse($response);
    }

    /**
     * Updates nickname and/or default status of a wallet.
     *
     * @param string $userHandle
     * @param string $nickname
     * @param boolean $status
     * @param string $userPrivateKey
     * @return ApiResponse
     * @throws Exception
     */
    public function updateWallet(
        string $userHandle,
        string $nickname,
        bool $status,
        string $userPrivateKey
    ): ApiResponse {
        $body = new UpdateWalletMessage($userHandle, $this->configuration->getAuthHandle(), $nickname, $status);
        $path = '/update_wallet';
        $json = $this->serializer->serialize($body, 'json');
        $headers = [
            self::AUTH_SIGNATURE => EcdsaUtil::sign($json, $this->configuration->getPrivateKey()),
            self::USER_SIGNATURE => EcdsaUtil::sign($json, $userPrivateKey)
        ];
        $response = $this->configuration->getApiClient()->callAPI($path, $json, $headers);
        return $this->prepareResponse($response);
    }

    /**
     * Deletes a user wallet.
     *
     * @param string $userHandle
     * @param string $userPrivateKey
     * @return ApiResponse
     * @throws Exception
     */
    public function deleteWallet(string $userHandle, string $userPrivateKey): ApiResponse
    {
        $body = new DeleteWalletMessage($userHandle, $this->configuration->getAuthHandle());
        $path = '/delete_wallet';
        $json = $this->serializer->serialize($body, 'json');
        $headers = [
            self::AUTH_SIGNATURE => EcdsaUtil::sign($json, $this->configuration->getPrivateKey()),
            self::USER_SIGNATURE => EcdsaUtil::sign($json, $userPrivateKey)
        ];
        $response = $this->configuration->getApiClient()->callAPI($path, $json, $headers);
        return $this->prepareResponse($response);
    }

    /**
     * Gets a paginated list of "wallets"/blockchain addresses attached to a user handle.
     *
     * @param string $userHandle
     * @param SearchFilters $searchFilters
     * @param string $userPrivateKey
     * @return ApiResponse
     * @throws Exception
     */
    public function getWallets(
        string $userHandle,
        string $userPrivateKey,
        SearchFilters $searchFilters = null
    ): ApiResponse {
        $body = new GetWalletsMessage($userHandle, $this->configuration->getAuthHandle(), $searchFilters);
        $path = '/get_wallets';
        $json = $this->serializer->serialize($body, 'json');
        $headers = [
            self::AUTH_SIGNATURE => EcdsaUtil::sign($json, $this->configuration->getPrivateKey()),
            self::USER_SIGNATURE => EcdsaUtil::sign($json, $userPrivateKey)
        ];
        $response = $this->configuration->getApiClient()->callAPI($path, $json, $headers);
        return $this->prepareResponse($response);
    }

    /**
     * Gets the configuration api client
     * @return \Silamoney\Client\Api\ApiClient
     */
    public function getApiClient(): ApiClient
    {
        return $this->configuration->getApiClient();
    }

    /**
     * Create a new SilaWallet
     * @param string|null $private_key
     * @param string|null $address
     * @return SilaWallet
     */
    public function generateWallet($private_key = null, $address = null): SilaWallet
    {
        return new SilaWallet($private_key, $address);
    }

    /**
     * Gets the configuration api client
     * @return \Silamoney\Client\Api\ApiClient
     */
    public function getBalanceClient(): ApiClient
    {
        return $this->configuration->getBalanceClient();
    }

    private function prepareResponse(Response $response, string $className = ''): ApiResponse
    {
        $statusCode = $response->getStatusCode();
        $contents = $response->getBody()->getContents();
        if ($className == SilaBalanceResponse::class) {
            $contents = json_encode(json_decode($contents));
        }
        if ($statusCode == 200) {
            if ($className != '') {
                $baseResponse = $this->serializer->deserialize($contents, $className, 'json');
                return new ApiResponse($statusCode, $response->getHeaders(), $baseResponse);
            } else {
                return new ApiResponse($statusCode, $response->getHeaders(), json_decode($contents));
            }
        } elseif ($statusCode == 400) {
            $baseResponse = $this->serializer->deserialize($contents, BaseResponse::class, 'json');
            return new ApiResponse($statusCode, $response->getHeaders(), $baseResponse);
        } else {
            return new ApiResponse($statusCode, $response->getHeaders(), json_decode($contents));
        }
    }

    private function prepareBaseResponse(Response $response): ApiResponse
    {
        return $this->prepareResponse($response, BaseResponse::class);
    }

    private function prepareJsonResponse(string $json, int $statusCode, array $headers)
    {
        $json = json_decode($json);
        return new ApiResponse($statusCode, $headers, $json);
    }
}
