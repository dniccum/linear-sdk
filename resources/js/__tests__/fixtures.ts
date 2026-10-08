import { vi } from 'vitest';
import type {
  AlpineMagics,
  Connection,
  Destination,
  FailureItem,
  Member,
  Project,
  Settings,
  Team,
  TeamOptions,
  WorkflowState,
} from '../types';

export function makeTeam(overrides: Partial<Team> = {}): Team {
  return { id: 'team-1', name: 'Support', key: 'SUP', color: null, icon: null, ...overrides };
}

export function makeState(overrides: Partial<WorkflowState> = {}): WorkflowState {
  return { id: 'state-1', name: 'Triage', type: 'triage', color: null, ...overrides };
}

export function makeProject(overrides: Partial<Project> = {}): Project {
  return { id: 'project-1', name: 'Website', color: null, icon: null, ...overrides };
}

export function makeMember(overrides: Partial<Member> = {}): Member {
  return {
    id: 'member-1',
    name: 'Grace Hopper',
    avatarUrl: null,
    initials: null,
    avatarBackgroundColor: null,
    ...overrides,
  };
}

export function makeConnection(overrides: Partial<Connection> = {}): Connection {
  return {
    status: 'active',
    organizationName: 'Acme Inc',
    organizationUrlKey: 'acme',
    userName: 'Ada Lovelace',
    userEmail: 'ada@example.com',
    lastError: null,
    lastSyncedAt: '2026-01-15T10:30:00Z',
    ...overrides,
  };
}

export function makeDestination(overrides: Partial<Destination> = {}): Destination {
  return {
    sendMode: 'automatic',
    teamId: 'team-1',
    teamName: 'Support',
    projectId: 'project-1',
    stateId: 'state-1',
    labelIds: ['label-1'],
    priority: 2,
    assigneeId: 'member-1',
    ...overrides,
  };
}

export function makeFailure(overrides: Partial<FailureItem> = {}): FailureItem {
  return {
    id: 'failure-1',
    kind: 'issue',
    subject: 'Checkout is broken',
    message: 'Linear returned 500',
    attempts: 3,
    occurredAt: '2026-01-15T10:30:00Z',
    linkId: 'link-1',
    ...overrides,
  };
}

export function makeOptions(overrides: Partial<TeamOptions> = {}): TeamOptions {
  return {
    states: [
      makeState({ id: 'state-1', name: 'Triage', type: 'triage' }),
      makeState({ id: 'state-2', name: 'Todo', type: 'unstarted' }),
    ],
    projects: [
      makeProject({ id: 'project-1', name: 'Website' }),
      makeProject({ id: 'project-2', name: 'Mobile' }),
    ],
    members: [
      makeMember({ id: 'member-1', name: 'Grace Hopper' }),
      makeMember({ id: 'member-2', name: 'Alan Turing' }),
    ],
    labels: [
      { id: 'label-1', name: 'Bug', color: '#eb5757' },
      { id: 'label-2', name: 'Feature', color: null },
    ],
    ...overrides,
  };
}

/** A complete, valid settings payload (connected, with a destination and one failure). */
export function makeSettings(overrides: Partial<Settings> = {}): Settings {
  return {
    configured: true,
    authMode: 'oauth',
    brand: { name: 'Acme Support', logo: null, color: '#5E6AD2' },
    csrf: 'csrf-token',
    urls: {
      connect: '/linear/connect',
      apiKey: '/linear/api-key',
      disconnect: '/linear',
      teams: '/linear/api/teams',
      teamOptions: '/linear/api/teams/{team}/options',
      destination: '/linear/api/destination',
      retry: '/linear/api/issues/{link}/retry',
      login: '/login',
    },
    connection: makeConnection(),
    destination: makeDestination(),
    failures: [makeFailure()],
    flash: { status: null, error: null },
    back: null,
    ...overrides,
  };
}

/** The Alpine magic the components use, as a spy that runs its callback immediately. */
export function makeMagics(): AlpineMagics {
  return {
    $nextTick: vi.fn((callback?: () => void) => {
      callback?.();
      return Promise.resolve();
    }),
  };
}

/**
 * Alpine injects magics onto the component object at runtime; this does the
 * same for a component built outside Alpine so its `this.$nextTick` works.
 */
export function withMagics<T extends object>(component: T, magics: AlpineMagics = makeMagics()): T & AlpineMagics {
  return Object.assign(component, magics);
}

export function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

export interface Deferred<T> {
  promise: Promise<T>;
  resolve: (value: T) => void;
  reject: (reason: unknown) => void;
}

/** A promise settled from the outside, to control the order of async responses. */
export function deferred<T>(): Deferred<T> {
  let resolve: (value: T) => void = () => undefined;
  let reject: (reason: unknown) => void = () => undefined;
  const promise = new Promise<T>((res, rej) => {
    resolve = res;
    reject = rej;
  });
  return { promise, resolve, reject };
}

export interface CapturedEvent {
  name: string;
  detail: unknown;
}

/** Records the `CustomEvent`s (name + detail) that reach `target`. */
export function captureEvents(target: EventTarget, names: readonly string[]): CapturedEvent[] {
  const captured: CapturedEvent[] = [];

  for (const name of names) {
    target.addEventListener(name, (event) => {
      captured.push({ name, detail: event instanceof CustomEvent ? (event.detail as unknown) : undefined });
    });
  }

  return captured;
}

/** A host element attached to the document (so focus works) with focusable `data-linear-ref` children. */
export function makeHost(refs: readonly string[] = []): HTMLElement {
  const host = document.createElement('div');

  for (const ref of refs) {
    const button = document.createElement('button');
    button.setAttribute('data-linear-ref', ref);
    host.append(button);
  }

  document.body.append(host);
  return host;
}
