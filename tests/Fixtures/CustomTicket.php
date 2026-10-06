<?php

declare(strict_types=1);

namespace Dniccum\Linear\Tests\Fixtures;

use Dniccum\Linear\Contracts\ComposesLinearIssue;
use Dniccum\Linear\Models\LinearDestination;
use Workbench\App\Models\Ticket;

/**
 * Takes full control of what is sent and where.
 */
class CustomTicket extends Ticket implements ComposesLinearIssue
{
    protected $table = 'tickets';

    public function linearTitle(): string
    {
        return 'Custom: '.$this->title;
    }

    public function linearDescription(): string
    {
        return 'Custom description';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function linearComment(string $event, array $context = []): string
    {
        return "Custom {$event} comment";
    }

    public function linearDestinationOverride(): ?LinearDestination
    {
        return new LinearDestination(['team_id' => 'team-override', 'priority' => 1]);
    }

    /**
     * @return list<string>
     */
    public function linearEvents(): array
    {
        return ['created', 'updated', 'deleted'];
    }
}
