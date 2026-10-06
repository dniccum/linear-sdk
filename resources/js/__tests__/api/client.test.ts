import { describe, expect, it, vi } from 'vitest';
import {
  createClient,
  fillUrl,
  NETWORK_ERROR_MESSAGE,
  UNEXPECTED_RESPONSE_MESSAGE,
  type ClientConfig,
  type FetchLike,
} from '../../api/client';
import { ApiError } from '../../api/errors';
import type { DestinationPayload } from '../../types';
import { jsonResponse, makeDestination, makeOptions } from '../fixtures';

const config: ClientConfig = {
  csrf: 'csrf-123',
  urls: {
    teams: '/linear/api/teams',
    teamOptions: '/linear/api/teams/{team}/options',
    destination: '/linear/api/destination',
    retry: '/linear/api/issues/{link}/retry',
  },
};

function clientWith(handler: FetchLike) {
  const fetchMock = vi.fn<FetchLike>(handler);
  return { client: createClient(config, fetchMock), fetchMock };
}

async function rejection(promise: Promise<unknown>): Promise<ApiError> {
  try {
    await promise;
  } catch (error) {
    if (error instanceof ApiError) {
      return error;
    }
    throw error;
  }
  throw new Error('Expected the promise to reject.');
}

const payload: DestinationPayload = {
  sendMode: 'manual',
  teamId: 'team-1',
  projectId: null,
  stateId: null,
  labelIds: ['label-1'],
  priority: 3,
  assigneeId: null,
};

describe('fillUrl', () => {
  it('replaces the literal placeholder with the encoded value', () => {
    expect(fillUrl('/teams/{team}/options', 'team', 'a b/c')).toBe('/teams/a%20b%2Fc/options');
  });

  it('accepts the percent-encoded placeholder Laravel route() produces', () => {
    expect(fillUrl('/teams/%7Bteam%7D/options', 'team', 'abc')).toBe('/teams/abc/options');
    expect(fillUrl('/teams/%7bteam%7d/options', 'team', 'abc')).toBe('/teams/abc/options');
  });

  it('replaces every occurrence and does not interpret $ in the value', () => {
    expect(fillUrl('/{link}/x/{link}', 'link', "$&'")).toBe("/%24%26'/x/%24%26'");
  });

  it('throws when the placeholder is missing', () => {
    expect(() => fillUrl('/teams/options', 'team', 'abc')).toThrow(TypeError);
    expect(() => fillUrl('/teams/options', 'team', 'abc')).toThrow(/\{team\}/);
  });
});

describe('request plumbing', () => {
  it('sends JSON, CSRF and XHR headers on every request', async () => {
    const { client, fetchMock } = clientWith(async () => jsonResponse({ teams: [] }));

    await client.getTeams();

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(url).toBe('/linear/api/teams');
    expect(init).toMatchObject({
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': 'csrf-123',
        'X-Requested-With': 'XMLHttpRequest',
      },
    });
    expect(init?.body).toBeUndefined();
    expect(init?.headers).not.toHaveProperty('Content-Type');
  });

  it('uses the global fetch when none is injected', async () => {
    const globalFetch = vi.fn(async () => jsonResponse({ teams: [{ id: '1', name: 'Support', key: 'SUP' }] }));
    vi.stubGlobal('fetch', globalFetch);

    const teams = await createClient(config).getTeams();

    expect(teams).toEqual([{ id: '1', name: 'Support', key: 'SUP' }]);
    expect(globalFetch).toHaveBeenCalledWith('/linear/api/teams', expect.objectContaining({ method: 'GET' }));
  });
});

describe('endpoints', () => {
  it('getTeams returns the teams', async () => {
    const teams = [{ id: 't1', name: 'Support', key: 'SUP' }];
    const { client } = clientWith(async () => jsonResponse({ teams }));

    await expect(client.getTeams()).resolves.toEqual(teams);
  });

  it('getTeamOptions fills {team} and returns the options', async () => {
    const options = makeOptions();
    const { client, fetchMock } = clientWith(async () => jsonResponse(options));

    await expect(client.getTeamOptions('team/1')).resolves.toEqual(options);

    expect(fetchMock.mock.calls[0]?.[0]).toBe('/linear/api/teams/team%2F1/options');
  });

  it('saveDestination PUTs the payload and returns the saved destination', async () => {
    const saved = makeDestination({ sendMode: 'manual', priority: 3 });
    const { client, fetchMock } = clientWith(async () => jsonResponse({ destination: saved }));

    await expect(client.saveDestination(payload)).resolves.toEqual(saved);

    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(url).toBe('/linear/api/destination');
    expect(init?.method).toBe('PUT');
    expect(init?.body).toBe(JSON.stringify(payload));
    expect(init?.headers).toMatchObject({ 'Content-Type': 'application/json' });
  });

  it('deleteDestination sends DELETE and resolves with nothing', async () => {
    const { client, fetchMock } = clientWith(async () => jsonResponse({ destination: null }));

    await expect(client.deleteDestination()).resolves.toBeUndefined();

    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(url).toBe('/linear/api/destination');
    expect(init?.method).toBe('DELETE');
    expect(init?.body).toBeUndefined();
  });

  it('retryIssue POSTs to the link URL', async () => {
    const { client, fetchMock } = clientWith(async () => jsonResponse({ ok: true }));

    await expect(client.retryIssue('link 9')).resolves.toBeUndefined();

    const [url, init] = fetchMock.mock.calls[0] ?? [];
    expect(url).toBe('/linear/api/issues/link%209/retry');
    expect(init?.method).toBe('POST');
  });

  it('rejects when a URL template is missing its placeholder', async () => {
    const client = createClient({ ...config, urls: { ...config.urls, retry: '/retry' } }, vi.fn<FetchLike>());

    await expect(client.retryIssue('1')).rejects.toBeInstanceOf(TypeError);
  });
});

describe('error narrowing', () => {
  it('maps 422 to field errors and the server message', async () => {
    const { client } = clientWith(async () =>
      jsonResponse({ message: 'The team id field is required.', errors: { teamId: ['Required'], 'labelIds.0': ['Bad'] } }, 422),
    );

    const error = await rejection(client.saveDestination(payload));

    expect(error.status).toBe(422);
    expect(error.isValidation).toBe(true);
    expect(error.message).toBe('The team id field is required.');
    expect(error.fieldErrors).toEqual({ teamId: ['Required'], 'labelIds.0': ['Bad'] });
    expect(error.reconnect).toBe(false);
  });

  it('tolerates a 422 without a usable errors map', async () => {
    const { client } = clientWith(async () => jsonResponse({ errors: 'broken' }, 422));

    const error = await rejection(client.saveDestination(payload));

    expect(error.fieldErrors).toEqual({});
    expect(error.message).toBe('The submitted values are not valid.');
  });

  it('ignores field errors on non-422 responses', async () => {
    const { client } = clientWith(async () => jsonResponse({ message: 'Hmm', errors: { a: ['b'] } }, 400));

    expect((await rejection(client.getTeams())).fieldErrors).toEqual({});
  });

  it('flags 409 reconnect responses', async () => {
    const { client } = clientWith(async () => jsonResponse({ message: 'Reconnect Linear.', reconnect: true }, 409));

    const error = await rejection(client.getTeams());

    expect(error.status).toBe(409);
    expect(error.reconnect).toBe(true);
    expect(error.message).toBe('Reconnect Linear.');
  });

  it('does not flag a 409 without reconnect: true', async () => {
    const { client } = clientWith(async () => jsonResponse({ message: 'Conflict' }, 409));

    expect((await rejection(client.getTeams())).reconnect).toBe(false);
  });

  it('does not treat reconnect on a non-409 as a reconnect request', async () => {
    const { client } = clientWith(async () => jsonResponse({ message: 'Odd', reconnect: true }, 500));

    expect((await rejection(client.getTeams())).reconnect).toBe(false);
  });

  it('does not flag a non-object 409 body', async () => {
    const { client } = clientWith(async () => new Response('["x"]', { status: 409 }));

    const error = await rejection(client.getTeams());

    expect(error.reconnect).toBe(false);
    expect(error.message).toBe('The Linear connection needs to be re-authorised.');
  });

  it('uses the server message for outages (503)', async () => {
    const { client } = clientWith(async () => jsonResponse({ message: 'Linear is down.' }, 503));

    const error = await rejection(client.getTeams());

    expect(error.status).toBe(503);
    expect(error.message).toBe('Linear is down.');
  });

  it.each([
    [401, /not signed in/],
    [403, /permission/],
    [404, /could not be found/],
    [419, /session expired/],
    [429, /Too many requests/],
    [503, /unavailable/],
    [500, /server hit an error/],
    [502, /server hit an error/],
    [418, /HTTP 418/],
  ])('falls back to a friendly message for an empty %i response', async (status, pattern) => {
    const { client } = clientWith(async () => new Response('', { status }));

    const error = await rejection(client.getTeams());

    expect(error.status).toBe(status);
    expect(error.message).toMatch(pattern);
  });

  it('falls back when the error body is not JSON', async () => {
    const { client } = clientWith(async () => new Response('<html>Server Error</html>', { status: 500 }));

    expect((await rejection(client.getTeams())).message).toMatch(/server hit an error/);
  });

  it('wraps network failures as status 0 with the cause', async () => {
    const cause = new TypeError('Failed to fetch');
    const { client } = clientWith(async () => {
      throw cause;
    });

    const error = await rejection(client.getTeams());

    expect(error.status).toBe(0);
    expect(error.isNetwork).toBe(true);
    expect(error.message).toBe(NETWORK_ERROR_MESSAGE);
    expect(error.cause).toBe(cause);
  });

  it('rejects successful responses with an unexpected shape', async () => {
    const { client } = clientWith(async () => jsonResponse({ teams: 'nope' }));

    const error = await rejection(client.getTeams());

    expect(error.status).toBe(200);
    expect(error.message).toBe(UNEXPECTED_RESPONSE_MESSAGE);
  });

  it('rejects successful responses that are not JSON', async () => {
    const { client } = clientWith(async () => new Response('OK', { status: 200 }));

    expect((await rejection(client.retryIssue('1'))).message).toBe(UNEXPECTED_RESPONSE_MESSAGE);
  });

  it('rejects a save that answers with a null destination', async () => {
    const { client } = clientWith(async () => jsonResponse({ destination: null }));

    expect((await rejection(client.saveDestination(payload))).message).toBe(UNEXPECTED_RESPONSE_MESSAGE);
  });

  it('rejects a retry that answers ok: false', async () => {
    const { client } = clientWith(async () => jsonResponse({ ok: false }));

    expect((await rejection(client.retryIssue('1'))).message).toBe(UNEXPECTED_RESPONSE_MESSAGE);
  });
});
