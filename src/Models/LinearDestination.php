<?php

declare(strict_types=1);

namespace Dniccum\Linear\Models;

use Carbon\CarbonInterface;
use Dniccum\Linear\Data\Destination;
use Dniccum\Linear\Database\Factories\LinearDestinationFactory;
use Dniccum\Linear\Enums\LinearSendMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Per-owner Linear settings: where issues are filed, and whether new records
 * are sent automatically or only when `sendToLinear()` is called. In manual
 * mode the destination is still the default for manual sends.
 *
 * The destination IDs belong to the workspace recorded in
 * linear_organization_id; if the owner reconnects a different workspace the
 * destination stops applying until it is reconfigured.
 *
 * An unsaved instance can be returned from a source model's
 * `linearDestinationOverride()` hook to route that model somewhere else.
 *
 * @property int $id
 * @property string $owner_type
 * @property int|string $owner_id
 * @property string|null $linear_organization_id
 * @property LinearSendMode $send_mode
 * @property string $team_id
 * @property string|null $team_name
 * @property string|null $project_id
 * @property string|null $state_id
 * @property list<string>|null $label_ids
 * @property int|null $priority
 * @property string|null $assignee_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Model|null $owner
 */
class LinearDestination extends Model
{
    /** @use HasFactory<LinearDestinationFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'owner_type',
        'owner_id',
        'linear_organization_id',
        'send_mode',
        'team_id',
        'team_name',
        'project_id',
        'state_id',
        'label_ids',
        'priority',
        'assignee_id',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'send_mode' => 'automatic',
    ];

    public function getTable(): string
    {
        return config()->string('linear.table_prefix', 'linear_').'destinations';
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Whether new records should be filed automatically using the given
     * connection: it must be in automatic mode, and the connection must be
     * healthy and authorized for the workspace the destination lives in.
     *
     * A destination without a recorded workspace (an unsaved override) applies
     * to whichever workspace the connection belongs to.
     */
    public function appliesTo(?LinearConnection $connection): bool
    {
        return $this->send_mode === LinearSendMode::Automatic
            && $connection !== null
            && $connection->isActive()
            && ($this->linear_organization_id === null || $connection->linear_organization_id === $this->linear_organization_id);
    }

    public function destination(): Destination
    {
        return new Destination(
            teamId: $this->team_id,
            projectId: $this->project_id,
            stateId: $this->state_id,
            labelIds: $this->label_ids ?? [],
            priority: $this->priority,
            assigneeId: $this->assignee_id,
        );
    }

    protected static function newFactory(): LinearDestinationFactory
    {
        return LinearDestinationFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'send_mode' => LinearSendMode::class,
            'label_ids' => 'array',
            'priority' => 'integer',
        ];
    }
}
