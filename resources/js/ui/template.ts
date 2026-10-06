/**
 * The whole UI as Alpine-annotated HTML strings.
 *
 * Nothing dynamic is interpolated into the markup: every value (URLs, CSRF
 * token, names, messages) reaches the DOM through Alpine bindings (`x-text`,
 * `:href`, `:value`, ...), which write properties rather than parse HTML. That
 * makes the templates static, XSS-safe and keeps the Blade view trivial.
 *
 * Child templates read root state (`connection`, `failures`, `settings`, ...)
 * through Alpine's scope chain but never write it; they dispatch events
 * instead (see `components/events.ts`).
 */
import { COMPONENT_NAMES } from '../components';
import { LINEAR_EVENTS } from '../components/events';

/** Inline validation message for a form field, linked via `aria-describedby`. */
function fieldError(field: string, id: string): string {
  return `<p class="linear-field__error" id="${id}" x-show="hasError('${field}')" x-text="firstError('${field}')"></p>`;
}

function headerTemplate(): string {
  return `
<header class="linear-header">
  <template x-if="settings.brand.logo">
    <img class="linear-header__logo" :src="settings.brand.logo" alt="" width="44" height="44">
  </template>
  <div class="linear-header__text">
    <p class="linear-header__eyebrow" x-text="settings.brand.name"></p>
    <h1 class="linear-header__title">Linear integration</h1>
    <p class="linear-header__subtitle">Connect your Linear workspace and choose where new issues are created.</p>
  </div>
</header>`;
}

/** Status and error banners. The live regions exist up front so updates are announced. */
function flashTemplate(): string {
  return `
<div class="linear-flashes">
  <div role="status" aria-live="polite">
    <template x-if="flash.status">
      <div class="linear-flash linear-flash--status">
        <p class="linear-flash__message" x-text="flash.status"></p>
        <button type="button" class="linear-flash__dismiss" @click="dismissStatus()" aria-label="Dismiss message">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
    </template>
  </div>
  <div role="alert">
    <template x-if="flash.error">
      <div class="linear-flash linear-flash--error">
        <p class="linear-flash__message" x-text="flash.error"></p>
        <button type="button" class="linear-flash__dismiss" @click="dismissError()" aria-label="Dismiss error">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
    </template>
  </div>
</div>`;
}

function connectionTemplate(): string {
  return `
<section class="linear-card" aria-labelledby="linear-connection-title" x-data="${COMPONENT_NAMES.connection}($el)">
  <div class="linear-card__header">
    <div class="linear-card__heading">
      <h2 class="linear-card__title" id="linear-connection-title">Connection</h2>
      <p class="linear-card__description" x-text="connectionDescription"></p>
    </div>
    <span class="linear-badge" :class="'linear-badge--' + statusTone" x-text="statusLabel"></span>
  </div>

  <template x-if="connection">
    <dl class="linear-details">
      <div class="linear-details__row">
        <dt>Workspace</dt>
        <dd x-text="organizationLabel"></dd>
      </div>
      <div class="linear-details__row" x-show="accountLabel">
        <dt>Account</dt>
        <dd x-text="accountLabel"></dd>
      </div>
      <div class="linear-details__row" x-show="connection.lastSyncedAt">
        <dt>Last synced</dt>
        <dd><time :datetime="connection.lastSyncedAt" x-text="formatDate(connection.lastSyncedAt)"></time></dd>
      </div>
    </dl>
  </template>

  <template x-if="connection && connection.lastError">
    <p class="linear-notice linear-notice--error">
      <strong>Last error:</strong> <span x-text="connection.lastError"></span>
    </p>
  </template>

  <template x-if="showApiKeyForm">
    <form class="linear-form" method="post" :action="settings.urls.apiKey" @submit="submitApiKey($event)">
      <input type="hidden" name="_token" :value="settings.csrf">
      <div class="linear-field" :class="{ 'linear-field--invalid': apiKeyError }">
        <label class="linear-field__label" for="linear-api-key">Linear API key</label>
        <input
          class="linear-input"
          id="linear-api-key"
          name="api_key"
          type="password"
          autocomplete="off"
          spellcheck="false"
          required
          data-linear-ref="apiKey"
          x-model="apiKey"
          :aria-invalid="apiKeyError ? 'true' : 'false'"
          aria-describedby="linear-api-key-hint linear-api-key-error"
        >
        <p class="linear-field__hint" id="linear-api-key-hint">
          Create a personal API key in Linear under Settings, Account, Security &amp; access.
        </p>
        <p class="linear-field__error" id="linear-api-key-error" x-show="apiKeyError" x-text="apiKeyError"></p>
      </div>
      <div class="linear-actions">
        <button type="submit" class="linear-button linear-button--primary" :disabled="submitting" x-text="apiKeyLabel"></button>
      </div>
    </form>
  </template>

  <div class="linear-actions" x-show="showConnectLink || isConnected">
    <template x-if="showConnectLink">
      <a class="linear-button linear-button--primary" :href="settings.urls.connect" x-text="connectLabel"></a>
    </template>

    <template x-if="isConnected">
      <div class="linear-disconnect">
        <button
          type="button"
          class="linear-button linear-button--ghost"
          data-linear-ref="disconnect"
          x-show="!confirmingDisconnect"
          @click="askDisconnect()"
        >Disconnect</button>
        <form
          class="linear-confirm"
          method="post"
          :action="settings.urls.disconnect"
          x-show="confirmingDisconnect"
          @submit="submitDisconnect()"
        >
          <input type="hidden" name="_token" :value="settings.csrf">
          <input type="hidden" name="_method" value="DELETE">
          <p class="linear-confirm__text" id="linear-disconnect-confirm">
            Disconnect from Linear? Issues will stop being created until you reconnect.
          </p>
          <div class="linear-confirm__actions">
            <button
              type="submit"
              class="linear-button linear-button--danger"
              data-linear-ref="confirmDisconnect"
              aria-describedby="linear-disconnect-confirm"
              :disabled="submitting"
            >Yes, disconnect</button>
            <button type="button" class="linear-button linear-button--secondary" @click="cancelDisconnect()">Cancel</button>
          </div>
        </form>
      </div>
    </template>
  </div>
</section>`;
}

function optionsSkeletonTemplate(): string {
  return `
<div class="linear-skeleton-card" role="status" aria-live="polite" aria-busy="true">
  <p class="linear-skeleton-card__caption">
    <span class="linear-spinner" aria-hidden="true"></span>
    Loading team options&hellip;
  </p>
  <div class="linear-skeleton-card__grid" aria-hidden="true">
    <div class="linear-skeleton-card__field"><span class="linear-skeleton linear-skeleton--label"></span><span class="linear-skeleton linear-skeleton--input"></span></div>
    <div class="linear-skeleton-card__field"><span class="linear-skeleton linear-skeleton--label"></span><span class="linear-skeleton linear-skeleton--input"></span></div>
    <div class="linear-skeleton-card__field"><span class="linear-skeleton linear-skeleton--label"></span><span class="linear-skeleton linear-skeleton--input"></span></div>
  </div>
  <div class="linear-skeleton-card__chips" aria-hidden="true">
    <span class="linear-skeleton linear-skeleton--chip"></span>
    <span class="linear-skeleton linear-skeleton--chip"></span>
    <span class="linear-skeleton linear-skeleton--chip"></span>
    <span class="linear-skeleton linear-skeleton--chip"></span>
  </div>
</div>`;
}

function optionsReadyTemplate(): string {
  return `
<div class="linear-options__ready">
  <div class="linear-grid">
    <div class="linear-field" :class="{ 'linear-field--invalid': hasError('projectId') }">
      <label class="linear-field__label" for="linear-project">Project</label>
      <select class="linear-select" id="linear-project" name="projectId" x-model="draft.projectId" @change="clearError('projectId')" :aria-invalid="hasError('projectId')" aria-describedby="linear-project-error">
        <option value="">No project</option>
        <template x-for="project in options.projects" :key="project.id">
          <option :value="project.id" :selected="project.id === draft.projectId" x-text="project.name"></option>
        </template>
      </select>
      ${fieldError('projectId', 'linear-project-error')}
    </div>

    <div class="linear-field" :class="{ 'linear-field--invalid': hasError('stateId') }">
      <label class="linear-field__label" for="linear-state">Status</label>
      <select class="linear-select" id="linear-state" name="stateId" x-model="draft.stateId" @change="clearError('stateId')" :aria-invalid="hasError('stateId')" aria-describedby="linear-state-error">
        <option value="">Team default</option>
        <template x-for="state in options.states" :key="state.id">
          <option :value="state.id" :selected="state.id === draft.stateId" x-text="state.name"></option>
        </template>
      </select>
      ${fieldError('stateId', 'linear-state-error')}
    </div>

    <div class="linear-field" :class="{ 'linear-field--invalid': hasError('assigneeId') }">
      <label class="linear-field__label" for="linear-assignee">Assignee</label>
      <select class="linear-select" id="linear-assignee" name="assigneeId" x-model="draft.assigneeId" @change="clearError('assigneeId')" :aria-invalid="hasError('assigneeId')" aria-describedby="linear-assignee-error">
        <option value="">Unassigned</option>
        <template x-for="member in options.members" :key="member.id">
          <option :value="member.id" :selected="member.id === draft.assigneeId" x-text="member.name"></option>
        </template>
      </select>
      ${fieldError('assigneeId', 'linear-assignee-error')}
    </div>
  </div>

  <fieldset class="linear-fieldset" x-show="options.labels.length > 0" aria-describedby="linear-labels-error">
    <legend class="linear-fieldset__legend">Labels</legend>
    <div class="linear-chips">
      <template x-for="label in options.labels" :key="label.id">
        <label class="linear-chip">
          <input type="checkbox" class="linear-chip__input" :value="label.id" :checked="hasLabel(label.id)" @change="toggleLabel(label.id)">
          <span class="linear-chip__body">
            <span class="linear-chip__dot" :style="labelDotStyle(label)" aria-hidden="true"></span>
            <span class="linear-chip__name" x-text="label.name"></span>
          </span>
        </label>
      </template>
    </div>
    ${fieldError('labelIds', 'linear-labels-error')}
  </fieldset>
</div>`;
}

function destinationFormTemplate(): string {
  return `
<form class="linear-form" x-data="${COMPONENT_NAMES.destination}($el)" @submit.prevent="save()" aria-labelledby="linear-destination-title" novalidate>
  <div role="alert">
    <template x-if="formError">
      <p class="linear-notice linear-notice--error" x-text="formError"></p>
    </template>
  </div>

  <fieldset class="linear-fieldset">
    <legend class="linear-fieldset__legend">Sending mode</legend>
    <div class="linear-radio-group">
      <label class="linear-radio-card">
        <input type="radio" class="linear-radio-card__input" name="sendMode" value="automatic" x-model="draft.sendMode" @change="clearError('sendMode')">
        <span class="linear-radio-card__body">
          <span class="linear-radio-card__title">Automatic</span>
          <span class="linear-radio-card__text">Create a Linear issue as soon as a new request arrives.</span>
        </span>
      </label>
      <label class="linear-radio-card">
        <input type="radio" class="linear-radio-card__input" name="sendMode" value="manual" x-model="draft.sendMode" @change="clearError('sendMode')">
        <span class="linear-radio-card__body">
          <span class="linear-radio-card__title">Manual</span>
          <span class="linear-radio-card__text">Only create an issue when someone chooses to send a request.</span>
        </span>
      </label>
    </div>
    ${fieldError('sendMode', 'linear-send-mode-error')}
  </fieldset>

  <div class="linear-grid">
    <div class="linear-field" :class="{ 'linear-field--invalid': hasError('teamId') }">
      <label class="linear-field__label" for="linear-team">Team</label>
      <select
        class="linear-select"
        id="linear-team"
        name="teamId"
        data-linear-ref="team"
        x-model="draft.teamId"
        @change="onTeamChange()"
        :disabled="teamsState !== 'ready'"
        :aria-busy="teamsState === 'loading'"
        :aria-invalid="hasError('teamId')"
        aria-describedby="linear-team-error"
      >
        <option value="" x-text="teamPlaceholder"></option>
        <template x-for="team in teams" :key="team.id">
          <option :value="team.id" :selected="team.id === draft.teamId" x-text="team.name + ' (' + team.key + ')'"></option>
        </template>
      </select>
      ${fieldError('teamId', 'linear-team-error')}
      <template x-if="teamsState === 'error'">
        <div class="linear-inline-error" role="alert">
          <span x-text="teamsError"></span>
          <button type="button" class="linear-link-button" @click="loadTeams()">Try again</button>
        </div>
      </template>
    </div>

    <div class="linear-field" :class="{ 'linear-field--invalid': hasError('priority') }">
      <label class="linear-field__label" for="linear-priority">Priority</label>
      <select class="linear-select" id="linear-priority" name="priority" x-model.number="draft.priority" @change="clearError('priority')" :aria-invalid="hasError('priority')" aria-describedby="linear-priority-error">
        <template x-for="priority in priorities" :key="priority.value">
          <option :value="priority.value" :selected="priority.value === draft.priority" x-text="priority.label"></option>
        </template>
      </select>
      ${fieldError('priority', 'linear-priority-error')}
    </div>
  </div>

  <div class="linear-options" :aria-busy="optionsState === 'loading'">
    <template x-if="optionsState === 'idle'">
      <p class="linear-hint">Select a team to choose a project, status, assignee and labels.</p>
    </template>
    <template x-if="optionsState === 'loading'">${optionsSkeletonTemplate()}</template>
    <template x-if="optionsState === 'error'">
      <div class="linear-notice linear-notice--error" role="alert">
        <p x-text="optionsError"></p>
        <button type="button" class="linear-button linear-button--secondary" @click="loadOptions()">Try again</button>
      </div>
    </template>
    <template x-if="optionsState === 'ready'">${optionsReadyTemplate()}</template>
  </div>

  <div class="linear-actions">
    <button type="submit" class="linear-button linear-button--primary" :disabled="!canSave">
      <span class="linear-spinner" x-show="saving" aria-hidden="true"></span>
      <span x-text="saving ? 'Saving…' : 'Save destination'"></span>
    </button>

    <template x-if="hasSavedDestination">
      <div class="linear-remove">
        <button type="button" class="linear-button linear-button--ghost-danger" data-linear-ref="remove" x-show="!confirmingRemove" @click="askRemove()">Remove settings</button>
        <div class="linear-confirm" role="group" aria-labelledby="linear-remove-confirm" x-show="confirmingRemove">
          <p class="linear-confirm__text" id="linear-remove-confirm">Remove the saved destination? Issues will stop being created.</p>
          <div class="linear-confirm__actions">
            <button type="button" class="linear-button linear-button--danger" data-linear-ref="confirmRemove" :disabled="removing" @click="remove()" x-text="removing ? 'Removing…' : 'Yes, remove'"></button>
            <button type="button" class="linear-button linear-button--secondary" @click="cancelRemove()">Cancel</button>
          </div>
        </div>
      </div>
    </template>
  </div>
</form>`;
}

function destinationTemplate(): string {
  return `
<section class="linear-card" aria-labelledby="linear-destination-title">
  <div class="linear-card__header">
    <div class="linear-card__heading">
      <h2 class="linear-card__title" id="linear-destination-title">Issue destination</h2>
      <p class="linear-card__description" x-text="destinationSummary || 'Choose the Linear team and defaults for new issues.'"></p>
    </div>
  </div>

  <template x-if="!canConfigure">
    <p class="linear-hint">Connect your Linear workspace above to choose where issues are created.</p>
  </template>

  <template x-if="canConfigure">${destinationFormTemplate()}</template>
</section>`;
}

function failuresTemplate(): string {
  return `
<section class="linear-card" aria-labelledby="linear-failures-title" x-data="${COMPONENT_NAMES.failures}($el)" x-show="hasFailures">
  <div class="linear-card__header">
    <div class="linear-card__heading">
      <h2 class="linear-card__title" id="linear-failures-title" tabindex="-1" data-linear-ref="heading">Recent failures</h2>
      <p class="linear-card__description" x-text="failuresSummary"></p>
    </div>
  </div>

  <ul class="linear-failures">
    <template x-for="(failure, index) in failures" :key="failure.id">
      <li class="linear-failure">
        <div class="linear-failure__main">
          <p class="linear-failure__meta">
            <span class="linear-badge linear-badge--muted" x-text="kindLabel(failure.kind)"></span>
            <span x-text="attemptsLabel(failure.attempts)"></span>
            <time x-show="failure.occurredAt" :datetime="failure.occurredAt" x-text="formatDate(failure.occurredAt)"></time>
          </p>
          <p class="linear-failure__subject" x-text="failure.subject"></p>
          <p class="linear-failure__message" x-show="failure.message" x-text="failure.message"></p>
          <p class="linear-field__error" role="alert" x-show="errorFor(failure.id)" x-text="errorFor(failure.id)"></p>
        </div>
        <button type="button" class="linear-button linear-button--secondary" :aria-label="'Retry ' + failure.subject" @click="retry(failure, index)">Retry</button>
      </li>
    </template>
  </ul>
</section>`;
}

/** The complete application markup, rooted at the `linearApp` component. */
export function renderApp(): string {
  const listeners = [
    `x-on:${LINEAR_EVENTS.flash}="onFlash($event.detail)"`,
    `x-on:${LINEAR_EVENTS.destinationChanged}="onDestinationChanged($event.detail)"`,
    `x-on:${LINEAR_EVENTS.reconnectRequired}="onReconnectRequired($event.detail)"`,
    `x-on:${LINEAR_EVENTS.failureRemoved}="onFailureRemoved($event.detail)"`,
    `x-on:${LINEAR_EVENTS.failureRestored}="onFailureRestored($event.detail)"`,
  ].join('\n  ');

  return `
<div class="linear-shell" x-data="${COMPONENT_NAMES.app}()"
  ${listeners}>
${headerTemplate()}
${flashTemplate()}
${connectionTemplate()}
${destinationTemplate()}
${failuresTemplate()}
</div>`;
}

/**
 * Replaces the app with a friendly message when it cannot start. Built with DOM
 * APIs (not Alpine) because Alpine never starts in this case.
 */
export function renderFatalError(container: HTMLElement, detail: string): void {
  const doc = container.ownerDocument;
  const box = doc.createElement('div');
  box.className = 'linear-fatal';
  box.setAttribute('role', 'alert');

  const title = doc.createElement('h2');
  title.className = 'linear-fatal__title';
  title.textContent = 'The Linear settings page could not start';

  const message = doc.createElement('p');
  message.className = 'linear-fatal__message';
  message.textContent = 'Reload the page. If the problem persists, check that the package assets are up to date.';

  const technical = doc.createElement('p');
  technical.className = 'linear-fatal__detail';
  technical.textContent = detail;

  box.append(title, message, technical);
  container.replaceChildren(box);
}
