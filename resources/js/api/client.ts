import { isRecord, type Guard } from '../guards';
import {
  isDestinationResponse,
  isFieldErrorsBody,
  isMessageBody,
  isRetryResponse,
  isSavedDestinationResponse,
  isTeamOptions,
  isTeamsResponse,
  withTeamOptionsDefaults,
  withTeamsDefaults,
} from '../schema';
import type { Destination, DestinationPayload, Settings, Team, TeamOptions } from '../types';
import { ApiError } from './errors';

type HttpMethod = 'GET' | 'PUT' | 'POST' | 'DELETE';

/** The parts of `Settings` the client needs. */
export interface ClientConfig {
  csrf: Settings['csrf'];
  urls: Pick<Settings['urls'], 'teams' | 'teamOptions' | 'destination' | 'retry' | 'login'>;
}

export type FetchLike = (input: string, init?: RequestInit) => Promise<Response>;

/** Sends the browser to another page (`window.location.assign`); injectable for tests. */
export type Redirect = (url: string) => void;

/** Typed access to the JSON endpoints in `docs/http-contract.md`. */
export interface LinearClient {
  getTeams(): Promise<Team[]>;
  getTeamOptions(teamId: string): Promise<TeamOptions>;
  saveDestination(payload: DestinationPayload): Promise<Destination>;
  deleteDestination(): Promise<void>;
  retryIssue(linkId: string): Promise<void>;
}

export const NETWORK_ERROR_MESSAGE = 'Could not reach the server. Check your connection and try again.';
export const UNEXPECTED_RESPONSE_MESSAGE = 'The server sent a response this page could not understand.';

/** Shown (briefly) while the browser is being sent to the login page. */
export const SIGN_IN_REDIRECT_MESSAGE = 'Your session has expired. Redirecting to sign in…';
/** Shown when the session expired and there is no login page to send the user to. */
export const SESSION_EXPIRED_MESSAGE = 'Your session has expired. Please sign in again.';

const STATUS_MESSAGES: Readonly<Record<number, string>> = {
  403: 'You do not have permission to do that.',
  404: 'That could not be found. Reload the page and try again.',
  409: 'The Linear connection needs to be re-authorised.',
  422: 'The submitted values are not valid.',
  429: 'Too many requests. Wait a moment and try again.',
  503: 'Linear is unavailable right now. Please try again shortly.',
};

function statusMessage(status: number): string {
  const known = STATUS_MESSAGES[status];

  if (known !== undefined) {
    return known;
  }

  return status >= 500 ? 'The server hit an error. Please try again.' : `The request failed (HTTP ${status}).`;
}

/**
 * Fills a `{placeholder}` in a URL template with a URL-encoded value.
 *
 * Laravel's `route()` helper percent-encodes unresolved parameters, so the
 * encoded form (`%7Bteam%7D`) is accepted as well as the literal `{team}`.
 *
 * @throws {TypeError} when the placeholder is not present in the template.
 */
export function fillUrl(template: string, placeholder: string, value: string): string {
  const source = `\\{${placeholder}\\}|%7B${placeholder}%7D`;

  if (!new RegExp(source, 'i').test(template)) {
    throw new TypeError(`The URL template "${template}" does not contain a {${placeholder}} placeholder.`);
  }

  return template.replace(new RegExp(source, 'gi'), () => encodeURIComponent(value));
}

/** Narrows a failed response into an `ApiError` (message, field errors, reconnect). */
function toApiError(status: number, payload: unknown): ApiError {
  if (status === 401 || status === 419) {
    // The server's wording ("Unauthenticated.", "CSRF token mismatch.") is not for end users.
    return new ApiError(SESSION_EXPIRED_MESSAGE, { status });
  }

  return new ApiError(isMessageBody(payload) ? payload.message : statusMessage(status), {
    status,
    fieldErrors: status === 422 && isFieldErrorsBody(payload) ? payload.errors : {},
    reconnect: status === 409 && isRecord(payload) && payload['reconnect'] === true,
  });
}

async function readJson(response: Response): Promise<unknown> {
  try {
    return (await response.json()) as unknown;
  } catch {
    return null;
  }
}

/**
 * @param redirect used to send the browser to `config.urls.login` when a
 *   request fails with 401 or 419. That happens at most once, however many
 *   requests fail together. The failing request still rejects (with
 *   `SIGN_IN_REDIRECT_MESSAGE`, or `SESSION_EXPIRED_MESSAGE` when there is no
 *   login URL or the redirect threw) so callers restore their loading and
 *   optimistic state instead of being left waiting.
 */
export function createClient(
  config: ClientConfig,
  fetchImpl: FetchLike = (input, init) => globalThis.fetch(input, init),
  redirect: Redirect = (url) => {
    globalThis.location.assign(url);
  },
): LinearClient {
  let redirecting = false;

  /** Sends the browser to the login page; `false` when there is none or it could not be opened. */
  function signIn(): boolean {
    if (redirecting) {
      return true;
    }

    if (config.urls.login === '') {
      return false;
    }

    try {
      redirect(config.urls.login);
      redirecting = true;
    } catch {
      return false;
    }

    return true;
  }
  async function request<T>(
    method: HttpMethod,
    url: string,
    guard: Guard<T>,
    body?: unknown,
    normalize: (payload: unknown) => unknown = (payload) => payload,
  ): Promise<T> {
    const headers: Record<string, string> = {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-TOKEN': config.csrf,
    };
    const init: RequestInit = { method, headers, credentials: 'same-origin' };

    if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(body);
    }

    let response: Response;
    try {
      response = await fetchImpl(url, init);
    } catch (cause) {
      throw new ApiError(NETWORK_ERROR_MESSAGE, { status: 0, cause });
    }

    const raw = await readJson(response);

    if (!response.ok) {
      const error = toApiError(response.status, raw);

      if (error.isUnauthenticated && signIn()) {
        throw new ApiError(SIGN_IN_REDIRECT_MESSAGE, { status: error.status });
      }

      throw error;
    }

    const payload = normalize(raw);

    if (!guard(payload)) {
      throw new ApiError(UNEXPECTED_RESPONSE_MESSAGE, { status: response.status });
    }

    return payload;
  }

  return {
    async getTeams() {
      return (await request('GET', config.urls.teams, isTeamsResponse, undefined, withTeamsDefaults)).teams;
    },

    async getTeamOptions(teamId) {
      return request('GET', fillUrl(config.urls.teamOptions, 'team', teamId), isTeamOptions, undefined, withTeamOptionsDefaults);
    },

    async saveDestination(payload) {
      return (await request('PUT', config.urls.destination, isSavedDestinationResponse, payload)).destination;
    },

    async deleteDestination() {
      await request('DELETE', config.urls.destination, isDestinationResponse);
    },

    async retryIssue(linkId) {
      await request('POST', fillUrl(config.urls.retry, 'link', linkId), isRetryResponse);
    },
  };
}
