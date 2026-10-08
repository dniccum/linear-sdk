/**
 * End-to-end tests: the real Alpine runtime renders the real templates into
 * jsdom, with only `fetch` mocked. They prove the templates, component state
 * and events work together (and guard against typos in Alpine expressions,
 * which unit tests on plain objects cannot see).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Settings } from '../types';
import {
  deferred,
  jsonResponse,
  makeConnection,
  makeFailure,
  makeMember,
  makeOptions,
  makeProject,
  makeSettings,
  makeState,
  makeTeam,
} from './fixtures';

type Handler = (url: string, init: RequestInit) => Response | Promise<Response>;

const TEAMS = [
  makeTeam({ id: 'team-1', name: 'Support', key: 'SUP' }),
  makeTeam({ id: 'team-2', name: 'Engineering', key: 'ENG' }),
];

/** Default backend: teams, per-team options, save and delete all succeed. */
const happyBackend: Handler = (url, init) => {
  if (url === '/linear/api/teams') {
    return jsonResponse({ teams: TEAMS });
  }

  if (/\/linear\/api\/teams\/[^/]+\/options$/.test(url)) {
    const team = url.split('/teams/')[1]?.split('/')[0] ?? '';
    return jsonResponse(
      makeOptions({ projects: [makeProject({ id: `project-${team}`, name: `Project ${team}` }), makeProject({ id: 'project-1', name: 'Website' })] }),
    );
  }

  if (url === '/linear/api/destination' && init.method === 'PUT') {
    const body = JSON.parse(typeof init.body === 'string' ? init.body : '{}') as Record<string, unknown>;
    return jsonResponse({ destination: { ...body, teamName: body['teamId'] === 'team-2' ? 'Engineering' : 'Support' } });
  }

  if (url === '/linear/api/destination' && init.method === 'DELETE') {
    return jsonResponse({ destination: null });
  }

  if (/\/linear\/api\/issues\/[^/]+\/retry$/.test(url)) {
    return jsonResponse({ ok: true });
  }

  return jsonResponse({ message: 'Not found' }, 404);
};

interface App {
  container: HTMLElement;
  fetchMock: ReturnType<typeof vi.fn<(url: string, init: RequestInit) => Promise<Response>>>;
  requests: () => Array<{ method: string; url: string; body: unknown }>;
}

let stopAlpine: (() => void) | undefined;

async function boot(settings: Settings, handler: Handler = happyBackend): Promise<App> {
  vi.resetModules();
  const fetchMock = vi.fn(async (url: string, init: RequestInit = {}) => handler(url, init));
  vi.stubGlobal('fetch', fetchMock);

  document.body.innerHTML = `<div data-linear-app></div><script type="application/json" id="linear-settings">${JSON.stringify(settings)}</script>`;
  const container = document.querySelector<HTMLElement>('[data-linear-app]');
  if (container === null) {
    throw new Error('missing app root');
  }

  const { mount } = await import('../mount');
  expect(mount()).toBe('mounted');
  stopAlpine = () => window.Alpine.stopObservingMutations();

  await vi.waitFor(() => {
    expect(container.querySelector('.linear-header__title')).not.toBeNull();
  });

  return {
    container,
    fetchMock,
    requests: () =>
      fetchMock.mock.calls.map(([url, init]) => ({
        method: init?.method ?? 'GET',
        url,
        body: typeof init?.body === 'string' ? (JSON.parse(init.body) as unknown) : undefined,
      })),
  };
}

function el<T extends Element>(app: App, selector: string): T {
  const element = app.container.querySelector<T>(selector);
  if (element === null) {
    throw new Error(`No element matches ${selector}`);
  }
  return element;
}

function visible(element: Element | null): boolean {
  return element instanceof HTMLElement && element.style.display !== 'none' && element.isConnected;
}

function text(app: App, selector: string): string {
  return (el(app, selector).textContent ?? '').replace(/\s+/g, ' ').trim();
}

function trigger(app: App, id: string): HTMLButtonElement {
  return el<HTMLButtonElement>(app, `button#${id}`);
}

/** The text a select's trigger currently shows. */
function shown(app: App, id: string): string {
  return text(app, `button#${id} .linear-select__value`);
}

/** The value a select submits (its hidden input). */
function submitted(app: App, name: string): string {
  return el<HTMLInputElement>(app, `input[type="hidden"][name="${name}"]`).value;
}

function optionLabels(app: App, id: string): string[] {
  return [...el(app, `#${id}-listbox`).querySelectorAll('.linear-select__item-label')].map((label) => label.textContent ?? '');
}

/** Opens a select with a click and chooses the option with `label`, the way a user would. */
async function pick(app: App, id: string, label: string): Promise<void> {
  trigger(app, id).click();
  await vi.waitFor(() => {
    expect(trigger(app, id).getAttribute('aria-expanded')).toBe('true');
  });

  const option = [...el(app, `#${id}-listbox`).querySelectorAll<HTMLElement>('[role="option"]')].find(
    (candidate) => candidate.querySelector('.linear-select__item-label')?.textContent === label,
  );
  if (option === undefined) {
    throw new Error(`No option "${label}" in #${id}`);
  }
  option.click();

  await vi.waitFor(() => {
    expect(trigger(app, id).getAttribute('aria-expanded')).toBe('false');
  });
}

function submit(form: HTMLFormElement): Event {
  const event = new Event('submit', { bubbles: true, cancelable: true });
  form.dispatchEvent(event);
  return event;
}

async function settled(app: App): Promise<void> {
  await vi.waitFor(() => {
    expect(app.container.querySelector('.linear-skeleton-card')).toBeNull();
    expect(app.container.querySelector('#linear-team')?.hasAttribute('disabled')).toBe(false);
  });
}

beforeEach(() => {
  vi.spyOn(console, 'error').mockImplementation(() => undefined);
  vi.spyOn(console, 'warn').mockImplementation(() => undefined);
});

afterEach(() => {
  stopAlpine?.();
  stopAlpine = undefined;
});

describe('disconnected', () => {
  const disconnected = makeSettings({ connection: null, destination: null, failures: [] });

  it('offers an OAuth connect link and keeps the destination form out of the way', async () => {
    const app = await boot(disconnected);

    expect(text(app, '.linear-badge')).toBe('Not connected');
    const link = el<HTMLAnchorElement>(app, 'a.linear-button--primary');
    expect(link.getAttribute('href')).toBe('/linear/connect');
    expect(link.textContent).toBe('Connect with Linear');
    expect(app.container.querySelector('form[action="/linear/api-key"]')).toBeNull();
    expect(text(app, '#linear-destination-title + p')).toBe('Choose the Linear team and defaults for new issues.');
    expect(app.container.querySelector('#linear-team')).toBeNull();
    expect(app.container.textContent).toContain('Connect your Linear workspace above');
    expect(visible(app.container.querySelector('.linear-failures')?.closest('section') ?? null)).toBe(false);
    expect(app.fetchMock).not.toHaveBeenCalled();
  });

  it('shows the brand name and logo', async () => {
    const app = await boot({
      ...disconnected,
      brand: { name: 'Acme Support', logo: 'https://example.com/logo.svg', color: '#5E6AD2' },
    });

    expect(text(app, '.linear-header__eyebrow')).toBe('Acme Support');
    const logo = el<HTMLImageElement>(app, '.linear-header__logo');
    expect(logo.getAttribute('src')).toBe('https://example.com/logo.svg');
    expect(logo.getAttribute('alt')).toBe('');
  });

  it('omits the logo when none is configured', async () => {
    const app = await boot(disconnected);

    expect(app.container.querySelector('.linear-header__logo')).toBeNull();
  });

  it('explains when Linear is not configured and offers no way to connect', async () => {
    const app = await boot({ ...disconnected, configured: false });

    expect(text(app, '.linear-badge')).toBe('Not configured');
    expect(app.container.textContent).toContain('Add your Linear credentials');
    expect(app.container.querySelector('a.linear-button')).toBeNull();
    expect(app.container.querySelector('form[action="/linear/api-key"]')).toBeNull();
  });
});

describe('API key mode', () => {
  const apiKey = makeSettings({ authMode: 'api_key', connection: null, destination: null, failures: [] });

  it('renders a CSRF-protected form that posts to the API key URL', async () => {
    const app = await boot(apiKey);

    const form = el<HTMLFormElement>(app, 'form[action="/linear/api-key"]');
    expect(form.getAttribute('method')).toBe('post');
    expect(el<HTMLInputElement>(app, 'input[name="_token"]').value).toBe('csrf-token');
    const input = el<HTMLInputElement>(app, '#linear-api-key');
    expect(input.type).toBe('password');
    expect(input.name).toBe('api_key');
    expect(input.autocomplete).toBe('off');
    expect(text(app, 'form[action="/linear/api-key"] button[type="submit"]')).toBe('Connect');
    expect(app.container.querySelector('a.linear-button')).toBeNull();
  });

  it('blocks a blank key, then lets a real key submit and locks the button', async () => {
    const app = await boot(apiKey);
    const form = el<HTMLFormElement>(app, 'form[action="/linear/api-key"]');
    const input = el<HTMLInputElement>(app, '#linear-api-key');

    expect(submit(form).defaultPrevented).toBe(true);
    await vi.waitFor(() => {
      expect(visible(el(app, '#linear-api-key-error'))).toBe(true);
    });
    expect(text(app, '#linear-api-key-error')).toBe('Enter your Linear API key.');
    expect(input.getAttribute('aria-invalid')).toBe('true');

    input.value = 'lin_api_secret';
    input.dispatchEvent(new Event('input', { bubbles: true }));

    expect(submit(form).defaultPrevented).toBe(false);
    await vi.waitFor(() => {
      expect(el<HTMLButtonElement>(app, 'form[action="/linear/api-key"] button[type="submit"]').disabled).toBe(true);
    });
    expect(visible(el(app, '#linear-api-key-error'))).toBe(false);
  });

  it('replaces the key when the connection needs reconnecting', async () => {
    const app = await boot({ ...apiKey, connection: makeConnection({ status: 'needs_reconnect', lastError: 'Invalid key' }) });

    expect(text(app, '.linear-badge')).toBe('Needs reconnect');
    expect(text(app, 'form[action="/linear/api-key"] button[type="submit"]')).toBe('Replace API key');
    expect(app.container.textContent).toContain('Last error: Invalid key');
  });
});

describe('connected', () => {
  it('shows the workspace, account and last sync', async () => {
    const app = await boot(makeSettings({ failures: [] }));

    expect(text(app, '.linear-badge')).toBe('Connected');
    expect(app.container.textContent).toContain('Acme Inc');
    expect(app.container.textContent).toContain('Ada Lovelace (ada@example.com)');
    expect(el<HTMLTimeElement>(app, 'time').getAttribute('datetime')).toBe('2026-01-15T10:30:00Z');
  });

  it('hides rows with no data', async () => {
    const app = await boot(
      makeSettings({
        connection: makeConnection({ userName: null, userEmail: null, lastSyncedAt: null }),
        failures: [],
      }),
    );

    const rows = [...app.container.querySelectorAll<HTMLElement>('.linear-details__row')];
    expect(rows.map((row) => visible(row))).toEqual([true, false, false]);
  });

  it('confirms before disconnecting with a CSRF-protected DELETE form', async () => {
    const app = await boot(makeSettings({ failures: [], destination: null }));
    const trigger = el<HTMLButtonElement>(app, '[data-linear-ref="disconnect"]');
    const confirmation = el<HTMLFormElement>(app, 'form.linear-confirm');

    expect(visible(trigger)).toBe(true);
    expect(visible(confirmation)).toBe(false);
    expect(confirmation.getAttribute('action')).toBe('/linear');
    expect(confirmation.getAttribute('method')).toBe('post');
    expect(confirmation.querySelector<HTMLInputElement>('input[name="_token"]')?.value).toBe('csrf-token');
    expect(confirmation.querySelector<HTMLInputElement>('input[name="_method"]')?.value).toBe('DELETE');

    trigger.click();
    await vi.waitFor(() => {
      expect(visible(confirmation)).toBe(true);
    });
    expect(visible(trigger)).toBe(false);
    expect(document.activeElement).toBe(el(app, '[data-linear-ref="confirmDisconnect"]'));

    confirmation.querySelector<HTMLButtonElement>('button[type="button"]')?.click();
    await vi.waitFor(() => {
      expect(visible(confirmation)).toBe(false);
    });
    expect(visible(trigger)).toBe(true);
    expect(document.activeElement).toBe(trigger);
  });

  it('disables the confirm button after submitting the disconnect form', async () => {
    const app = await boot(makeSettings({ failures: [], destination: null }));
    el<HTMLButtonElement>(app, '[data-linear-ref="disconnect"]').click();

    submit(el<HTMLFormElement>(app, 'form.linear-confirm'));

    await vi.waitFor(() => {
      expect(el<HTMLButtonElement>(app, '[data-linear-ref="confirmDisconnect"]').disabled).toBe(true);
    });
  });
});

describe('destination form', () => {
  it('loads teams and options and selects the saved values', async () => {
    const app = await boot(makeSettings({ failures: [] }));
    await settled(app);

    expect(app.requests().map((request) => `${request.method} ${request.url}`)).toEqual([
      'GET /linear/api/teams',
      'GET /linear/api/teams/team-1/options',
    ]);
    expect(['linear-team', 'linear-project', 'linear-state', 'linear-assignee', 'linear-priority'].map((id) => shown(app, id))).toEqual([
      'Support (SUP)',
      'Website',
      'Triage',
      'Grace Hopper',
      'High',
    ]);
    expect(['teamId', 'projectId', 'stateId', 'assigneeId', 'priority'].map((name) => submitted(app, name))).toEqual([
      'team-1',
      'project-1',
      'state-1',
      'member-1',
      '2',
    ]);
    expect(el<HTMLInputElement>(app, 'input[name="sendMode"][value="automatic"]').checked).toBe(true);

    const labels = [...app.container.querySelectorAll<HTMLInputElement>('.linear-chip__input')];
    expect(labels.map((input) => [input.value, input.checked])).toEqual([
      ['label-1', true],
      ['label-2', false],
    ]);
    expect(text(app, '#linear-destination-title + p')).toBe('New issues are sent to Support automatically.');
  });

  it('lists priorities as No priority, Urgent, High, Medium, Low', async () => {
    const app = await boot(makeSettings({ failures: [] }));
    await settled(app);

    expect(optionLabels(app, 'linear-priority')).toEqual(['No priority', 'Urgent', 'High', 'Medium', 'Low']);
  });

  it('shows team names with their keys and colour dots for labels', async () => {
    const app = await boot(makeSettings({ failures: [] }));
    await settled(app);

    expect(optionLabels(app, 'linear-team')).toEqual(['Select a team', 'Support (SUP)', 'Engineering (ENG)']);

    const dots = [...app.container.querySelectorAll<HTMLElement>('.linear-chip__dot')];
    expect(dots[0]?.style.getPropertyValue('--linear-dot')).toBe('#eb5757');
    expect(dots[1]?.style.getPropertyValue('--linear-dot')).toBe('');
  });

  it('shows a loading card while options load, then the selects', async () => {
    const options = deferred<Response>();
    const app = await boot(makeSettings({ failures: [] }), (url, init) =>
      /\/options$/.test(url) ? options.promise : happyBackend(url, init),
    );

    await vi.waitFor(() => {
      expect(app.container.querySelector('.linear-skeleton-card')).not.toBeNull();
    });
    const skeleton = el(app, '.linear-skeleton-card');
    expect(skeleton.getAttribute('role')).toBe('status');
    expect(skeleton.getAttribute('aria-busy')).toBe('true');
    expect(skeleton.textContent).toContain('Loading team options');
    expect(el(app, '.linear-options').getAttribute('aria-busy')).toBe('true');
    expect(app.container.querySelector('#linear-project')).toBeNull();
    expect(el<HTMLButtonElement>(app, 'button[type="submit"].linear-button--primary').disabled).toBe(true);

    options.resolve(jsonResponse(makeOptions()));

    await vi.waitFor(() => {
      expect(app.container.querySelector('#linear-project')).not.toBeNull();
    });
    expect(app.container.querySelector('.linear-skeleton-card')).toBeNull();
    expect(el(app, '.linear-options').hasAttribute('aria-busy')).toBe(false);
  });

  it('shows an inline error with a retry when options fail to load', async () => {
    let attempts = 0;
    const app = await boot(makeSettings({ failures: [] }), (url, init) => {
      if (/\/options$/.test(url) && attempts++ === 0) {
        return jsonResponse({ message: 'Linear is unavailable right now.' }, 503);
      }
      return happyBackend(url, init);
    });

    await vi.waitFor(() => {
      expect(app.container.querySelector('.linear-options [role="alert"]')).not.toBeNull();
    });
    expect(text(app, '.linear-options [role="alert"]')).toContain('Linear is unavailable right now.');
    expect(app.container.querySelector('#linear-project')).toBeNull();

    el<HTMLButtonElement>(app, '.linear-options [role="alert"] button').click();

    await vi.waitFor(() => {
      expect(app.container.querySelector('#linear-project')).not.toBeNull();
    });
    expect(app.container.querySelector('.linear-options [role="alert"]')).toBeNull();
  });

  it('shows an inline error with a retry when teams fail to load', async () => {
    let attempts = 0;
    const app = await boot(makeSettings({ failures: [], destination: null }), (url, init) => {
      if (url === '/linear/api/teams' && attempts++ === 0) {
        return jsonResponse({ message: 'Teams are unavailable.' }, 503);
      }
      return happyBackend(url, init);
    });

    await vi.waitFor(() => {
      expect(text(app, '.linear-inline-error')).toContain('Teams are unavailable.');
    });
    expect(trigger(app, 'linear-team').disabled).toBe(true);
    expect(shown(app, 'linear-team')).toBe('Teams unavailable');

    el<HTMLButtonElement>(app, '.linear-inline-error button').click();

    await vi.waitFor(() => {
      expect(trigger(app, 'linear-team').disabled).toBe(false);
    });
    expect(app.container.querySelector('.linear-inline-error')).toBeNull();
  });

  it('loads new options and resets team-specific fields when the team changes', async () => {
    const app = await boot(makeSettings({ failures: [] }));
    await settled(app);

    await pick(app, 'linear-team', 'Engineering (ENG)');

    await vi.waitFor(() => {
      expect(app.requests().map((request) => request.url)).toContain('/linear/api/teams/team-2/options');
    });
    await settled(app);
    expect(shown(app, 'linear-team')).toBe('Engineering (ENG)');
    expect([shown(app, 'linear-project'), shown(app, 'linear-state'), shown(app, 'linear-assignee')]).toEqual([
      'No project',
      'Team default',
      'Unassigned',
    ]);
    expect(['projectId', 'stateId', 'assigneeId'].map((name) => submitted(app, name))).toEqual(['', '', '']);
    expect(shown(app, 'linear-priority')).toBe('High');
    expect([...app.container.querySelectorAll<HTMLInputElement>('.linear-chip__input')].some((input) => input.checked)).toBe(false);
    expect(optionLabels(app, 'linear-project')).toEqual(['No project', 'Project team-2', 'Website']);
  });

  it('returns to the idle hint when the team is cleared', async () => {
    const app = await boot(makeSettings({ failures: [] }));
    await settled(app);

    await pick(app, 'linear-team', 'Select a team');

    await vi.waitFor(() => {
      expect(app.container.querySelector('#linear-project')).toBeNull();
    });
    expect(text(app, '.linear-options .linear-hint')).toContain('Select a team');
    expect(el<HTMLButtonElement>(app, 'button[type="submit"].linear-button--primary').disabled).toBe(true);
  });

  it('saves the destination and reports it', async () => {
    const app = await boot(makeSettings({ failures: [] }));
    await settled(app);

    await pick(app, 'linear-priority', 'Medium');
    el<HTMLInputElement>(app, 'input[name="sendMode"][value="manual"]').click();
    el<HTMLInputElement>(app, '.linear-chip__input[value="label-2"]').click();
    await pick(app, 'linear-assignee', 'Unassigned');
    submit(el<HTMLFormElement>(app, 'form.linear-form[aria-labelledby="linear-destination-title"]'));

    await vi.waitFor(() => {
      expect(app.requests().some((request) => request.method === 'PUT')).toBe(true);
    });
    const put = app.requests().find((request) => request.method === 'PUT');
    expect(put?.url).toBe('/linear/api/destination');
    expect(put?.body).toEqual({
      sendMode: 'manual',
      teamId: 'team-1',
      projectId: 'project-1',
      stateId: 'state-1',
      labelIds: ['label-1', 'label-2'],
      priority: 3,
      assigneeId: null,
    });
    const headers = app.fetchMock.mock.calls.find(([, init]) => init?.method === 'PUT')?.[1]?.headers;
    expect(headers).toMatchObject({ 'X-CSRF-TOKEN': 'csrf-token', 'Content-Type': 'application/json' });

    await vi.waitFor(() => {
      expect(text(app, '.linear-flash--status')).toBe('Destination saved. ×');
    });
    expect(text(app, '#linear-destination-title + p')).toBe('Issues are sent to Support when you choose to send them.');
    expect(el<HTMLButtonElement>(app, 'button[type="submit"].linear-button--primary').disabled).toBe(false);
  });

  it('shows field-level validation errors from a 422', async () => {
    const app = await boot(makeSettings({ failures: [] }), (url, init) =>
      init.method === 'PUT'
        ? jsonResponse(
            { message: 'The given data was invalid.', errors: { priority: ['Pick a valid priority.'], 'labelIds.0': ['Unknown label.'] } },
            422,
          )
        : happyBackend(url, init),
    );
    await settled(app);

    submit(el<HTMLFormElement>(app, 'form.linear-form[aria-labelledby="linear-destination-title"]'));

    await vi.waitFor(() => {
      expect(visible(el(app, '#linear-priority-error'))).toBe(true);
    });
    expect(text(app, '#linear-priority-error')).toBe('Pick a valid priority.');
    expect(text(app, '#linear-labels-error')).toBe('Unknown label.');
    expect(el(app, '#linear-priority').getAttribute('aria-invalid')).toBe('true');
    expect(text(app, '.linear-form > [role="alert"]')).toBe('Check the highlighted fields and try again.');
    expect(app.container.querySelector('.linear-flash--status')).toBeNull();

    // Editing a field clears its own error only.
    await pick(app, 'linear-priority', 'Urgent');
    await vi.waitFor(() => {
      expect(visible(el(app, '#linear-priority-error'))).toBe(false);
    });
    expect(visible(el(app, '#linear-labels-error'))).toBe(true);
    expect(el(app, '#linear-priority').hasAttribute('aria-invalid')).toBe(false);
  });

  it('shows an outage message when saving fails', async () => {
    const app = await boot(makeSettings({ failures: [] }), (url, init) =>
      init.method === 'PUT' ? jsonResponse({ message: 'Linear is down.' }, 503) : happyBackend(url, init),
    );
    await settled(app);

    submit(el<HTMLFormElement>(app, 'form.linear-form[aria-labelledby="linear-destination-title"]'));

    await vi.waitFor(() => {
      expect(text(app, '.linear-form > [role="alert"]')).toBe('Linear is down.');
    });
  });

  it('removes the saved destination after confirmation', async () => {
    const app = await boot(makeSettings({ failures: [] }));
    await settled(app);
    const remove = el<HTMLButtonElement>(app, '[data-linear-ref="remove"]');

    remove.click();
    await vi.waitFor(() => {
      expect(visible(remove)).toBe(false);
    });
    expect(document.activeElement).toBe(el(app, '[data-linear-ref="confirmRemove"]'));

    el<HTMLButtonElement>(app, '[data-linear-ref="confirmRemove"]').click();

    await vi.waitFor(() => {
      expect(app.requests().some((request) => request.method === 'DELETE')).toBe(true);
    });
    await vi.waitFor(() => {
      expect(text(app, '.linear-flash--status')).toBe('Destination settings removed. ×');
    });
    expect(submitted(app, 'teamId')).toBe('');
    expect(shown(app, 'linear-team')).toBe('Select a team');
    expect(app.container.querySelector('[data-linear-ref="remove"]')).toBeNull();
    expect(text(app, '#linear-destination-title + p')).toBe('Choose the Linear team and defaults for new issues.');
    expect(text(app, '.linear-options .linear-hint')).toContain('Select a team');
    expect(document.activeElement).toBe(el(app, '#linear-team'));
  });

  it('can cancel removing the destination', async () => {
    const app = await boot(makeSettings({ failures: [] }));
    await settled(app);
    const remove = el<HTMLButtonElement>(app, '[data-linear-ref="remove"]');

    remove.click();
    await vi.waitFor(() => {
      expect(visible(remove)).toBe(false);
    });
    el<HTMLButtonElement>(app, '.linear-remove .linear-button--secondary').click();

    await vi.waitFor(() => {
      expect(visible(remove)).toBe(true);
    });
    expect(document.activeElement).toBe(remove);
    expect(app.requests().some((request) => request.method === 'DELETE')).toBe(false);
  });

  it('offers no remove button when nothing is saved', async () => {
    const app = await boot(makeSettings({ failures: [], destination: null }));
    await settled(app);

    expect(app.container.querySelector('[data-linear-ref="remove"]')).toBeNull();
    expect(app.requests().map((request) => request.url)).toEqual(['/linear/api/teams']);
  });
});

describe('reconnect', () => {
  it('switches to the reconnect state when Linear rejects the token', async () => {
    const app = await boot(makeSettings({ failures: [] }), (url, init) =>
      url === '/linear/api/teams'
        ? jsonResponse({ message: 'Linear needs to be reconnected.', reconnect: true }, 409)
        : happyBackend(url, init),
    );

    await vi.waitFor(() => {
      expect(text(app, '.linear-badge')).toBe('Needs reconnect');
    });
    expect(text(app, '.linear-flash--error')).toBe('Linear needs to be reconnected. ×');
    expect(text(app, '.linear-notice--error')).toContain('Linear needs to be reconnected.');
    expect(el<HTMLAnchorElement>(app, 'a.linear-button--primary').textContent).toBe('Reconnect to Linear');
    await vi.waitFor(() => {
      expect(app.container.querySelector('#linear-team')).toBeNull();
    });
    expect(app.container.textContent).toContain('Connect your Linear workspace above');
  });

  it('starts in the reconnect state when the connection is stale', async () => {
    const app = await boot(
      makeSettings({ failures: [], connection: makeConnection({ status: 'needs_reconnect', lastError: 'Token revoked' }) }),
    );

    expect(text(app, '.linear-badge')).toBe('Needs reconnect');
    expect(el<HTMLAnchorElement>(app, 'a.linear-button--primary').getAttribute('href')).toBe('/linear/connect');
    expect(app.container.querySelector('#linear-team')).toBeNull();
    expect(app.fetchMock).not.toHaveBeenCalled();
  });
});

describe('failures', () => {
  const failures = [
    makeFailure({ id: 'f1', subject: 'Checkout is broken', linkId: 'L1', attempts: 3, message: 'HTTP 500' }),
    makeFailure({ id: 'f2', subject: 'Re: refund', linkId: 'L2', attempts: 1, kind: 'comment', message: null, occurredAt: null }),
  ];

  function items(app: App): string[] {
    return [...app.container.querySelectorAll('.linear-failure__subject')].map((subject) => subject.textContent ?? '');
  }

  it('lists recent failures with their details', async () => {
    const app = await boot(makeSettings({ destination: null, failures }));

    expect(visible(el(app, '#linear-failures-title').closest('section'))).toBe(true);
    expect(text(app, '#linear-failures-title + p')).toBe('2 deliveries failed. Retry once the underlying problem is fixed.');
    expect(items(app)).toEqual(['Checkout is broken', 'Re: refund']);
    const [first, second] = [...app.container.querySelectorAll<HTMLElement>('.linear-failure')];
    expect(first?.textContent).toContain('Issue');
    expect(first?.textContent).toContain('3 attempts');
    expect(first?.textContent).toContain('HTTP 500');
    expect(second?.textContent).toContain('Comment');
    expect(second?.textContent).toContain('1 attempt');
    expect(first?.querySelector('button')?.getAttribute('aria-label')).toBe('Retry Checkout is broken');
    expect(visible(second?.querySelector('time') ?? null)).toBe(false);
    expect(visible(second?.querySelector('.linear-failure__message') ?? null)).toBe(false);
  });

  it('hides the failures card when there are none', async () => {
    const app = await boot(makeSettings({ destination: null, failures: [] }));

    expect(visible(el(app, '#linear-failures-title').closest('section'))).toBe(false);
  });

  it('removes a failure optimistically, calls the retry URL and announces it', async () => {
    const retry = deferred<Response>();
    const app = await boot(makeSettings({ destination: null, failures }), (url, init) =>
      url.endsWith('/L1/retry') ? retry.promise : happyBackend(url, init),
    );

    el<HTMLButtonElement>(app, '.linear-failure button').click();

    await vi.waitFor(() => {
      expect(items(app)).toEqual(['Re: refund']);
    });
    expect(app.requests().filter((request) => request.method === 'POST')).toEqual([
      { method: 'POST', url: '/linear/api/issues/L1/retry', body: undefined },
    ]);
    expect(document.activeElement).toBe(el(app, '#linear-failures-title'));

    retry.resolve(jsonResponse({ ok: true }));
    await vi.waitFor(() => {
      expect(text(app, '.linear-flash--status')).toBe('Retry requested for "Checkout is broken". ×');
    });
    expect(text(app, '#linear-failures-title + p')).toBe('1 delivery failed. Retry once the underlying problem is fixed.');
  });

  it('puts the failure back with an error when the retry fails', async () => {
    const app = await boot(makeSettings({ destination: null, failures }), (url, init) =>
      url.endsWith('/L2/retry') ? jsonResponse({ message: 'Linear is down.' }, 503) : happyBackend(url, init),
    );

    el<HTMLButtonElement>(app, '.linear-failure:nth-of-type(2) button').click();

    await vi.waitFor(() => {
      expect(text(app, '.linear-failure:nth-of-type(2) .linear-field__error')).toBe('Linear is down.');
    });
    expect(items(app)).toEqual(['Checkout is broken', 'Re: refund']);
  });

  it('sends the browser to the login page when the session has expired, and restores the failure', async () => {
    const assign = vi.fn();
    vi.stubGlobal('location', { assign });
    const app = await boot(makeSettings({ destination: null, failures }), (url, init) =>
      url.endsWith('/L2/retry') ? jsonResponse({ message: 'Unauthenticated.' }, 401) : happyBackend(url, init),
    );

    el<HTMLButtonElement>(app, '.linear-failure:nth-of-type(2) button').click();

    await vi.waitFor(() => {
      expect(assign).toHaveBeenCalledExactlyOnceWith('/login');
    });
    await vi.waitFor(() => {
      expect(text(app, '.linear-failure:nth-of-type(2) .linear-field__error')).toBe('Your session has expired. Redirecting to sign in…');
    });
    expect(items(app)).toEqual(['Checkout is broken', 'Re: refund']);
  });

  it('explains an expired session when there is no login page to go to', async () => {
    const assign = vi.fn();
    vi.stubGlobal('location', { assign });
    const settings = makeSettings({ destination: null, failures });
    const app = await boot({ ...settings, urls: { ...settings.urls, login: '' } }, (url, init) =>
      url.endsWith('/L2/retry') ? jsonResponse({}, 419) : happyBackend(url, init),
    );

    el<HTMLButtonElement>(app, '.linear-failure:nth-of-type(2) button').click();

    await vi.waitFor(() => {
      expect(text(app, '.linear-failure:nth-of-type(2) .linear-field__error')).toBe('Your session has expired. Please sign in again.');
    });
    expect(assign).not.toHaveBeenCalled();
    expect(items(app)).toEqual(['Checkout is broken', 'Re: refund']);
  });

  it('clears the error and removes the item when a second attempt succeeds', async () => {
    let attempts = 0;
    const app = await boot(makeSettings({ destination: null, failures: [makeFailure({ id: 'f1', linkId: 'L1' })] }), (url, init) =>
      url.endsWith('/L1/retry') && attempts++ === 0 ? jsonResponse({ message: 'Nope.' }, 500) : happyBackend(url, init),
    );

    el<HTMLButtonElement>(app, '.linear-failure button').click();
    await vi.waitFor(() => {
      expect(visible(el(app, '.linear-failure .linear-field__error'))).toBe(true);
    });

    el<HTMLButtonElement>(app, '.linear-failure button').click();
    await vi.waitFor(() => {
      expect(items(app)).toEqual([]);
    });
    expect(visible(el(app, '#linear-failures-title').closest('section'))).toBe(false);
  });

  it('asks to reconnect when a retry is rejected with a reconnect response', async () => {
    const app = await boot(makeSettings({ destination: null, failures }), (url, init) =>
      url.endsWith('/L1/retry')
        ? jsonResponse({ message: 'Reconnect Linear.', reconnect: true }, 409)
        : happyBackend(url, init),
    );

    el<HTMLButtonElement>(app, '.linear-failure button').click();

    await vi.waitFor(() => {
      expect(text(app, '.linear-badge')).toBe('Needs reconnect');
    });
    expect(items(app)).toEqual(['Checkout is broken', 'Re: refund']);
  });
});

describe('flash banners', () => {
  it('renders server-provided status and error messages in live regions', async () => {
    const app = await boot(
      makeSettings({ connection: null, destination: null, failures: [], flash: { status: 'Connected to Linear.', error: 'Something odd.' } }),
    );

    expect(text(app, '.linear-flash--status')).toBe('Connected to Linear. ×');
    expect(text(app, '.linear-flash--error')).toBe('Something odd. ×');
    expect(el(app, '.linear-flash--status').closest('[role="status"]')).not.toBeNull();
    expect(el(app, '.linear-flash--error').closest('[role="alert"]')).not.toBeNull();
  });

  it('dismisses each banner independently', async () => {
    const app = await boot(
      makeSettings({ connection: null, destination: null, failures: [], flash: { status: 'Hello.', error: 'Oops.' } }),
    );

    el<HTMLButtonElement>(app, '.linear-flash--status button').click();
    await vi.waitFor(() => {
      expect(app.container.querySelector('.linear-flash--status')).toBeNull();
    });
    expect(app.container.querySelector('.linear-flash--error')).not.toBeNull();

    el<HTMLButtonElement>(app, '.linear-flash--error button').click();
    await vi.waitFor(() => {
      expect(app.container.querySelector('.linear-flash--error')).toBeNull();
    });
  });
});

describe('custom select', () => {
  function key(app: App, id: string, name: string, init: KeyboardEventInit = {}): KeyboardEvent {
    const event = new KeyboardEvent('keydown', { key: name, bubbles: true, cancelable: true, ...init });
    trigger(app, id).dispatchEvent(event);
    return event;
  }

  function popup(app: App, id: string): HTMLElement {
    return el(app, `#${id}-listbox`);
  }

  function isOpen(app: App, id: string): boolean {
    return visible(popup(app, id));
  }

  async function opened(app: App, id: string): Promise<void> {
    await vi.waitFor(() => {
      expect(trigger(app, id).getAttribute('aria-expanded')).toBe('true');
      expect(isOpen(app, id)).toBe(true);
    });
  }

  async function closed(app: App, id: string): Promise<void> {
    await vi.waitFor(() => {
      expect(trigger(app, id).getAttribute('aria-expanded')).toBe('false');
      expect(isOpen(app, id)).toBe(false);
    });
  }

  function activeLabel(app: App): string {
    return text(app, '.linear-select__item--active .linear-select__item-label');
  }

  async function ready(settings: Settings = makeSettings({ failures: [] })): Promise<App> {
    const app = await boot(settings);
    await settled(app);
    return app;
  }

  it('wires the combobox, listbox and options with the ARIA select-only pattern', async () => {
    const app = await ready();
    const field = trigger(app, 'linear-project');

    expect(field.getAttribute('type')).toBe('button');
    expect(field.getAttribute('role')).toBe('combobox');
    expect(field.getAttribute('aria-haspopup')).toBe('listbox');
    expect(field.getAttribute('aria-controls')).toBe('linear-project-listbox');
    expect(field.getAttribute('aria-labelledby')).toBe('linear-project-label');
    expect(field.getAttribute('aria-describedby')).toBe('linear-project-error');
    expect(field.getAttribute('aria-expanded')).toBe('false');
    expect(field.hasAttribute('aria-activedescendant')).toBe(false);
    expect(popup(app, 'linear-project').getAttribute('role')).toBe('listbox');
    expect(isOpen(app, 'linear-project')).toBe(false);

    const options = [...popup(app, 'linear-project').querySelectorAll<HTMLElement>('[role="option"]')];
    expect(options.map((option) => option.id)).toEqual([
      'linear-project-option-0',
      'linear-project-option-1',
      'linear-project-option-2',
    ]);
    expect(options.map((option) => option.getAttribute('aria-selected'))).toEqual(['false', 'false', 'true']);
    expect(options.map((option) => visible(option.querySelector('.linear-select__check')))).toEqual([false, false, true]);
  });

  it('opens on click and marks the selected option as the active descendant', async () => {
    const app = await ready();

    trigger(app, 'linear-state').click();
    await opened(app, 'linear-state');

    expect(trigger(app, 'linear-state').getAttribute('aria-activedescendant')).toBe('linear-state-option-1');
    expect(activeLabel(app)).toBe('Triage');
    expect(document.activeElement).toBe(trigger(app, 'linear-state'));

    trigger(app, 'linear-state').click();
    await closed(app, 'linear-state');
    expect(trigger(app, 'linear-state').hasAttribute('aria-activedescendant')).toBe(false);
  });

  it('chooses an option with a click: closes, refocuses, updates the value and the hidden input', async () => {
    const app = await ready();

    await pick(app, 'linear-state', 'Todo');

    expect(shown(app, 'linear-state')).toBe('Todo');
    expect(submitted(app, 'stateId')).toBe('state-2');
    expect(document.activeElement).toBe(trigger(app, 'linear-state'));
    await vi.waitFor(() => {
      expect(popup(app, 'linear-state').querySelector('[aria-selected="true"]')?.textContent.trim()).toBe('Todo');
    });
  });

  it('chooses the empty option to clear a value, like the old "No project" row', async () => {
    const app = await ready();

    await pick(app, 'linear-project', 'No project');

    expect(submitted(app, 'projectId')).toBe('');
    expect(el(app, '#linear-project .linear-select__value').classList.contains('linear-select__value--placeholder')).toBe(true);
  });

  it('does nothing when the current option is chosen again', async () => {
    const app = await ready();
    const before = app.requests().length;

    await pick(app, 'linear-team', 'Support (SUP)');

    expect(app.requests()).toHaveLength(before);
    expect(shown(app, 'linear-project')).toBe('Website');
  });

  it('supports the keyboard: open, move, Home/End, select', async () => {
    const app = await ready();

    expect(key(app, 'linear-priority', 'ArrowDown').defaultPrevented).toBe(true);
    await opened(app, 'linear-priority');
    expect(activeLabel(app)).toBe('High');

    key(app, 'linear-priority', 'ArrowDown');
    await vi.waitFor(() => {
      expect(activeLabel(app)).toBe('Medium');
    });
    expect(trigger(app, 'linear-priority').getAttribute('aria-activedescendant')).toBe('linear-priority-option-3');
    key(app, 'linear-priority', 'End');
    await vi.waitFor(() => {
      expect(activeLabel(app)).toBe('Low');
    });
    key(app, 'linear-priority', 'Home');
    await vi.waitFor(() => {
      expect(activeLabel(app)).toBe('No priority');
    });
    key(app, 'linear-priority', 'ArrowDown');
    key(app, 'linear-priority', 'Enter');
    await closed(app, 'linear-priority');

    expect(shown(app, 'linear-priority')).toBe('Urgent');
    expect(submitted(app, 'priority')).toBe('1');
    expect(document.activeElement).toBe(trigger(app, 'linear-priority'));
  });

  it('selects with Space, and opens with Enter or ArrowUp', async () => {
    const app = await ready();

    key(app, 'linear-assignee', 'Enter');
    await opened(app, 'linear-assignee');
    key(app, 'linear-assignee', 'ArrowDown');
    key(app, 'linear-assignee', ' ');
    await closed(app, 'linear-assignee');
    expect(shown(app, 'linear-assignee')).toBe('Alan Turing');

    key(app, 'linear-assignee', 'ArrowUp');
    await opened(app, 'linear-assignee');
  });

  it('supports typeahead', async () => {
    const app = await ready();

    key(app, 'linear-project', 'Enter');
    await opened(app, 'linear-project');
    key(app, 'linear-project', 'p');
    await vi.waitFor(() => {
      expect(activeLabel(app)).toBe('Project team-1');
    });
    key(app, 'linear-project', 'Enter');
    await closed(app, 'linear-project');

    expect(shown(app, 'linear-project')).toBe('Project team-1');
  });

  it('closes with Escape (refocusing the trigger) and with Tab, selecting nothing', async () => {
    const app = await ready();

    key(app, 'linear-state', 'Enter');
    await opened(app, 'linear-state');
    key(app, 'linear-state', 'ArrowDown');
    key(app, 'linear-state', 'Escape');
    await closed(app, 'linear-state');
    expect(shown(app, 'linear-state')).toBe('Triage');
    expect(document.activeElement).toBe(trigger(app, 'linear-state'));

    key(app, 'linear-state', 'Enter');
    await opened(app, 'linear-state');
    key(app, 'linear-state', 'ArrowDown');
    key(app, 'linear-state', 'Tab');
    await closed(app, 'linear-state');
    expect(shown(app, 'linear-state')).toBe('Triage');
  });

  it('follows the pointer and closes on a click outside', async () => {
    const app = await ready();
    trigger(app, 'linear-state').click();
    await opened(app, 'linear-state');

    popup(app, 'linear-state').querySelectorAll<HTMLElement>('[role="option"]')[2]?.dispatchEvent(new Event('pointermove', { bubbles: true }));
    await vi.waitFor(() => {
      expect(activeLabel(app)).toBe('Todo');
    });

    document.body.click();
    await closed(app, 'linear-state');
    expect(shown(app, 'linear-state')).toBe('Triage');
  });

  it('does not close when the click lands inside the popup padding', async () => {
    const app = await ready();
    trigger(app, 'linear-state').click();
    await opened(app, 'linear-state');

    popup(app, 'linear-state').click();

    expect(isOpen(app, 'linear-state')).toBe(true);
  });

  it('keeps only one select open at a time', async () => {
    const app = await ready();

    trigger(app, 'linear-state').click();
    await opened(app, 'linear-state');
    trigger(app, 'linear-priority').click();
    await opened(app, 'linear-priority');
    await closed(app, 'linear-state');

    key(app, 'linear-project', 'Enter');
    await opened(app, 'linear-project');
    await closed(app, 'linear-priority');
  });

  it('closes when the window is resized', async () => {
    const app = await ready();
    trigger(app, 'linear-state').click();
    await opened(app, 'linear-state');

    window.dispatchEvent(new Event('resize'));

    await closed(app, 'linear-state');
  });

  it('opens from its label', async () => {
    const app = await ready();

    el<HTMLLabelElement>(app, 'label[for="linear-assignee"]').click();

    await opened(app, 'linear-assignee');
    expect(document.activeElement).toBe(trigger(app, 'linear-assignee'));
  });

  it('flips above the trigger when there is more room there', async () => {
    const app = await ready();
    vi.stubGlobal('innerHeight', 300);
    vi.spyOn(trigger(app, 'linear-priority'), 'getBoundingClientRect').mockReturnValue({
      top: 200, bottom: 242, left: 0, right: 100, width: 100, height: 42, x: 0, y: 200, toJSON: () => ({}),
    });
    Object.defineProperty(popup(app, 'linear-priority'), 'scrollHeight', { value: 300, configurable: true });

    trigger(app, 'linear-priority').click();
    await opened(app, 'linear-priority');

    await vi.waitFor(() => {
      expect(popup(app, 'linear-priority').getAttribute('data-side')).toBe('top');
    });
    expect(popup(app, 'linear-priority').style.getPropertyValue('max-height')).toBe('188px');
  });

  it('opens below by default', async () => {
    const app = await ready();

    trigger(app, 'linear-priority').click();
    await opened(app, 'linear-priority');

    expect(popup(app, 'linear-priority').getAttribute('data-side')).toBe('bottom');
  });

  it('is disabled and busy while teams load, then enabled', async () => {
    const teams = deferred<Response>();
    const app = await boot(makeSettings({ failures: [], destination: null }), (url, init) =>
      url === '/linear/api/teams' ? teams.promise : happyBackend(url, init),
    );

    await vi.waitFor(() => {
      expect(trigger(app, 'linear-team').disabled).toBe(true);
    });
    expect(trigger(app, 'linear-team').getAttribute('aria-busy')).toBe('true');
    expect(shown(app, 'linear-team')).toBe('Loading teams…');
    expect(el(app, '#linear-team .linear-select__value').classList.contains('linear-select__value--placeholder')).toBe(true);
    trigger(app, 'linear-team').click();
    key(app, 'linear-team', 'ArrowDown');
    expect(isOpen(app, 'linear-team')).toBe(false);

    teams.resolve(jsonResponse({ teams: TEAMS }));

    await vi.waitFor(() => {
      expect(trigger(app, 'linear-team').disabled).toBe(false);
    });
    expect(trigger(app, 'linear-team').hasAttribute('aria-busy')).toBe(false);
    expect(shown(app, 'linear-team')).toBe('Select a team');
  });

  it('marks a field with a validation error as invalid and clears it on change', async () => {
    const app = await boot(makeSettings({ failures: [] }), (url, init) =>
      init.method === 'PUT'
        ? jsonResponse({ message: 'Invalid.', errors: { project_id: ['Pick another project.'], priority: ['Bad priority.'] } }, 422)
        : happyBackend(url, init),
    );
    await settled(app);

    submit(el<HTMLFormElement>(app, 'form.linear-form[aria-labelledby="linear-destination-title"]'));

    await vi.waitFor(() => {
      expect(trigger(app, 'linear-project').getAttribute('aria-invalid')).toBe('true');
    });
    expect(trigger(app, 'linear-priority').getAttribute('aria-invalid')).toBe('true');
    expect(trigger(app, 'linear-state').hasAttribute('aria-invalid')).toBe(false);
    expect(trigger(app, 'linear-project').closest('.linear-field')?.classList.contains('linear-field--invalid')).toBe(true);
    expect(text(app, '#linear-project-error')).toBe('Pick another project.');

    key(app, 'linear-project', 'ArrowDown');
    key(app, 'linear-project', 'Home');
    key(app, 'linear-project', 'Enter');

    await vi.waitFor(() => {
      expect(trigger(app, 'linear-project').hasAttribute('aria-invalid')).toBe(false);
    });
    expect(trigger(app, 'linear-priority').getAttribute('aria-invalid')).toBe('true');
  });

  it('saves the values chosen with the new selects, with priority as a number', async () => {
    const app = await ready();

    await pick(app, 'linear-priority', 'Low');
    await pick(app, 'linear-project', 'Project team-1');
    await pick(app, 'linear-state', 'Todo');
    await pick(app, 'linear-assignee', 'Alan Turing');
    submit(el<HTMLFormElement>(app, 'form.linear-form[aria-labelledby="linear-destination-title"]'));

    await vi.waitFor(() => {
      expect(app.requests().some((request) => request.method === 'PUT')).toBe(true);
    });
    expect(app.requests().find((request) => request.method === 'PUT')?.body).toMatchObject({
      teamId: 'team-1',
      projectId: 'project-team-1',
      stateId: 'state-2',
      assigneeId: 'member-2',
      priority: 4,
    });
  });

  it('focuses the team trigger through its data-linear-ref', async () => {
    const app = await ready(makeSettings({ failures: [], destination: null }));

    expect(el(app, '#linear-team').getAttribute('data-linear-ref')).toBe('team');
    el<HTMLElement>(app, '[data-linear-ref="team"]').focus();
    expect(document.activeElement).toBe(trigger(app, 'linear-team'));
  });
});

describe('accessibility of the rendered page', () => {
  it('gives every rendered control a label and valid description references', async () => {
    const app = await boot(makeSettings({ failures: [makeFailure()] }));
    await settled(app);

    for (const control of app.container.querySelectorAll('input:not([type="hidden"]), [role="combobox"]')) {
      const labelled =
        control.closest('label') !== null ||
        app.container.querySelector(`label[for="${control.id}"]`) !== null;
      expect(labelled, `#${control.id || control.getAttribute('name')} lacks a label`).toBe(true);
    }

    for (const element of app.container.querySelectorAll('[aria-describedby]')) {
      for (const id of (element.getAttribute('aria-describedby') ?? '').split(/\s+/).filter(Boolean)) {
        expect(app.container.querySelector(`#${id}`), `missing #${id}`).not.toBeNull();
      }
    }

    for (const button of app.container.querySelectorAll('button')) {
      const name = (button.textContent ?? '').trim() || button.getAttribute('aria-label') || '';
      expect(name, `unnamed button: ${button.outerHTML}`).not.toBe('');
    }

    const ids = [...app.container.querySelectorAll('[id]')].map((element) => element.id);
    expect(new Set(ids).size).toBe(ids.length);
  });
});

describe('select visuals', () => {
  const visualOptions = makeOptions({
    states: [
      makeState({ id: 'state-1', name: 'In Progress', type: 'started', color: '#f2c94c' }),
      makeState({ id: 'state-2', name: 'Done', type: 'completed', color: '#5e6ad2' }),
      makeState({ id: 'state-3', name: 'Mystery', type: 'brand-new', color: 'not-a-colour' }),
    ],
    projects: [
      makeProject({ id: 'project-1', name: 'Launch', color: '#26b5ce', icon: '🚀' }),
      makeProject({ id: 'project-2', name: 'Plumbing', color: '#eb5757', icon: 'Wrench' }),
    ],
    members: [
      makeMember({ id: 'member-1', name: 'Grace Hopper', avatarUrl: 'https://img.test/grace.png', initials: 'GH', avatarBackgroundColor: '#f2994a' }),
      makeMember({ id: 'member-2', name: 'Alan Turing', avatarUrl: null, initials: 'AT', avatarBackgroundColor: '#4cb782' }),
      makeMember({ id: 'member-3', name: 'Mallory', avatarUrl: 'javascript:alert(1)', initials: '<img src=x onerror=alert(1)>' }),
    ],
  });

  const backend: Handler = (url, init) => {
    if (url === '/linear/api/teams') {
      return jsonResponse({
        teams: [makeTeam({ id: 'team-1', name: 'Support', key: 'SUP', color: '#5e6ad2', icon: '🛟' }), makeTeam({ id: 'team-2', name: 'Eng', key: 'ENG', icon: 'Bug' })],
      });
    }

    return /\/options$/.test(url) ? jsonResponse(visualOptions) : happyBackend(url, init);
  };

  async function ready(): Promise<App> {
    const app = await boot(makeSettings({ failures: [] }), backend);
    await settled(app);
    await vi.waitFor(() => {
      expect(el(app, '#linear-assignee .linear-visual')).toBeTruthy();
    });
    return app;
  }

  async function open(app: App, id: string): Promise<HTMLElement> {
    trigger(app, id).click();
    await vi.waitFor(() => {
      expect(trigger(app, id).getAttribute('aria-expanded')).toBe('true');
    });
    return el(app, `#${id}-listbox`);
  }

  const triggerVisual = (app: App, id: string): HTMLElement => el(app, `button#${id} > .linear-visual`);

  it('shows the selected value\'s visual in each trigger, decorative and before the label', async () => {
    const app = await ready();

    const team = triggerVisual(app, 'linear-team');
    expect(team.classList.contains('linear-visual--tile')).toBe(true);
    expect(team.classList.contains('linear-visual--solid')).toBe(true);
    expect(team.textContent).toBe('🛟');
    expect(team.style.getPropertyValue('--linear-visual-bg')).toBe('#5e6ad2');

    const project = triggerVisual(app, 'linear-project');
    expect(project.classList.contains('linear-visual--soft')).toBe(true);
    expect(project.textContent).toBe('🚀');

    const state = triggerVisual(app, 'linear-state');
    expect(state.classList.contains('linear-visual--status-started')).toBe(true);
    expect(state.querySelector('svg')).not.toBeNull();
    expect(state.style.getPropertyValue('--linear-visual-color')).toBe('#f2c94c');

    expect(triggerVisual(app, 'linear-priority').classList.contains('linear-visual--priority')).toBe(true);
    expect(triggerVisual(app, 'linear-priority').querySelectorAll('.linear-visual__bar:not(.linear-visual__bar--off)')).toHaveLength(3);

    const assignee = triggerVisual(app, 'linear-assignee');
    expect(assignee.classList.contains('linear-visual--avatar')).toBe(true);
    expect(assignee.querySelector('.linear-visual__text')?.textContent).toBe('GH');

    for (const id of ['linear-team', 'linear-project', 'linear-state', 'linear-priority', 'linear-assignee']) {
      expect(triggerVisual(app, id).getAttribute('aria-hidden')).toBe('true');
      expect(trigger(app, id).getAttribute('aria-labelledby')).toBe(`${id}-label`);
    }
    expect(shown(app, 'linear-assignee')).toBe('Grace Hopper');
  });

  it('renders a visual on every option of every select and keeps the label as the text', async () => {
    const app = await ready();

    for (const [id, labels] of [
      ['linear-team', ['Select a team', 'Support (SUP)', 'Eng (ENG)']],
      ['linear-project', ['No project', 'Launch', 'Plumbing']],
      ['linear-state', ['Team default', 'In Progress', 'Done', 'Mystery']],
      ['linear-priority', ['No priority', 'Urgent', 'High', 'Medium', 'Low']],
      ['linear-assignee', ['Unassigned', 'Grace Hopper', 'Alan Turing', 'Mallory']],
    ] as const) {
      const list = await open(app, id);
      const options = [...list.querySelectorAll('[role="option"]')];

      expect(options.map((option) => option.querySelector('.linear-select__item-label')?.textContent)).toEqual(labels);
      options.forEach((option, index) => {
        // Every item but the team placeholder has a visual.
        expect(option.querySelector('.linear-visual') !== null, `${id} option ${index}`).toBe(id !== 'linear-team' || index > 0);
      });
      key(app, id, 'Escape');
    }
  });

  function key(app: App, id: string, name: string): void {
    trigger(app, id).dispatchEvent(new KeyboardEvent('keydown', { key: name, bubbles: true, cancelable: true }));
  }

  it('draws status, priority and "none" icons and falls back to a plain circle for an unknown type', async () => {
    const app = await ready();

    const states = [...(await open(app, 'linear-state')).querySelectorAll('[role="option"]')];
    expect(states[0]?.querySelector('.linear-visual--icon svg')).not.toBeNull();
    expect(states[2]?.querySelector('.linear-visual--status-completed path')).not.toBeNull();
    expect(states[3]?.querySelector('.linear-visual--status-unstarted svg')).not.toBeNull();
    expect((states[3]?.querySelector('.linear-visual') as HTMLElement).style.getPropertyValue('--linear-visual-color')).toBe('');
    key(app, 'linear-state', 'Escape');

    const priorities = [...(await open(app, 'linear-priority')).querySelectorAll('[role="option"]')];
    expect(priorities[1]?.querySelector('.linear-visual__urgent')).not.toBeNull();
    expect(priorities[4]?.querySelectorAll('.linear-visual__bar:not(.linear-visual__bar--off)')).toHaveLength(1);
    key(app, 'linear-priority', 'Escape');

    const projects = [...(await open(app, 'linear-project')).querySelectorAll('[role="option"]')];
    expect(projects[0]?.querySelector('.linear-visual--icon svg')).not.toBeNull();
    expect(projects[2]?.querySelector('.linear-visual--icon svg')).not.toBeNull();
  });

  it('loads avatars lazily without a referrer and falls back to the initials when the image fails', async () => {
    const app = await ready();
    const image = el<HTMLImageElement>(app, 'button#linear-assignee .linear-visual__image');

    expect(image.getAttribute('src')).toBe('https://img.test/grace.png');
    expect(image.getAttribute('loading')).toBe('lazy');
    expect(image.getAttribute('referrerpolicy')).toBe('no-referrer');
    expect(image.getAttribute('alt')).toBe('');
    expect(image.hidden).toBe(false);

    image.dispatchEvent(new Event('error'));

    expect(image.hidden).toBe(true);
    expect(el(app, 'button#linear-assignee .linear-visual__text').textContent).toBe('GH');
  });

  it('shows initials only without an image URL, and never renders unsafe URLs or markup from the API', async () => {
    const app = await ready();
    const list = await open(app, 'linear-assignee');
    const options = [...list.querySelectorAll('[role="option"]')];

    expect(options[2]?.querySelector('img')).toBeNull();
    expect(options[2]?.querySelector('.linear-visual__text')?.textContent).toBe('AT');
    expect(options[3]?.querySelector('img')).toBeNull();
    expect(list.querySelector('img[src^="javascript"]')).toBeNull();
    expect(options[3]?.querySelector('.linear-visual__text')?.textContent).toBe('<IM');
    expect(options[3]?.querySelector('.linear-visual__text img')).toBeNull();
  });

  it('updates the trigger visual when another value is chosen', async () => {
    const app = await ready();

    await pick(app, 'linear-project', 'Plumbing');

    const visual = triggerVisual(app, 'linear-project');
    expect(visual.classList.contains('linear-visual--icon')).toBe(true);
    expect(visual.style.getPropertyValue('--linear-visual-color')).toBe('#eb5757');

    await pick(app, 'linear-assignee', 'Unassigned');

    expect(triggerVisual(app, 'linear-assignee').classList.contains('linear-visual--icon')).toBe(true);
  });
});
