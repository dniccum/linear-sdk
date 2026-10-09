<?php

declare(strict_types=1);
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Facade;

arch('no debugging statements are left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit', 'sleep', 'usleep'])
    ->not->toBeUsed();

arch('every source file declares strict types')
    ->expect('Dniccum\Linear')
    ->toUseStrictTypes();

arch('the workbench and factories are strict too')
    ->expect(['Workbench\App', 'Dniccum\Linear\Database\Factories'])
    ->toUseStrictTypes();

arch('data transfer objects are readonly')
    ->expect('Dniccum\Linear\Data')
    ->classes
    ->toBeReadonly();

arch('data transfer objects are final, apart from the base class')
    ->expect('Dniccum\Linear\Data')
    ->classes
    ->toBeFinal()
    ->ignoring('Dniccum\Linear\Data\Data');

arch('enums are backed enums')
    ->expect('Dniccum\Linear\Enums')
    ->toBeEnums()
    ->toBeStringBackedEnums()
    ->ignoring('Dniccum\Linear\Enums\LinearPriority');

// Priorities are numbers in Linear's API and in the HTTP contract.
arch('the priority enum is an int backed enum')
    ->expect('Dniccum\Linear\Enums\LinearPriority')
    ->toBeIntBackedEnums();

arch('contracts are interfaces')
    ->expect('Dniccum\Linear\Contracts')
    ->toBeInterfaces();

arch('traits are traits')
    ->expect('Dniccum\Linear\Concerns')
    ->toBeTraits();

arch('models are Eloquent models')
    ->expect('Dniccum\Linear\Models')
    ->toExtend(Model::class);

arch('jobs are queued')
    ->expect('Dniccum\Linear\Jobs')
    ->toImplement(ShouldQueue::class);

arch('controllers extend the package controller')
    ->expect('Dniccum\Linear\Http\Controllers')
    ->toHaveSuffix('Controller')
    ->toExtend('Dniccum\Linear\Http\Controllers\Controller')
    ->ignoring('Dniccum\Linear\Http\Controllers\Controller');

arch('requests are form requests')
    ->expect('Dniccum\Linear\Http\Requests')
    ->toExtend(FormRequest::class);

arch('the facade is a facade')
    ->expect('Dniccum\Linear\Facades')
    ->toExtend(Facade::class);

arch('actions are plain classes with an execute method')
    ->expect('Dniccum\Linear\Actions')
    ->toHaveMethod('execute');

arch('events are immutable value objects')
    ->expect('Dniccum\Linear\Events')
    ->toBeReadonly()
    ->toBeFinal();

arch('the package never reads the environment directly')
    ->expect('Dniccum\Linear')
    ->not->toUse(['env', 'getenv']);

arch('only the testing support code touches PHPUnit')
    ->expect('PHPUnit')
    ->toOnlyBeUsedIn('Dniccum\Linear\Testing');

arch('the domain does not depend on the HTTP layer')
    ->expect(['Dniccum\Linear\Services', 'Dniccum\Linear\Models', 'Dniccum\Linear\Data', 'Dniccum\Linear\Jobs'])
    ->not->toUse('Dniccum\Linear\Http');

/*
|--------------------------------------------------------------------------
| Framework independence
|--------------------------------------------------------------------------
|
| The core must run in any PHP application: it may use PSR interfaces and
| nothing from Laravel, Carbon or the Laravel adapter. Everything that
| touches Laravel lives in the Laravel adapter list below.
|
*/

const LINEAR_CORE = [
    'Dniccum\Linear\Contracts',
    'Dniccum\Linear\Transport',
    'Dniccum\Linear\Enums',
    'Dniccum\Linear\Events',
    'Dniccum\Linear\Exceptions',
    'Dniccum\Linear\LinearConfig',
    'Dniccum\Linear\Services\LinearClient',
    'Dniccum\Linear\Services\LinearOAuth',
    'Dniccum\Linear\Services\LinearIssueSync',
    'Dniccum\Linear\Services\DestinationResolver',
    'Dniccum\Linear\Services\ConnectionClient',
    'Dniccum\Linear\Support\Json',
    'Dniccum\Linear\Support\Emoji',
    'Dniccum\Linear\Support\Uuid',
    'Dniccum\Linear\Support\NullMutex',
    'Dniccum\Linear\Support\LogErrorReporter',
    'Dniccum\Linear\Testing\InMemory',
    'Dniccum\Linear\Testing\FakeLinearClient',
    'Dniccum\Linear\Testing\FakeLinearOAuth',
    'Dniccum\Linear\Testing\NullTransport',
    'Dniccum\Linear\Data',
];

// Illuminate itself is covered by the "only depends on PHP and PSR" test below:
// banning the bare vendor namespace crashes older releases of the arch plugin.
arch('the core does not use Carbon or the Laravel adapter')
    ->expect(LINEAR_CORE)
    ->not->toUse([
        'Laravel',
        'Carbon',
        'Spatie',
        'Dniccum\Linear\Laravel',
        'Dniccum\Linear\Models',
        'Dniccum\Linear\Http',
        'Dniccum\Linear\Jobs',
        'Dniccum\Linear\Concerns',
        'Dniccum\Linear\Actions',
        'Dniccum\Linear\Facades',
        'Dniccum\Linear\View',
        'Dniccum\Linear\Observers',
        'Dniccum\Linear\Support\ModelHooks',
        'Dniccum\Linear\Support\LinearAssets',
        'Dniccum\Linear\Linear',
        'Dniccum\Linear\LinearServiceProvider',
    ])
    ->ignoring([
        // The page's payloads and the OAuth callback result are part of the
        // Laravel adapter, even though they live in the Data namespace.
        'Dniccum\Linear\Data\Settings',
        'Dniccum\Linear\Data\OAuthResult',
    ]);

arch('the core only depends on PHP and PSR interfaces')
    ->expect(LINEAR_CORE)
    ->toOnlyUse([
        'Dniccum\Linear',
        'Psr\Http\Client',
        'Psr\Http\Message',
        'Psr\EventDispatcher',
        'Psr\Log',
        'PHPUnit',
    ])
    ->ignoring([
        'Dniccum\Linear\Data\Settings',
        'Dniccum\Linear\Data\OAuthResult',
    ]);
