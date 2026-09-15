<?php

namespace Drupal\Tests\key_infisical\Unit;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\key_infisical\InfisicalClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * @coversDefaultClass \Drupal\key_infisical\InfisicalClient
 * @group key_infisical
 */
class InfisicalClientTest extends UnitTestCase {

  /**
   * The queue of mocked HTTP responses/exceptions.
   *
   * @var \GuzzleHttp\Handler\MockHandler
   */
  protected MockHandler $mockHandler;

  /**
   * The recorded request/response history, in request order.
   *
   * @var array
   */
  protected array $history = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->mockHandler = new MockHandler();
    $this->history = [];
  }

  /**
   * Builds an InfisicalClient wired to the mock handler.
   *
   * The returned Guzzle client is a real \GuzzleHttp\Client wrapping the
   * mock handler, so the default handler stack (including the http_errors
   * middleware that turns 4xx/5xx responses into exceptions) behaves the
   * same as it would in production. A history middleware is added on top
   * so tests can assert on the requests that were actually made.
   *
   * @return array
   *   A two-element array containing the \Drupal\key_infisical\InfisicalClient
   *   under test and the mocked \Drupal\Core\Logger\LoggerChannelInterface
   *   it was constructed with.
   */
  protected function createClient(): array {
    $handlerStack = HandlerStack::create($this->mockHandler);
    $handlerStack->push(Middleware::history($this->history));
    $httpClient = new Client(['handler' => $handlerStack]);

    $logger = $this->createMock(LoggerChannelInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->expects($this->once())
      ->method('get')
      ->with('key_infisical')
      ->willReturn($logger);

    $client = new InfisicalClient($httpClient, $loggerFactory);

    return [$client, $logger];
  }

  /**
   * Tests that a successful login and fetch return the secret value.
   *
   * @covers ::getSecret
   * @covers ::authenticate
   * @covers ::requestSecret
   */
  public function testGetSecretHappyPath(): void {
    [$client, $logger] = $this->createClient();
    $logger->expects($this->never())->method('error');

    $this->mockHandler->append(
      new Response(200, [], json_encode(['accessToken' => 'token-1'])),
      new Response(200, [], json_encode(['secret' => ['secretValue' => 'super-secret']])),
    );

    $result = $client->getSecret('https://infisical.example.com', 'client-id', 'client-secret', 'project-1', 'dev', '/', 'MY_SECRET');

    $this->assertSame('super-secret', $result);
    $this->assertCount(2, $this->history);

    $loginRequest = $this->history[0]['request'];
    $this->assertSame('POST', $loginRequest->getMethod());
    $this->assertSame('/api/v1/auth/universal-auth/login', $loginRequest->getUri()->getPath());

    $fetchRequest = $this->history[1]['request'];
    $this->assertSame('GET', $fetchRequest->getMethod());
    $this->assertSame('/api/v3/secrets/raw/MY_SECRET', $fetchRequest->getUri()->getPath());
    $this->assertSame('Bearer token-1', $fetchRequest->getHeaderLine('Authorization'));

    parse_str($fetchRequest->getUri()->getQuery(), $query);
    $this->assertSame([
      'workspaceId' => 'project-1',
      'environment' => 'dev',
      'secretPath' => '/',
    ], $query);
  }

  /**
   * Tests that repeated identical calls are served from the in-memory cache.
   *
   * @covers ::getSecret
   */
  public function testGetSecretIsCachedPerRequest(): void {
    [$client, $logger] = $this->createClient();
    $logger->expects($this->never())->method('error');

    $this->mockHandler->append(
      new Response(200, [], json_encode(['accessToken' => 'token-1'])),
      new Response(200, [], json_encode(['secret' => ['secretValue' => 'cached-value']])),
    );

    $first = $client->getSecret('https://infisical.example.com', 'client-id', 'client-secret', 'project-1', 'dev', '/', 'MY_SECRET');
    $second = $client->getSecret('https://infisical.example.com', 'client-id', 'client-secret', 'project-1', 'dev', '/', 'MY_SECRET');

    $this->assertSame('cached-value', $first);
    $this->assertSame('cached-value', $second);
    $this->assertCount(2, $this->history);
  }

  /**
   * Tests that differing secret names do not share a cache entry.
   *
   * Also asserts that the cached access token is reused across them.
   *
   * @covers ::getSecret
   */
  public function testCacheKeyDiffersBySecretName(): void {
    [$client, $logger] = $this->createClient();
    $logger->expects($this->never())->method('error');

    // Only one login response is queued: the second getSecret() call
    // reuses the token cached from the first call, so only the two fetch
    // requests plus a single login make up the history.
    $this->mockHandler->append(
      new Response(200, [], json_encode(['accessToken' => 'token-1'])),
      new Response(200, [], json_encode(['secret' => ['secretValue' => 'value-a']])),
      new Response(200, [], json_encode(['secret' => ['secretValue' => 'value-b']])),
    );

    $first = $client->getSecret('https://infisical.example.com', 'client-id', 'client-secret', 'project-1', 'dev', '/', 'SECRET_A');
    $second = $client->getSecret('https://infisical.example.com', 'client-id', 'client-secret', 'project-1', 'dev', '/', 'SECRET_B');

    $this->assertSame('value-a', $first);
    $this->assertSame('value-b', $second);
    $this->assertCount(3, $this->history);

    $firstFetch = $this->history[1]['request'];
    $secondFetch = $this->history[2]['request'];
    $this->assertSame('/api/v3/secrets/raw/SECRET_A', $firstFetch->getUri()->getPath());
    $this->assertSame('/api/v3/secrets/raw/SECRET_B', $secondFetch->getUri()->getPath());
  }

  /**
   * Tests that differing environments do not share a cache entry.
   *
   * Also asserts that the cached access token is reused across them.
   *
   * @covers ::getSecret
   */
  public function testCacheKeyDiffersByEnvironment(): void {
    [$client, $logger] = $this->createClient();
    $logger->expects($this->never())->method('error');

    $this->mockHandler->append(
      new Response(200, [], json_encode(['accessToken' => 'token-1'])),
      new Response(200, [], json_encode(['secret' => ['secretValue' => 'dev-value']])),
      new Response(200, [], json_encode(['secret' => ['secretValue' => 'prod-value']])),
    );

    $dev = $client->getSecret('https://infisical.example.com', 'client-id', 'client-secret', 'project-1', 'dev', '/', 'MY_SECRET');
    $prod = $client->getSecret('https://infisical.example.com', 'client-id', 'client-secret', 'project-1', 'prod', '/', 'MY_SECRET');

    $this->assertSame('dev-value', $dev);
    $this->assertSame('prod-value', $prod);
    $this->assertCount(3, $this->history);

    $firstFetch = $this->history[1]['request'];
    $secondFetch = $this->history[2]['request'];
    parse_str($firstFetch->getUri()->getQuery(), $firstQuery);
    parse_str($secondFetch->getUri()->getQuery(), $secondQuery);
    $this->assertSame('dev', $firstQuery['environment']);
    $this->assertSame('prod', $secondQuery['environment']);
  }

  /**
   * Tests that a 401 on the fetch triggers exactly one retry.
   *
   * The retry re-authenticates and succeeds with the new token.
   *
   * @covers ::getSecret
   * @covers ::authenticate
   * @covers ::requestSecret
   */
  public function testGetSecretRetriesOnceAfter401(): void {
    [$client, $logger] = $this->createClient();
    $logger->expects($this->never())->method('error');

    $this->mockHandler->append(
      new Response(200, [], json_encode(['accessToken' => 'token-1'])),
      new Response(401, [], json_encode(['message' => 'Unauthorized'])),
      new Response(200, [], json_encode(['accessToken' => 'token-2'])),
      new Response(200, [], json_encode(['secret' => ['secretValue' => 'retried-value']])),
    );

    $result = $client->getSecret('https://infisical.example.com', 'client-id', 'client-secret', 'project-1', 'dev', '/', 'MY_SECRET');

    $this->assertSame('retried-value', $result);
    $this->assertCount(4, $this->history);

    $firstFetch = $this->history[1]['request'];
    $secondFetch = $this->history[3]['request'];
    $this->assertSame('Bearer token-1', $firstFetch->getHeaderLine('Authorization'));
    $this->assertSame('Bearer token-2', $secondFetch->getHeaderLine('Authorization'));
  }

  /**
   * Tests that a failed login returns NULL and is logged.
   *
   * The secret fetch is never attempted.
   *
   * @covers ::getSecret
   * @covers ::authenticate
   */
  public function testGetSecretReturnsNullWhenAuthenticationFails(): void {
    [$client, $logger] = $this->createClient();
    $logger->expects($this->once())
      ->method('error')
      ->with('Infisical authentication failed: @message', $this->anything());

    $this->mockHandler->append(
      new Response(500, [], 'Internal Server Error'),
    );

    $result = $client->getSecret('https://infisical.example.com', 'client-id', 'client-secret', 'project-1', 'dev', '/', 'MY_SECRET');

    $this->assertNull($result);
    $this->assertCount(1, $this->history);
  }

  /**
   * Tests that a non-401 fetch failure returns NULL and is logged.
   *
   * No re-authentication or retry is triggered.
   *
   * @covers ::getSecret
   * @covers ::requestSecret
   */
  public function testGetSecretReturnsNullOnNonAuthFetchFailureWithoutRetry(): void {
    [$client, $logger] = $this->createClient();
    $logger->expects($this->once())
      ->method('error')
      ->with('Infisical secret retrieval failed: @message', $this->anything());

    $this->mockHandler->append(
      new Response(200, [], json_encode(['accessToken' => 'token-1'])),
      new Response(500, [], 'Internal Server Error'),
    );

    $result = $client->getSecret('https://infisical.example.com', 'client-id', 'client-secret', 'project-1', 'dev', '/', 'MY_SECRET');

    $this->assertNull($result);
    $this->assertCount(2, $this->history);
  }

  /**
   * Tests that a response missing the secret value returns NULL.
   *
   * No error is logged, since the request itself succeeded.
   *
   * @covers ::getSecret
   * @covers ::requestSecret
   */
  public function testGetSecretReturnsNullWhenSecretValueMissing(): void {
    [$client, $logger] = $this->createClient();
    $logger->expects($this->never())->method('error');

    $this->mockHandler->append(
      new Response(200, [], json_encode(['accessToken' => 'token-1'])),
      new Response(200, [], json_encode(['secret' => []])),
    );

    $result = $client->getSecret('https://infisical.example.com', 'client-id', 'client-secret', 'project-1', 'dev', '/', 'MY_SECRET');

    $this->assertNull($result);
    $this->assertCount(2, $this->history);
  }

}
