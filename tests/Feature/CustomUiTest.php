<?php

declare(strict_types=1);

use Dniccum\Linear\Data\Member;
use Dniccum\Linear\Data\Project;
use Dniccum\Linear\Data\Team;
use Dniccum\Linear\Data\TeamOptions;
use Dniccum\Linear\Data\WorkflowState;
use Dniccum\Linear\Enums\LinearSendMode;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\Facades\Linear;
use Dniccum\Linear\Models\LinearConnection;
use Dniccum\Linear\Tests\Fixtures\CustomUi\LinearSettingsController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Workbench\App\Models\User;

/*
 * Runs the worked example from docs/custom-ui.md: a custom controller and Blade
 * view on top of the headless Actions, with the bundled UI switched off.
 */

beforeEach(function () {
    config(['linear.routes.ui' => false]);
    reloadLinearRoutes();

    View::addLocation(__DIR__.'/../Fixtures/CustomUi/views');
    Route::middleware('web')->prefix('integrations/linear')->group(function () {
        Route::get('/', [LinearSettingsController::class, 'show'])->name('integrations.linear');
        Route::post('/', [LinearSettingsController::class, 'update'])->name('integrations.linear.update');
    });

    $this->fake = Linear::fake()
        ->withTeams([new Team('team-1', 'Support', 'SUP', '#5e6ad2', '🛟'), new Team('team-2', 'Tools', 'tls')])
        ->withTeamOptions(new TeamOptions(
            team: new Team('team-1', 'Support', 'SUP', '#5e6ad2', '🛟'),
            projects: [new Project('project-1', 'Inbox', '#4cb782', '📥'), new Project('project-2', 'Plumbing', 'javascript:alert(1)', 'Wrench')],
            states: [new WorkflowState('state-1', 'In Review', 'started', '#0f783c'), new WorkflowState('state-2', 'Parked', 'brand-new', null)],
            labels: [],
            members: [
                new Member('user-1', 'Ada Lovelace', 'https://public.linear.app/ada.png', 'AL', '#5e6ad2'),
                new Member('user-2', 'grace hopper'),
            ],
        ));

    $this->owner = User::factory()->create();
    LinearConnection::factory()->for($this->owner, 'owner')->create();
});

test('the custom page renders tiles, avatars with an initials fallback, and status and priority labels', function () {
    $this->actingAs($this->owner)->get('/integrations/linear')
        ->assertOk()
        ->assertSee('🛟', false)
        ->assertSee('TL', false)
        ->assertSee('background: #5e6ad2', false)
        ->assertSee('📥', false)
        ->assertDontSee('javascript:alert', false)
        ->assertSee('(Started)', false)
        ->assertSee('(brand-new)', false)
        ->assertSee('<img src="https://public.linear.app/ada.png" alt="" loading="lazy" referrerpolicy="no-referrer">', false)
        ->assertSee('GH', false)
        ->assertSeeInOrder(['No priority', 'Urgent', 'High', 'Medium', 'Low'])
        ->assertDontSee('Connect Linear');
});

test('another team can be chosen with the query string and a saved destination is checked', function () {
    $this->actingAs($this->owner)->post('/integrations/linear', ['team' => 'team-1', 'project' => 'project-1', 'state' => 'state-1', 'assignee' => 'user-2', 'priority' => '2'])
        ->assertRedirect()
        ->assertSessionHas('status');

    expect($this->owner->fresh()->linearDestination)
        ->team_id->toBe('team-1')
        ->project_id->toBe('project-1')
        ->state_id->toBe('state-1')
        ->assignee_id->toBe('user-2')
        ->priority->toBe(2)
        ->send_mode->toBe(LinearSendMode::Automatic);

    $this->actingAs($this->owner)->get('/integrations/linear?team=team-1')
        ->assertOk()
        ->assertSee('value="project-1" checked', false)
        ->assertSee('value="2" checked', false);
});

test('invalid input and stale IDs come back as validation errors', function () {
    $this->actingAs($this->owner)->from('/integrations/linear')->post('/integrations/linear', ['team' => 'team-1', 'priority' => '9'])
        ->assertSessionHasErrors('priority');

    $this->actingAs($this->owner)->from('/integrations/linear')->post('/integrations/linear', ['team' => 'team-1', 'project' => 'gone'])
        ->assertSessionHasErrors();
});

test('an owner without a connection is offered a connect link, and Linear errors are shown', function () {
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get('/integrations/linear')
        ->assertOk()
        ->assertSee('Connect Linear')
        ->assertSee('not connected');

    $this->fake->client->failWith('teams', new LinearApiException('Linear is down', LinearApiException::TRANSIENT));

    $this->actingAs($this->owner)->get('/integrations/linear')->assertOk()->assertSee('Linear is down');
});

test('no team at all renders an empty page', function () {
    $this->fake->withTeams([]);

    $this->actingAs($this->owner)->get('/integrations/linear')->assertOk()->assertDontSee('<form', false);
});

test('docs/custom-ui.md contains the exact code this test suite runs', function () {
    $docs = (string) file_get_contents(__DIR__.'/../../docs/custom-ui.md');
    $view = (string) file_get_contents(__DIR__.'/../Fixtures/CustomUi/views/integrations/linear.blade.php');
    $controller = (string) file_get_contents(__DIR__.'/../Fixtures/CustomUi/LinearSettingsController.php');

    // The docs use the application's own namespace and user model, and no test note.
    $body = substr($controller, (int) strpos($controller, 'class LinearSettingsController'));

    expect($docs)->toContain($view)->toContain($body)
        ->and($docs)->toContain('namespace App\Http\Controllers;')->toContain('use App\Models\User;');
});
