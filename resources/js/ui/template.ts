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

/** What `selectTemplate()` needs to render one custom select. */
export interface SelectSpec {
  /** The trigger's id. The field's `<label>` must have `for` equal to it and `id` equal to `${id}-label`. */
  id: string;
  /** Name of the hidden input that carries the value. */
  name: string;
  /** Alpine expressions, evaluated in the enclosing component's scope. */
  value: string;
  /** Expression returning the `SelectItem[]`. */
  items: string;
  /** Expression run when a different item is chosen; the chosen value is available as `value`. */
  onSelect: string;
  placeholder?: string;
  disabled?: string;
  invalid?: string;
  busy?: string;
  /** Space separated ids (usually the field's error element) for `aria-describedby`. */
  describedBy?: string;
  /** Value for `data-linear-ref`, so code can focus the trigger (see `focusRef`). */
  ref?: string;
}

const CHEVRON_ICON =
  '<svg class="linear-select__chevron" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"/></svg>';

const CHECK_ICON =
  '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20 6 9 17l-5-5"/></svg>';

/**
 * A shadcn-style select (see `components/select.ts`): a combobox button, a
 * popup listbox and a hidden input. Use it instead of a native `<select>`.
 */
export function selectTemplate(spec: SelectSpec): string {
  const { id } = spec;
  const config = [
    `id: '${id}'`,
    `value: () => ${spec.value}`,
    `items: () => ${spec.items}`,
    `onSelect: (value) => ${spec.onSelect}`,
    ...(spec.placeholder === undefined ? [] : [`placeholder: () => ${spec.placeholder}`]),
    ...(spec.disabled === undefined ? [] : [`disabled: () => ${spec.disabled}`]),
    ...(spec.invalid === undefined ? [] : [`invalid: () => ${spec.invalid}`]),
    ...(spec.busy === undefined ? [] : [`busy: () => ${spec.busy}`]),
  ].join(', ');
  const optional = [
    spec.describedBy === undefined ? '' : ` aria-describedby="${spec.describedBy}"`,
    spec.ref === undefined ? '' : ` data-linear-ref="${spec.ref}"`,
  ].join('');

  return `
<div class="linear-select" x-data="${COMPONENT_NAMES.select}($el, { ${config} })" @click.window="onOutside($event)" @linear-select-opened.window="onOutside($event)" @resize.window="onViewportChange()">
  <input type="hidden" name="${spec.name}" :value="selectedValue">
  <button
    type="button"
    class="linear-select__trigger"
    id="${id}"
    role="combobox"
    aria-haspopup="listbox"
    aria-controls="${id}-listbox"
    aria-labelledby="${id}-label"${optional}
    :aria-expanded="open"
    :aria-activedescendant="activeDescendant"
    :aria-invalid="isInvalid"
    :aria-busy="isBusy"
    :disabled="isDisabled"
    @click="toggle()"
    @keydown="onKeydown($event)"
  >
    <span class="linear-select__value" :class="{ 'linear-select__value--placeholder': isPlaceholder }" x-text="label"></span>
    ${CHEVRON_ICON}
  </button>
  <div class="linear-select__content" id="${id}-listbox" role="listbox" aria-labelledby="${id}-label" tabindex="-1" x-show="open" style="display: none" :data-side="placement" :style="contentStyle" @mousedown.prevent>
    <template x-for="(item, index) in entries" :key="String(item.value)">
      <div
        class="linear-select__item"
        role="option"
        :id="optionId(index)"
        :class="{ 'linear-select__item--active': index === activeIndex, 'linear-select__item--selected': isSelected(index), 'linear-select__item--disabled': item.disabled === true }"
        :aria-selected="isSelected(index)"
        :aria-disabled="item.disabled === true"
        @click="choose(index)"
        @pointermove="highlight(index)"
      >
        <span class="linear-select__item-label" x-text="item.label"></span>
        <span class="linear-select__check" x-show="isSelected(index)">${CHECK_ICON}</span>
      </div>
    </template>
    <div class="linear-select__empty" role="presentation" x-show="entries.length === 0">No options</div>
  </div>
</div>`;
}

function headerTemplate(): string {
  return `
<header class="linear-header">
  <template x-if="settings.brand.logo">
    <img class="linear-header__logo" :src="settings.brand.logo" alt="" width="80" height="80">
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
      <label class="linear-field__label" id="linear-project-label" for="linear-project">Project</label>
      ${selectTemplate({ id: 'linear-project', name: 'projectId', value: 'draft.projectId', items: 'projectItems', onSelect: "selectField('projectId', value)", invalid: "hasError('projectId')", describedBy: 'linear-project-error' })}
      ${fieldError('projectId', 'linear-project-error')}
    </div>

    <div class="linear-field" :class="{ 'linear-field--invalid': hasError('stateId') }">
      <label class="linear-field__label" id="linear-state-label" for="linear-state">Status</label>
      ${selectTemplate({ id: 'linear-state', name: 'stateId', value: 'draft.stateId', items: 'stateItems', onSelect: "selectField('stateId', value)", invalid: "hasError('stateId')", describedBy: 'linear-state-error' })}
      ${fieldError('stateId', 'linear-state-error')}
    </div>

    <div class="linear-field" :class="{ 'linear-field--invalid': hasError('assigneeId') }">
      <label class="linear-field__label" id="linear-assignee-label" for="linear-assignee">Assignee</label>
      ${selectTemplate({ id: 'linear-assignee', name: 'assigneeId', value: 'draft.assigneeId', items: 'assigneeItems', onSelect: "selectField('assigneeId', value)", invalid: "hasError('assigneeId')", describedBy: 'linear-assignee-error' })}
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
      <label class="linear-field__label" id="linear-team-label" for="linear-team">Team</label>
      ${selectTemplate({ id: 'linear-team', name: 'teamId', value: 'draft.teamId', items: 'teamItems', onSelect: "selectTeam(value)", placeholder: 'teamPlaceholder', disabled: "teamsState !== 'ready'", busy: "teamsState === 'loading'", invalid: "hasError('teamId')", ref: 'team', describedBy: 'linear-team-error' })}
      ${fieldError('teamId', 'linear-team-error')}
      <template x-if="teamsState === 'error'">
        <div class="linear-inline-error" role="alert">
          <span x-text="teamsError"></span>
          <button type="button" class="linear-link-button" @click="loadTeams()">Try again</button>
        </div>
      </template>
    </div>

    <div class="linear-field" :class="{ 'linear-field--invalid': hasError('priority') }">
      <label class="linear-field__label" id="linear-priority-label" for="linear-priority">Priority</label>
      ${selectTemplate({ id: 'linear-priority', name: 'priority', value: 'draft.priority', items: 'priorityItems', onSelect: "selectPriority(value)", invalid: "hasError('priority')", describedBy: 'linear-priority-error' })}
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
