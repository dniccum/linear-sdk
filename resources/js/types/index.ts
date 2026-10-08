/**
 * Types mirroring the JSON/HTTP contract documented in `docs/http-contract.md`.
 *
 * This file is type-only (it emits no runtime code), which is why it is the one
 * file excluded from coverage. Keep every interface here in lock-step with the
 * PHP DTO payloads; the runtime guards in `settings.ts` and `api/guards.ts`
 * enforce the same shapes at the boundary.
 */
import type { Alpine } from 'alpinejs';

// --- Enumerations -----------------------------------------------------------

export type AuthMode = 'oauth' | 'api_key';
export type ConnectionStatus = 'active' | 'needs_reconnect';
export type SendMode = 'automatic' | 'manual';
export type FailureKind = 'issue' | 'comment';

/** Linear priorities: 0 none, 1 urgent, 2 high, 3 medium, 4 low. */
export type Priority = 0 | 1 | 2 | 3 | 4;

// --- Settings payload (embedded in the page) --------------------------------

export interface Brand {
  name: string;
  logo: string | null;
  /** CSS hex colour such as `#5E6AD2`; validated before it is applied. */
  color: string;
}

export interface Urls {
  connect: string;
  apiKey: string;
  disconnect: string;
  teams: string;
  /** Contains the literal `{team}` placeholder. */
  teamOptions: string;
  destination: string;
  /** Contains the literal `{link}` placeholder. */
  retry: string;
  /** Where to send a signed-out user; `''` when no login redirect is configured. */
  login: string;
}

export interface Connection {
  status: ConnectionStatus;
  organizationName: string | null;
  organizationUrlKey: string | null;
  userName: string | null;
  userEmail: string | null;
  lastError: string | null;
  /** ISO 8601 timestamp. */
  lastSyncedAt: string | null;
}

export interface Destination {
  sendMode: SendMode;
  teamId: string;
  teamName: string | null;
  projectId: string | null;
  stateId: string | null;
  labelIds: string[];
  priority: Priority;
  assigneeId: string | null;
}

export interface FailureItem {
  id: string;
  kind: FailureKind;
  subject: string;
  message: string | null;
  attempts: number;
  /** ISO 8601 timestamp. */
  occurredAt: string | null;
  /** Identifier substituted into `urls.retry`. */
  linkId: string;
}

export interface Flash {
  status: string | null;
  error: string | null;
}

export interface Settings {
  configured: boolean;
  authMode: AuthMode;
  brand: Brand;
  csrf: string;
  urls: Urls;
  connection: Connection | null;
  destination: Destination | null;
  failures: FailureItem[];
  flash: Flash;
}

// --- API payloads -----------------------------------------------------------

export interface Team {
  id: string;
  name: string;
  key: string;
}

export interface WorkflowState {
  id: string;
  name: string;
  type: string;
}

export interface Project {
  id: string;
  name: string;
}

export interface Member {
  id: string;
  name: string;
}

export interface Label {
  id: string;
  name: string;
  color: string | null;
}

export interface TeamOptions {
  states: WorkflowState[];
  projects: Project[];
  members: Member[];
  labels: Label[];
}

export interface TeamsResponse {
  teams: Team[];
}

/** Body of `PUT destination`: a `Destination` without `teamName`. */
export type DestinationPayload = Omit<Destination, 'teamName'>;

export interface DestinationResponse {
  destination: Destination | null;
}

export interface RetryResponse {
  ok: true;
}

/** Laravel validation failure: HTTP 422. */
export interface ValidationErrorBody {
  message: string;
  errors: Record<string, string[]>;
}

/** Connection needs re-authorising: HTTP 409. */
export interface ReconnectErrorBody {
  message: string;
  reconnect: true;
}

/** Any other error body the server may send (for example a 503 outage). */
export interface MessageErrorBody {
  message: string;
}

// --- UI -----------------------------------------------------------------------

/** Lifecycle of a remote resource shown in the UI. */
export type LoadState = 'idle' | 'loading' | 'ready' | 'error';

export type FlashKind = 'status' | 'error';

/**
 * Payloads of the DOM events child components dispatch (via Alpine's
 * `$dispatch`) to the root `linearApp` component. See `components/events.ts`.
 */
export interface LinearEventMap {
  'linear-flash': { kind: FlashKind; message: string };
  'linear-destination-changed': { destination: Destination | null };
  'linear-reconnect-required': { message: string };
  'linear-failure-removed': { id: string };
  'linear-failure-restored': { failure: FailureItem; index: number };
}

/**
 * The one Alpine magic the components use. Alpine injects it onto every
 * `x-data` object at runtime; unit tests supply a fake.
 *
 * Other magics (`$dispatch`, `$refs`, `$el`) are deliberately avoided inside
 * component methods: Alpine binds them to the element that *invoked* the
 * method, which may already be detached from the page by the time an async
 * method resumes. Components receive their own host element from `x-data`
 * instead (`x-data="name($el)"`).
 */
export interface AlpineMagics {
  $nextTick: (callback?: () => void) => Promise<void>;
}

declare global {
  interface Window {
    /** Set by `mount()` so host pages and the browser console can reach Alpine. */
    Alpine: Alpine;
  }
}
