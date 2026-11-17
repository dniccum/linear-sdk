<?php

declare(strict_types=1);

use Dniccum\Linear\Data\Member;
use Dniccum\Linear\Enums\LinearAuthMode;
use Dniccum\Linear\LinearConfig;
use Dniccum\Linear\Support\CacheMutex;
use Dniccum\Linear\Support\LogErrorReporter;
use Dniccum\Linear\Testing\FakeLinearClient;
use Dniccum\Linear\Testing\FakeLinearOAuth;
use Dniccum\Linear\Testing\InMemory\InMemoryConnection;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Http\Client\Factory;
use Psr\Log\AbstractLogger;

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

test('the fakes refuse to talk to the real Linear and answer from memory', function () {
    $client = new FakeLinearClient($oauth = new FakeLinearOAuth);
    $http = (new Factory)->preventStrayRequests();

    expect($client->teams(new InMemoryConnection(1)))->toHaveCount(1)
        ->and($oauth->exchangeCode('code', 'verifier')->accessToken)->toBe('fake-access-token')
        ->and(fn () => $http->post('https://api.linear.app/graphql'))->toThrow(RuntimeException::class);
});

test('the cache mutex locks across callers, and runs unlocked on a store without locks', function () {
    $cache = new Repository(new ArrayStore);
    $mutex = new CacheMutex($cache);

    expect($mutex->synchronized('key', 5, 1, fn () => 'locked'))->toBe('locked');

    $held = $cache->getStore()->lock('busy', 30);
    $held->get();

    expect(fn () => $mutex->synchronized('busy', 5, 0, fn () => 'never'))->toThrow(LockTimeoutException::class);

    $held->release();

    $store = Mockery::mock(Store::class);

    expect((new CacheMutex(new Repository($store)))->synchronized('key', 5, 1, fn () => 'unlocked'))->toBe('unlocked');
});
