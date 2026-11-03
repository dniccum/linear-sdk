<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Framework-free smoke test
|--------------------------------------------------------------------------
|
| Proves the core runs in an application that has no Laravel at all: it files
| an issue through the standalone `illuminate/http` client (answering from a
| fake, so nothing leaves the process), using only the package's own
| dependencies.
|
| CI runs it from a throwaway project that requires this package like any
| application would (so no dev dependency, and no Laravel, sneaks in):
|
|     LINEAR_AUTOLOAD=/path/to/project/vendor/autoload.php \
|         php -d zend.assertions=1 -d assert.exception=1 tests/Standalone/smoke.php
|
| Without LINEAR_AUTOLOAD it uses this repository's own vendor directory.
*/

require getenv('LINEAR_AUTOLOAD') ?: dirname(__DIR__, 2).'/vendor/autoload.php';

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
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;

$http = new Factory;
$http->fake(fn (Request $request) => Factory::response(['data' => ['issueCreate' => ['success' => true, 'issue' => [
    'id' => $request['variables']['input']['id'],
    'identifier' => 'SUP-1',
    'url' => 'https://linear.app/acme/issue/SUP-1',
]]]]));

$config = new LinearConfig(clientId: 'id', clientSecret: 'secret', redirectUri: 'https://app.test/linear/callback');
$client = new LinearClient($http, new LinearOAuth($http, $config), $config);

$owner = new InMemoryOwner(7, new InMemoryConnection(1, token: 'lin_oauth_token'), new InMemoryDestination(new Destination('team-1')));
$store = new InMemoryStore;
$queue = new InMemoryQueue;
$sync = new LinearIssueSync($client, $store, $queue, $config);

$sync->handleEvent(new InMemorySource(42, $owner, 'Cannot upload', 'Uploads fail.'), 'created');
$queue->work($sync);

$link = $store->links[1];
$sent = $http->recorded();

assert($link->isSynced(), 'The issue should be synced.');
assert($link->issueIdentifier() === 'SUP-1', 'The issue identifier should come from Linear.');
assert($sent->count() === 1, 'Exactly one request should be sent, got '.$sent->count().'.');
assert($sent[0][0]->url() === 'https://api.linear.app/graphql', 'Unexpected URL '.$sent[0][0]->url());
assert($sent[0][0]->header('Authorization') === ['Bearer lin_oauth_token'], 'Unexpected authorization header.');
assert($sent[0][0]['variables']['input']['title'] === 'Cannot upload', 'The title should be sent.');
assert(! class_exists('Illuminate\Foundation\Application'), 'The Laravel framework must not be installed.');

echo "OK: filed {$link->issueIdentifier()} with no Laravel application installed.\n";
