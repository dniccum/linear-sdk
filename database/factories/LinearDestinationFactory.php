<?php

declare(strict_types=1);

namespace Dniccum\Linear\Database\Factories;

use Dniccum\Linear\Enums\LinearSendMode;
use Dniccum\Linear\Models\LinearDestination;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Pass an owner with `->for($user, 'owner')`; without one the factory builds
 * an instance of `config('linear.owner_model')`.
 *
 * @extends Factory<LinearDestination>
 */
class LinearDestinationFactory extends Factory
{
    protected $model = LinearDestination::class;

    /**
     * @return array<model-property<LinearDestination>, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_type' => fn (): string => self::ownerClass(),
            'owner_id' => fn (): mixed => self::ownerFactory()->createOne()->getKey(),
            'linear_organization_id' => 'org-1',
            'send_mode' => LinearSendMode::Automatic,
            'team_id' => 'team-1',
            'team_name' => 'Support',
            'project_id' => null,
            'state_id' => null,
            'label_ids' => [],
            'priority' => null,
            'assignee_id' => null,
        ];
    }

    public function manual(): static
    {
        return $this->state(fn (array $attributes): array => ['send_mode' => LinearSendMode::Manual]);
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
