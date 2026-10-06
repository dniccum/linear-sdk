<?php

declare(strict_types=1);

namespace Dniccum\Linear\Database\Factories;

use Dniccum\Linear\Enums\LinearAuthMode;
use Dniccum\Linear\Enums\LinearConnectionStatus;
use Dniccum\Linear\Models\LinearConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Pass an owner with `->for($user, 'owner')`; without one the factory builds
 * an instance of `config('linear.owner_model')`.
 *
 * @extends Factory<LinearConnection>
 */
class LinearConnectionFactory extends Factory
{
    protected $model = LinearConnection::class;

    /**
     * @return array<model-property<LinearConnection>, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_type' => fn (): string => self::ownerClass(),
            'owner_id' => fn (): mixed => self::ownerFactory()->createOne()->getKey(),
            'auth_type' => LinearAuthMode::OAuth,
            'linear_organization_id' => 'org-1',
            'organization_name' => 'Acme',
            'organization_url_key' => 'acme',
            'linear_user_id' => fake()->uuid(),
            'linear_user_name' => fake()->name(),
            'linear_user_email' => fake()->safeEmail(),
            'access_token' => 'lin_oauth_access',
            'refresh_token' => 'lin_oauth_refresh',
            'token_expires_at' => now()->addDay(),
            'scopes' => ['read', 'issues:create', 'comments:create'],
            'status' => LinearConnectionStatus::Active,
        ];
    }

    /**
     * A personal API key connection: no refresh token and no expiry.
     */
    public function apiKey(string $key = 'lin_api_key'): static
    {
        return $this->state(fn (array $attributes): array => [
            'auth_type' => LinearAuthMode::ApiKey,
            'access_token' => $key,
            'refresh_token' => null,
            'token_expires_at' => null,
            'scopes' => null,
        ]);
    }

    public function needsReconnect(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => LinearConnectionStatus::NeedsReconnect,
            'last_error' => 'Linear rejected your authorization.',
            'last_error_at' => now(),
        ]);
    }

    /**
     * @return class-string<Model>
     */
    private static function ownerClass(): string
    {
        /** @var class-string<Model> */
        return config()->string('linear.owner_model');
    }

    /**
     * @return Factory<Model>
     */
    private static function ownerFactory(): Factory
    {
        $class = self::ownerClass();
        $factory = method_exists($class, 'factory') ? $class::factory() : null;

        return $factory instanceof Factory
            ? $factory
            : throw new LogicException('Pass an owner with for($owner, \'owner\'), or give '.$class.' a factory.');
    }
}
