<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Framework-free smoke test
|--------------------------------------------------------------------------
|
| Proves the core runs in an application that has no Laravel at all: it files
| an issue through Symfony's PSR-18 client, using only the package's
| dependencies plus `symfony/http-client` and `nyholm/psr7`.
|
| CI runs it on an install without dev dependencies:
|
|     composer update --no-dev
|     composer require symfony/http-client nyholm/psr7
|     php -d zend.assertions=1 -d assert.exception=1 tests/Standalone/smoke.php
|
*/

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Dniccum\Linear\Data\Destination;
use Dniccum\Linear\LinearConfig;
use Dniccum\Linear\Services\LinearClient;
use Dniccum\Linear\Services\LinearIssueSync;
use Dniccum\Linear\Services\LinearOAuth;
use Dniccum\Linear\Testing\InMemory\InMemoryConnection;
use Dniccum\Linear\Testing\InMemory\InMemoryDestination;
use Dniccum\Linear\Testing\InMemory\InMemoryOwner;
use Dniccum\Linear\Testing\InMemory\InMemoryQueue;
use Dniccum\Linear\Testing\InMemory\InMemorySource;
use Dniccum\Linear\Testing\InMemory\InMemoryStore;
use Dniccum\Linear\Transport\PsrTransport;
use Illuminate\Support\Str;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;

$requests = [];

$http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
    $body = json_decode($options['body'], true);
    $headers = $options['normalized_headers'];
    $requests[] = ['url' => $url, 'authorization' => $headers['authorization'][0] ?? '', 'body' => $body];

    return new MockResponse(
        json_encode(['data' => ['issueCreate' => ['success' => true, 'issue' => [
            'id' => $body['variables']['input']['id'],
            'identifier' => 'SUP-1',
            'url' => 'https://linear.app/acme/issue/SUP-1',
        ]]]], JSON_THROW_ON_ERROR),
        ['http_code' => 200, 'response_headers' => ['content-type: application/json']],
    );
});

$psr17 = new Psr17Factory;
$transport = new PsrTransport(new Psr18Client($http), $psr17, $psr17);
$config = new LinearConfig(clientId: 'id', clientSecret: 'secret', redirectUri: 'https://app.test/linear/callback');
$client = new LinearClient($transport, new LinearOAuth($transport, $config), $config);

$owner = new InMemoryOwner(7, new InMemoryConnection(1, token: 'lin_oauth_token'), new InMemoryDestination(new Destination('team-1')));
$store = new InMemoryStore;
$queue = new InMemoryQueue;
$sync = new LinearIssueSync($client, $store, $queue, $config);

$sync->handleEvent(new InMemorySource(42, $owner, 'Cannot upload', 'Uploads fail.'), 'created');
$queue->work($sync);

$link = $store->links[1];

assert($link->isSynced(), 'The issue should be synced.');
assert($link->issueIdentifier() === 'SUP-1', 'The issue identifier should come from Linear.');
assert(count($requests) === 1, 'Exactly one request should be sent, got '.count($requests).'.');
assert($requests[0]['url'] === 'https://api.linear.app/graphql', 'Unexpected URL '.$requests[0]['url']);
assert(str_ends_with($requests[0]['authorization'], 'Bearer lin_oauth_token'), 'Unexpected authorization '.$requests[0]['authorization']);
assert($requests[0]['body']['variables']['input']['title'] === 'Cannot upload', 'The title should be sent.');
assert(! class_exists(Str::class, false), 'Laravel must not be loaded.');
assert(! class_exists('Illuminate\Support\Str'), 'Laravel must not even be installed.');

echo "OK: filed {$link->issueIdentifier()} through Symfony's PSR-18 client without Laravel.\n";
