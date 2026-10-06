/**
 * Runtime guards for every payload in `docs/http-contract.md`. Each guard is
 * declared against its interface in `types/index.ts`, so the compiler fails if
 * the two drift apart.
 */
import {
  arrayOf,
  isBoolean,
  isNumber,
  isString,
  literal,
  nullable,
  oneOf,
  recordOf,
  shape,
  type Guard,
} from './guards';
import type {
  AuthMode,
  Brand,
  Connection,
  ConnectionStatus,
  Destination,
  DestinationResponse,
  FailureItem,
  FailureKind,
  Flash,
  Label,
  Member,
  Priority,
  Project,
  RetryResponse,
  SendMode,
  Settings,
  Team,
  TeamOptions,
  TeamsResponse,
  Urls,
  WorkflowState,
} from './types';

export const AUTH_MODES: readonly AuthMode[] = ['oauth', 'api_key'];
export const CONNECTION_STATUSES: readonly ConnectionStatus[] = ['active', 'needs_reconnect'];
export const SEND_MODES: readonly SendMode[] = ['automatic', 'manual'];
export const FAILURE_KINDS: readonly FailureKind[] = ['issue', 'comment'];
export const PRIORITIES: readonly Priority[] = [0, 1, 2, 3, 4];

const nullableString = nullable(isString);

export const isBrand: Guard<Brand> = shape<Brand>({
  name: isString,
  logo: nullableString,
  color: isString,
});

export const isUrls: Guard<Urls> = shape<Urls>({
  connect: isString,
  apiKey: isString,
  disconnect: isString,
  teams: isString,
  teamOptions: isString,
  destination: isString,
  retry: isString,
});

export const isConnection: Guard<Connection> = shape<Connection>({
  status: oneOf(CONNECTION_STATUSES),
  organizationName: nullableString,
  organizationUrlKey: nullableString,
  userName: nullableString,
  userEmail: nullableString,
  lastError: nullableString,
  lastSyncedAt: nullableString,
});

export const isDestination: Guard<Destination> = shape<Destination>({
  sendMode: oneOf(SEND_MODES),
  teamId: isString,
  teamName: nullableString,
  projectId: nullableString,
  stateId: nullableString,
  labelIds: arrayOf(isString),
  priority: oneOf(PRIORITIES),
  assigneeId: nullableString,
});

export const isFailureItem: Guard<FailureItem> = shape<FailureItem>({
  id: isString,
  kind: oneOf(FAILURE_KINDS),
  subject: isString,
  message: nullableString,
  attempts: isNumber,
  occurredAt: nullableString,
  linkId: isString,
});

export const isFlash: Guard<Flash> = shape<Flash>({
  status: nullableString,
  error: nullableString,
});

export const isSettings: Guard<Settings> = shape<Settings>({
  configured: isBoolean,
  authMode: oneOf(AUTH_MODES),
  brand: isBrand,
  csrf: isString,
  urls: isUrls,
  connection: nullable(isConnection),
  destination: nullable(isDestination),
  failures: arrayOf(isFailureItem),
  flash: isFlash,
});

// --- API responses ----------------------------------------------------------

const isTeam: Guard<Team> = shape<Team>({ id: isString, name: isString, key: isString });
const isWorkflowState: Guard<WorkflowState> = shape<WorkflowState>({
  id: isString,
  name: isString,
  type: isString,
});
const isProject: Guard<Project> = shape<Project>({ id: isString, name: isString });
const isMember: Guard<Member> = shape<Member>({ id: isString, name: isString });
const isLabel: Guard<Label> = shape<Label>({ id: isString, name: isString, color: nullableString });

export const isTeamsResponse: Guard<TeamsResponse> = shape<TeamsResponse>({ teams: arrayOf(isTeam) });

export const isTeamOptions: Guard<TeamOptions> = shape<TeamOptions>({
  states: arrayOf(isWorkflowState),
  projects: arrayOf(isProject),
  members: arrayOf(isMember),
  labels: arrayOf(isLabel),
});

export const isDestinationResponse: Guard<DestinationResponse> = shape<DestinationResponse>({
  destination: nullable(isDestination),
});

/** `PUT destination` must answer with the saved destination, never `null`. */
export const isSavedDestinationResponse: Guard<{ destination: Destination }> = shape<{
  destination: Destination;
}>({ destination: isDestination });

export const isRetryResponse: Guard<RetryResponse> = shape<RetryResponse>({ ok: literal(true) });

// --- Error bodies -------------------------------------------------------------

export const isMessageBody: Guard<{ message: string }> = shape<{ message: string }>({
  message: isString,
});

/** The `errors` map of a Laravel 422 response (`message` is read separately). */
export const isFieldErrorsBody: Guard<{ errors: Record<string, string[]> }> = shape<{
  errors: Record<string, string[]>;
}>({ errors: recordOf(arrayOf(isString)) });
