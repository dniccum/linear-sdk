<?php

declare(strict_types=1);

namespace Dniccum\Linear\Tests;

use Dniccum\Linear\LinearServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Workbench\App\Models\User;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LinearServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'database.default' => 'testing',
            'database.connections.testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.debug' => false,
            'logging.default' => 'null',
            'cache.default' => 'array',
            'queue.default' => 'sync',
            'session.driver' => 'array',
            'auth.providers.users.model' => User::class,
            'linear.owner_model' => User::class,
        ]);
    }

    /**
     * A users table (the workbench gets its own from the Testbench skeleton),
     * then the workbench migrations: tickets plus the package's own migrations
     * exactly as a host application would publish them.
     */
    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(dirname(__DIR__).'/workbench/database/migrations');

        Schema::create('replies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }
}
