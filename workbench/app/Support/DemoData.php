<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Dniccum\Linear\Data\Label;
use Dniccum\Linear\Data\Member;
use Dniccum\Linear\Data\Project;
use Dniccum\Linear\Data\Team;
use Dniccum\Linear\Data\TeamOptions;
use Dniccum\Linear\Data\WorkflowState;
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
    /**
     * Teams with a mix of emoji icons, icon identifiers and no styling at all.
     *
     * @return list<Team>
     */
    public static function teams(): array
    {
        return [
            new Team('team-1', 'Support', 'SUP', '#5e6ad2', '🛟'),
            new Team('team-2', 'Engineering', 'ENG', '#f2994a', 'Bug'),
            new Team('team-3', 'Design', 'DSN'),
        ];
    }

    /**
     * Realistic options for the first team: coloured projects (one with an
     * icon identifier, one with no icon), one status of every type, and
     * members with a working avatar, no avatar and a broken avatar URL.
     */
    public static function options(): TeamOptions
    {
        return new TeamOptions(
            team: self::teams()[0],
            projects: [
                new Project('project-1', 'Inbox', '#4cb782', '📥'),
                new Project('project-2', 'Onboarding revamp', '#f2994a', '🚀'),
                new Project('project-3', 'Mobile app', '#26b5ce', 'Mobile'),
                new Project('project-4', 'Billing', '#eb5757'),
            ],
            states: [
                new WorkflowState('state-1', 'Triage', 'triage', '#bec2c8'),
                new WorkflowState('state-2', 'Backlog', 'backlog', '#bec2c8'),
                new WorkflowState('state-3', 'Todo', 'unstarted', '#e2e2e2'),
                new WorkflowState('state-4', 'In Progress', 'started', '#f2c94c'),
                new WorkflowState('state-5', 'In Review', 'started', '#0f783c'),
                new WorkflowState('state-6', 'Done', 'completed', '#5e6ad2'),
                new WorkflowState('state-7', 'Canceled', 'canceled', '#95a2b3'),
                new WorkflowState('state-8', 'Duplicate', 'duplicate', '#95a2b3'),
            ],
            labels: [
                new Label('label-1', 'Bug', '#eb5757'),
                new Label('label-2', 'Feature', '#bb87fc'),
                new Label('label-3', 'Improvement', '#4ea7fc'),
                new Label('label-4', 'Question', null),
            ],
            members: [
                new Member('user-1', 'Ada Lovelace', 'https://placehold.co/64x64/5e6ad2/ffffff.png?text=AL', 'AL', '#5e6ad2'),
                new Member('user-2', 'Grace Hopper', null, 'GH', '#f2994a'),
                new Member('user-3', 'Linus (broken avatar)', 'https://avatars.invalid/linus.png', 'LB', '#26b5ce'),
                new Member('user-4', 'Margaret Hamilton', 'https://placehold.co/64x64/4cb782/ffffff.png?text=MH', 'MH', '#4cb782'),
                new Member('user-5', 'No initials', null, null, null),
            ],
        );
    }

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
