<?php

declare(strict_types=1);

use Dniccum\Linear\Data\Member;
use Dniccum\Linear\Data\Tokens;
use Dniccum\Linear\Enums\LinearAuthMode;
use Dniccum\Linear\LinearConfig;
use Dniccum\Linear\Support\Json;
use Dniccum\Linear\Support\LogErrorReporter;
use Dniccum\Linear\Support\Uuid;
use Dniccum\Linear\Testing\FakeLinearClient;
use Dniccum\Linear\Testing\FakeLinearOAuth;
use Dniccum\Linear\Testing\InMemory\InMemoryConnection;
use Dniccum\Linear\Testing\NullTransport;
use Dniccum\Linear\Transport\PsrTransport;
use Dniccum\Linear\Transport\Response;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;

test('uuids are random version 4 identifiers', function () {
    expect(Uuid::v4())->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and(Uuid::v4())->not->toBe(Uuid::v4());
});

test('json helpers read nested values and detect blanks', function () {
    $data = ['a' => ['b' => ['c' => 1]], 'x' => null];

    expect(Json::get($data, 'a.b.c'))->toBe(1)
        ->and(Json::get($data, 'a.b.nope', 'fallback'))->toBe('fallback')
        ->and(Json::get($data, 'x.y'))->toBeNull()
        ->and(Json::get('scalar', 'a'))->toBeNull()
        ->and(Json::blank(null))->toBeTrue()
        ->and(Json::blank("  \n"))->toBeTrue()
        ->and(Json::blank('0'))->toBeFalse();
});

test('the config reads the same keys as the Laravel config file', function () {
    $config = LinearConfig::fromArray([
        'auth_mode' => 'api_key',
        'client_id' => 'id',
        'client_secret' => 'secret',
        'redirect' => 'https://app.test/callback',
        'scopes' => ['read'],
        'api_url' => 'https://proxy.test/',
        'authorize_url' => 'https://proxy.test/authorize',
        'on_update' => 'comment',
        'on_delete' => 'comment',
    ]);

    expect($config->authMode)->toBe(LinearAuthMode::ApiKey)
        ->and($config->hasOAuthCredentials())->toBeTrue()
        ->and($config->scopes)->toBe(['read'])
        ->and($config->apiEndpoint('/graphql'))->toBe('https://proxy.test/graphql')
        ->and($config->authorizeUrl)->toBe('https://proxy.test/authorize')
        ->and([$config->onUpdate, $config->onDelete])->toBe(['comment', 'comment']);
});

test('the config falls back to sensible defaults', function (array $input) {
    $config = LinearConfig::fromArray($input);

    expect($config->authMode)->toBe(LinearAuthMode::OAuth)
        ->and($config->hasOAuthCredentials())->toBeFalse()
        ->and($config->redirectUri)->toBeNull()
        ->and($config->apiUrl)->toBe('https://api.linear.app')
        ->and($config->authorizeUrl)->toBe('https://linear.app/oauth/authorize')
        ->and([$config->onUpdate, $config->onDelete])->toBe(['ignore', 'ignore']);
})->with([
    'empty' => [[]],
    'blank values' => [['auth_mode' => 'nonsense', 'client_id' => ' ', 'client_secret' => null, 'redirect' => '', 'api_url' => '', 'authorize_url' => null]],
    'an enum is accepted' => [['auth_mode' => LinearAuthMode::OAuth]],
]);

test('scopes default only when the key is missing', function () {
    expect(LinearConfig::fromArray([])->scopes)->toBe(LinearConfig::DEFAULT_SCOPES)
        ->and(LinearConfig::fromArray(['scopes' => []])->scopes)->toBe([]);
});

test('the error reporter writes to a PSR logger', function () {
    $logger = new class extends AbstractLogger
    {
        /** @var list<array{mixed, string|Stringable, array<mixed>}> */
        public array $records = [];

        public function log($level, $message, array $context = []): void
        {
            $this->records[] = [$level, $message, $context];
        }
    };
    $e = new RuntimeException('kaboom');

    (new LogErrorReporter($logger))->report($e);
    (new LogErrorReporter)->report($e);

    expect($logger->records)->toBe([['error', 'kaboom', ['exception' => $e]]]);
});

test('member initials use the first letters of the first two words', function () {
    expect((new Member('1', "  ada   byron\u{a0}lovelace "))->displayInitials())->toBe('AB')
        ->and((new Member('1', 'élan'))->displayInitials())->toBe('É')
        ->and((new Member('1', '   '))->displayInitials())->toBe('?');
});

test('token expiry is counted from the moment given', function () {
    $tokens = new Tokens('access', null, 60, []);
    $mutable = new DateTime('2026-01-01T00:00:00Z');

    expect($tokens->expiresAt($mutable)?->format('c'))->toBe('2026-01-01T00:01:00+00:00')
        // The moment passed in is never modified.
        ->and($mutable->format('c'))->toBe('2026-01-01T00:00:00+00:00')
        ->and($tokens->expiresAt()?->getTimestamp())->toBeGreaterThan(time() + 55);
});

test('the fakes never send a request', function () {
    $transport = new NullTransport;

    expect(fn () => $transport->postJson('https://api.linear.app/graphql', [], []))->toThrow(LogicException::class, 'does not send requests')
        ->and(fn () => $transport->postForm('https://api.linear.app/oauth/token', [], []))->toThrow(LogicException::class, 'does not send requests');

    $client = new FakeLinearClient(new FakeLinearOAuth);

    expect($client->teams(new InMemoryConnection(1)))->toHaveCount(1);
});

test('the PSR transport posts JSON and forms through any PSR-18 client', function () {
    $sent = [];
    $client = new class($sent) implements ClientInterface
    {
        /** @param list<RequestInterface> $sent */
        public function __construct(public array &$sent) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->sent[] = $request;

            return new PsrResponse(201, [], '{"data":{"ok":true}}');
        }
    };
    $factory = new HttpFactory;
    $transport = new PsrTransport($client, $factory, $factory);

    $json = $transport->postJson('https://api.linear.app/graphql', ['Authorization' => 'Bearer t'], ['query' => '{ viewer { id } }', 'variables' => ['a' => 'é/1']]);
    $form = $transport->postForm('https://api.linear.app/oauth/token', [], ['grant_type' => 'refresh_token', 'refresh_token' => 'a b']);

    expect($json->status())->toBe(201)
        ->and($json->successful())->toBeTrue()
        ->and($json->json('data.ok'))->toBeTrue()
        ->and($form->json())->toBe(['data' => ['ok' => true]])
        ->and($sent[0]->getMethod())->toBe('POST')
        ->and((string) $sent[0]->getUri())->toBe('https://api.linear.app/graphql')
        ->and($sent[0]->getHeaderLine('Authorization'))->toBe('Bearer t')
        ->and($sent[0]->getHeaderLine('Accept'))->toBe('application/json')
        ->and($sent[0]->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and(json_decode((string) $sent[0]->getBody(), true))->toBe(['query' => '{ viewer { id } }', 'variables' => ['a' => 'é/1']])
        ->and($sent[1]->getHeaderLine('Content-Type'))->toBe('application/x-www-form-urlencoded')
        ->and((string) $sent[1]->getBody())->toBe('grant_type=refresh_token&refresh_token=a+b');
});

test('a response knows its status class and tolerates bodies that are not JSON objects', function () {
    expect((new Response(204))->json())->toBeNull()
        ->and((new Response(404))->successful())->toBeFalse()
        ->and((new Response(503))->serverError())->toBeTrue()
        ->and((new Response(499))->serverError())->toBeFalse()
        ->and(Response::fromPsr(new PsrResponse(200, [], 'not json'))->data)->toBeNull()
        ->and(Response::fromPsr(new PsrResponse(200, [], '"text"'))->data)->toBeNull()
        ->and(Response::fromPsr(new PsrResponse(200, [], '{"a":1}'))->json('a'))->toBe(1);
});
