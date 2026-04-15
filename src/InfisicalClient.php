<?php

namespace Drupal\infisical;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Client for communicating with the Infisical API.
 */
class InfisicalClient {

  /**
   * Cached access tokens keyed by client ID.
   *
   * @var array
   */
  protected array $tokens = [];

  /**
   * The HTTP client.
   */
  protected ClientInterface $httpClient;

  /**
   * The logger.
   */
  protected $logger;

  /**
   * Constructs a new InfisicalClient.
   */
  public function __construct(ClientInterface $httpClient, LoggerChannelFactoryInterface $loggerFactory) {
    $this->httpClient = $httpClient;
    $this->logger = $loggerFactory->get('infisical');
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
    // Return cached token if available.
    if (isset($this->tokens[$clientId])) {
      return $this->tokens[$clientId];
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
        $this->tokens[$clientId] = $data['accessToken'];
        return $data['accessToken'];
      }
    }
    catch (GuzzleException $e) {
      $this->logger->error('Infisical authentication failed: @message', ['@message' => $e->getMessage()]);
    }

    return NULL;
  }

  /**
   * Retrieves a secret value from Infisical.
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
    $token = $this->authenticate($baseUrl, $clientId, $clientSecret);
    if ($token === NULL) {
      return NULL;
    }

    try {
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
    catch (GuzzleException $e) {
      // If unauthorized, clear cached token and retry once.
      if ($e->getCode() === 401) {
        unset($this->tokens[$clientId]);
        $token = $this->authenticate($baseUrl, $clientId, $clientSecret);
        if ($token === NULL) {
          return NULL;
        }
        try {
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
