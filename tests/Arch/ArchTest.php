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
    ->toBeStringBackedEnums();

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
