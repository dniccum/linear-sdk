<?php

declare(strict_types=1);

namespace Dniccum\Linear\Database\Factories;

use Dniccum\Linear\Enums\LinearIssueSource;
use Dniccum\Linear\Enums\LinearSyncStatus;
use Dniccum\Linear\Models\LinearIssueLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pass the linked model with `->for($ticket, 'linkable')` and its owner with
 * `->for($user, 'owner')`.
 *
 * @extends Factory<LinearIssueLink>
 */
class LinearIssueLinkFactory extends Factory
{
    protected $model = LinearIssueLink::class;

    /**
     * @return array<model-property<LinearIssueLink>, mixed>
     */
    public function definition(): array
    {
        return [
            'linear_organization_id' => 'org-1',
            'source' => LinearIssueSource::Automatic,
            'status' => LinearSyncStatus::Pending,
            'linear_issue_id' => fake()->uuid(),
            'payload' => ['team_id' => 'team-1', 'title' => 'Help', 'description' => 'Body'],
        ];
    }

    public function synced(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => LinearSyncStatus::Synced,
            'linear_issue_identifier' => 'SUP-1',
            'linear_issue_url' => 'https://linear.app/acme/issue/SUP-1',
            'attempts' => 1,
            'synced_at' => now(),
        ]);
    }

    public function failed(string $error = 'Linear rejected the request.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => LinearSyncStatus::Failed,
            'attempts' => 1,
            'last_error' => $error,
        ]);
    }
}
