<?php

declare(strict_types=1);

namespace Dniccum\Linear\Database\Factories;

use Dniccum\Linear\Enums\LinearSyncStatus;
use Dniccum\Linear\Models\LinearCommentDelivery;
use Dniccum\Linear\Models\LinearIssueLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LinearCommentDelivery>
 */
class LinearCommentDeliveryFactory extends Factory
{
    protected $model = LinearCommentDelivery::class;

    /**
     * @return array<model-property<LinearCommentDelivery>, mixed>
     */
    public function definition(): array
    {
        return [
            'linear_issue_link_id' => LinearIssueLink::factory(),
            'status' => LinearSyncStatus::Pending,
            'linear_comment_id' => fake()->uuid(),
            'body' => fake()->sentence(),
        ];
    }
}
