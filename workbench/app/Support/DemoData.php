<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Dniccum\Linear\Enums\LinearIssueSource;
use Dniccum\Linear\Enums\LinearSyncStatus;
use Dniccum\Linear\Models\LinearIssueLink;
use Workbench\App\Models\Ticket;
use Workbench\App\Models\User;

/**
 * Stub data so the configuration page can be exercised without a real Linear
 * workspace: an active connection and a failed issue to retry.
 */
final class DemoData
{
    public static function seed(User $user): void
    {
        if ($user->linearConnection()->exists()) {
            return;
        }

        $user->linearConnection()->create([
            'linear_organization_id' => 'org-1',
            'organization_name' => 'Acme',
            'organization_url_key' => 'acme',
            'linear_user_id' => 'viewer-1',
            'linear_user_name' => 'Ada Lovelace',
            'linear_user_email' => 'ada@example.com',
            'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'token_expires_at' => now()->addYear(),
            'scopes' => ['read', 'issues:create', 'comments:create'],
        ]);

        $ticket = Ticket::query()->create(['user_id' => $user->id, 'title' => 'Checkout button does nothing', 'body' => 'Reported by a customer.']);

        LinearIssueLink::query()->create([
            'linkable_type' => $ticket->getMorphClass(),
            'linkable_id' => $ticket->id,
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->id,
            'linear_organization_id' => 'org-1',
            'source' => LinearIssueSource::Automatic,
            'status' => LinearSyncStatus::Failed,
            'linear_issue_id' => (string) fake()->uuid(),
            'payload' => ['team_id' => 'team-1', 'title' => $ticket->title, 'description' => 'Reported by a customer.'],
            'attempts' => 5,
            'last_error' => 'Linear is temporarily unavailable. We will retry shortly.',
        ]);
    }
}
