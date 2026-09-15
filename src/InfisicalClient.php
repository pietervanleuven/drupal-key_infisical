<?php

namespace Drupal\key_infisical;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Client for communicating with the Infisical API.
 */
class InfisicalClient {

  /**
   * Cached access tokens keyed by base URL and client ID.
   *
   * @var array
   */
  protected array $tokens = [];

  /**
   * In-memory cache of resolved secret values, keyed by request hash.
   *
   * This is a plain instance property, not a Drupal cache bin: secret
   * values must never be written to the database cache table or any
   * other persistent store, since that would defeat the purpose of
   * keeping them in an external secrets manager. The cache only lives
   * for the lifetime of this object (i.e. a single request).
   *
   * @var array
   */
  protected array $secrets = [];

  /**
   * Whether the "base URL is not HTTPS" warning has already been logged.
   *
   * @var bool
   */
  protected bool $insecureHostWarned = FALSE;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The logger channel for this module.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a new InfisicalClient.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger channel factory.
   */
  public function __construct(ClientInterface $httpClient, LoggerChannelFactoryInterface $loggerFactory) {
    $this->httpClient = $httpClient;
    $this->logger = $loggerFactory->get('key_infisical');
  }

  /**
   * Authenticates with Infisical and returns an access token.
   *
   * @param string $baseUrl
   *   The Infisical instance URL.
   * @param string $clientId
   *   The machine identity client ID.
   * @param string $clientSecret
   *   The machine identity client secret.
   *
   * @return string|null
   *   The access token, or NULL on failure.
   */
  protected function authenticate(string $baseUrl, string $clientId, string $clientSecret): ?string {
    // Return cached token if available. The cache key includes the base
    // URL so that the same client ID used against two different
    // Infisical instances cannot collide.
    $tokenKey = $baseUrl . '|' . $clientId;
    if (isset($this->tokens[$tokenKey])) {
      return $this->tokens[$tokenKey];
    }

    try {
      $response = $this->httpClient->request('POST', $baseUrl . '/api/v1/auth/universal-auth/login', [
        'json' => [
          'clientId' => $clientId,
          'clientSecret' => $clientSecret,
        ],
      ]);

      $data = json_decode((string) $response->getBody(), TRUE);
      if (!empty($data['accessToken'])) {
        $this->tokens[$tokenKey] = $data['accessToken'];
        return $data['accessToken'];
      }
    }
    catch (GuzzleException $e) {
      $this->logger->error('Infisical authentication failed: @message', ['@message' => $e->getMessage()]);
    }

    return NULL;
  }

  /**
   * Performs the raw secret request against Infisical.
   *
   * @param string $baseUrl
   *   The Infisical instance URL.
   * @param string $token
   *   The access token to authenticate the request with.
   * @param string $projectId
   *   The Infisical project (workspace) ID.
   * @param string $environment
   *   The environment slug (e.g. 'dev', 'prod').
   * @param string $secretPath
   *   The secret folder path (e.g. '/').
   * @param string $secretName
   *   The name of the secret to retrieve.
   *
   * @return string|null
   *   The secret value, or NULL if it is not present in the response.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   *   If the HTTP request fails. Callers are responsible for handling
   *   this, including retrying after re-authentication on a 401.
   */
  private function requestSecret(string $baseUrl, string $token, string $projectId, string $environment, string $secretPath, string $secretName): ?string {
    $response = $this->httpClient->request('GET', $baseUrl . '/api/v3/secrets/raw/' . rawurlencode($secretName), [
      'headers' => [
        'Authorization' => 'Bearer ' . $token,
      ],
      'query' => [
        'workspaceId' => $projectId,
        'environment' => $environment,
        'secretPath' => $secretPath,
      ],
    ]);

    $data = json_decode((string) $response->getBody(), TRUE);
    return $data['secret']['secretValue'] ?? NULL;
  }

  /**
   * Retrieves a secret value from Infisical.
   *
   * Authentication and HTTP failures are handled here and degrade to NULL.
   * This method deliberately does not catch \Throwable: an \Error raised
   * inside it is a bug that should surface in tests, not be swallowed. The
   * never-throws guarantee the Key API requires is enforced one layer up,
   * in InfisicalKeyProvider::getKeyValue().
   *
   * @param string $baseUrl
   *   The Infisical instance URL.
   * @param string $clientId
   *   The machine identity client ID.
   * @param string $clientSecret
   *   The machine identity client secret.
   * @param string $projectId
   *   The Infisical project (workspace) ID.
   * @param string $environment
   *   The environment slug (e.g. 'dev', 'prod').
   * @param string $secretPath
   *   The secret folder path (e.g. '/').
   * @param string $secretName
   *   The name of the secret to retrieve.
   *
   * @return string|null
   *   The secret value, or NULL on failure.
   */
  public function getSecret(string $baseUrl, string $clientId, string $clientSecret, string $projectId, string $environment, string $secretPath, string $secretName): ?string {
    // Build a cache key from every argument so that requests for
    // different secrets, environments, paths, etc. never collide.
    // Failures are intentionally never cached, so a transient error
    // does not stick for the rest of the request.
    $cacheKey = hash('sha256', implode('|', [
      $baseUrl,
      $clientId,
      $clientSecret,
      $projectId,
      $environment,
      $secretPath,
      $secretName,
    ]));
    // Use array_key_exists() rather than isset(): a successful response
    // for a secret that does not exist caches NULL, and isset() would
    // treat that as a miss and re-request it on every call.
    if (array_key_exists($cacheKey, $this->secrets)) {
      return $this->secrets[$cacheKey];
    }

    // The client secret and the secret value both travel over this
    // connection, so warn (once per instance) if it is not encrypted.
    if (stripos($baseUrl, 'https://') !== 0 && !$this->insecureHostWarned) {
      $this->logger->warning('Infisical URL "@url" is not using HTTPS; credentials and secret values will be transmitted in the clear.', ['@url' => $baseUrl]);
      $this->insecureHostWarned = TRUE;
    }

    $token = $this->authenticate($baseUrl, $clientId, $clientSecret);
    if ($token === NULL) {
      return NULL;
    }

    try {
      $secret = $this->requestSecret($baseUrl, $token, $projectId, $environment, $secretPath, $secretName);
      $this->secrets[$cacheKey] = $secret;
      return $secret;
    }
    catch (GuzzleException $e) {
      // If unauthorized, clear the cached token and retry once.
      if ($e->getCode() === 401) {
        unset($this->tokens[$baseUrl . '|' . $clientId]);
        $token = $this->authenticate($baseUrl, $clientId, $clientSecret);
        if ($token === NULL) {
          return NULL;
        }
        try {
          $secret = $this->requestSecret($baseUrl, $token, $projectId, $environment, $secretPath, $secretName);
          $this->secrets[$cacheKey] = $secret;
          return $secret;
        }
        catch (GuzzleException $retryException) {
          $this->logger->error('Infisical secret retrieval failed after retry: @message', ['@message' => $retryException->getMessage()]);
        }
      }
      else {
        $this->logger->error('Infisical secret retrieval failed: @message', ['@message' => $e->getMessage()]);
      }
    }

    return NULL;
  }

}
