import { isRecord, type Guard } from '../guards';
import {
  isDestinationResponse,
  isFieldErrorsBody,
  isMessageBody,
  isRetryResponse,
  isSavedDestinationResponse,
  isTeamOptions,
  isTeamsResponse,
} from '../schema';
import type { Destination, DestinationPayload, Settings, Team, TeamOptions } from '../types';
import { ApiError } from './errors';

type HttpMethod = 'GET' | 'PUT' | 'POST' | 'DELETE';

/** The parts of `Settings` the client needs. */
export interface ClientConfig {
  csrf: Settings['csrf'];
  urls: Pick<Settings['urls'], 'teams' | 'teamOptions' | 'destination' | 'retry'>;
}

export type FetchLike = (input: string, init?: RequestInit) => Promise<Response>;

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

const STATUS_MESSAGES: Readonly<Record<number, string>> = {
  401: 'You are not signed in. Reload the page and try again.',
  403: 'You do not have permission to do that.',
  404: 'That could not be found. Reload the page and try again.',
  409: 'The Linear connection needs to be re-authorised.',
  419: 'Your session expired. Reload the page and try again.',
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

export function createClient(
  config: ClientConfig,
  fetchImpl: FetchLike = (input, init) => globalThis.fetch(input, init),
): LinearClient {
  async function request<T>(method: HttpMethod, url: string, guard: Guard<T>, body?: unknown): Promise<T> {
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

    const payload = await readJson(response);

    if (!response.ok) {
      throw toApiError(response.status, payload);
    }

    if (!guard(payload)) {
      throw new ApiError(UNEXPECTED_RESPONSE_MESSAGE, { status: response.status });
    }

    return payload;
  }

  return {
    async getTeams() {
      return (await request('GET', config.urls.teams, isTeamsResponse)).teams;
    },

    async getTeamOptions(teamId) {
      return request('GET', fillUrl(config.urls.teamOptions, 'team', teamId), isTeamOptions);
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
