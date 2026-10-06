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
  makeOptions,
  makeSettings,
} from './fixtures';

type Handler = (url: string, init: RequestInit) => Response | Promise<Response>;

const TEAMS = [
  { id: 'team-1', name: 'Support', key: 'SUP' },
  { id: 'team-2', name: 'Engineering', key: 'ENG' },
];

/** Default backend: teams, per-team options, save and delete all succeed. */
const happyBackend: Handler = (url, init) => {
  if (url === '/linear/api/teams') {
    return jsonResponse({ teams: TEAMS });
  }

  if (/\/linear\/api\/teams\/[^/]+\/options$/.test(url)) {
    const team = url.split('/teams/')[1]?.split('/')[0] ?? '';
    return jsonResponse(
      makeOptions({ projects: [{ id: `project-${team}`, name: `Project ${team}` }, { id: 'project-1', name: 'Website' }] }),
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

function choose(select: HTMLSelectElement, value: string): void {
  select.value = value;
  select.dispatchEvent(new Event('change', { bubbles: true }));
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
    expect(el<HTMLSelectElement>(app, '#linear-team').value).toBe('team-1');
    expect(el<HTMLSelectElement>(app, '#linear-project').value).toBe('project-1');
    expect(el<HTMLSelectElement>(app, '#linear-state').value).toBe('state-1');
    expect(el<HTMLSelectElement>(app, '#linear-assignee').value).toBe('member-1');
    expect(el<HTMLSelectElement>(app, '#linear-priority').value).toBe('2');
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

    const options = [...el<HTMLSelectElement>(app, '#linear-priority').options].map((option) => [option.value, option.text]);

    expect(options).toEqual([
      ['0', 'No priority'],
      ['1', 'Urgent'],
      ['2', 'High'],
      ['3', 'Medium'],
      ['4', 'Low'],
    ]);
  });

  it('shows team names with their keys and colour dots for labels', async () => {
    const app = await boot(makeSettings({ failures: [] }));
    await settled(app);

    const teams = [...el<HTMLSelectElement>(app, '#linear-team').options].map((option) => option.text);
    expect(teams).toEqual(['Select a team', 'Support (SUP)', 'Engineering (ENG)']);

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
    expect(el<HTMLSelectElement>(app, '#linear-team').disabled).toBe(true);
    expect(el<HTMLSelectElement>(app, '#linear-team').options[0]?.text).toBe('Teams unavailable');

    el<HTMLButtonElement>(app, '.linear-inline-error button').click();

    await vi.waitFor(() => {
      expect(el<HTMLSelectElement>(app, '#linear-team').disabled).toBe(false);
    });
    expect(app.container.querySelector('.linear-inline-error')).toBeNull();
  });

  it('loads new options and resets team-specific fields when the team changes', async () => {
    const app = await boot(makeSettings({ failures: [] }));
    await settled(app);

    choose(el<HTMLSelectElement>(app, '#linear-team'), 'team-2');

    await vi.waitFor(() => {
      expect(app.requests().map((request) => request.url)).toContain('/linear/api/teams/team-2/options');
    });
    await settled(app);
    expect(el<HTMLSelectElement>(app, '#linear-project').value).toBe('');
    expect(el<HTMLSelectElement>(app, '#linear-state').value).toBe('');
    expect(el<HTMLSelectElement>(app, '#linear-assignee').value).toBe('');
    expect(el<HTMLSelectElement>(app, '#linear-priority').value).toBe('2');
    expect([...app.container.querySelectorAll<HTMLInputElement>('.linear-chip__input')].some((input) => input.checked)).toBe(false);
    expect([...el<HTMLSelectElement>(app, '#linear-project').options].map((option) => option.value)).toEqual(['', 'project-team-2', 'project-1']);
  });

  it('returns to the idle hint when the team is cleared', async () => {
    const app = await boot(makeSettings({ failures: [] }));
    await settled(app);

    choose(el<HTMLSelectElement>(app, '#linear-team'), '');

    await vi.waitFor(() => {
      expect(app.container.querySelector('#linear-project')).toBeNull();
    });
    expect(text(app, '.linear-options .linear-hint')).toContain('Select a team');
    expect(el<HTMLButtonElement>(app, 'button[type="submit"].linear-button--primary').disabled).toBe(true);
  });

  it('saves the destination and reports it', async () => {
    const app = await boot(makeSettings({ failures: [] }));
    await settled(app);

    choose(el<HTMLSelectElement>(app, '#linear-priority'), '3');
    el<HTMLInputElement>(app, 'input[name="sendMode"][value="manual"]').click();
    el<HTMLInputElement>(app, '.linear-chip__input[value="label-2"]').click();
    choose(el<HTMLSelectElement>(app, '#linear-assignee'), '');
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
    choose(el<HTMLSelectElement>(app, '#linear-priority'), '1');
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
    expect(el<HTMLSelectElement>(app, '#linear-team').value).toBe('');
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

describe('accessibility of the rendered page', () => {
  it('gives every rendered control a label and valid description references', async () => {
    const app = await boot(makeSettings({ failures: [makeFailure()] }));
    await settled(app);

    for (const control of app.container.querySelectorAll('input:not([type="hidden"]), select')) {
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
