import { describe, expect, it } from 'vitest';
import {
  isConnection,
  isDestination,
  isDestinationResponse,
  isFailureItem,
  isFieldErrorsBody,
  isMessageBody,
  isRetryResponse,
  isSavedDestinationResponse,
  isSettings,
  isTeamOptions,
  isTeamsResponse,
} from '../schema';
import { makeConnection, makeDestination, makeFailure, makeOptions, makeSettings } from './fixtures';

describe('isSettings', () => {
  it('accepts a complete payload', () => {
    expect(isSettings(makeSettings())).toBe(true);
  });

  it('accepts a payload with no connection, destination or failures', () => {
    expect(isSettings(makeSettings({ connection: null, destination: null, failures: [] }))).toBe(true);
  });

  it('accepts the api_key auth mode and a logo URL', () => {
    const settings = makeSettings({
      authMode: 'api_key',
      brand: { name: 'Acme', logo: 'https://example.com/logo.png', color: '#fff' },
    });
    expect(isSettings(settings)).toBe(true);
  });

  it('rejects non-objects', () => {
    for (const value of [null, undefined, 'x', 1, [], true]) {
      expect(isSettings(value)).toBe(false);
    }
  });

  const broken: Array<[string, (settings: Record<string, unknown>) => void]> = [
    ['configured is not boolean', (s) => (s['configured'] = 'yes')],
    ['authMode is unknown', (s) => (s['authMode'] = 'basic')],
    ['brand is missing', (s) => delete s['brand']],
    ['brand.logo is a number', (s) => (s['brand'] = { name: 'A', logo: 1, color: '#fff' })],
    ['csrf is missing', (s) => delete s['csrf']],
    ['urls lacks a key', (s) => (s['urls'] = { connect: '/x' })],
    ['connection has a bad status', (s) => (s['connection'] = { ...makeConnection(), status: 'gone' })],
    ['connection is a string', (s) => (s['connection'] = 'active')],
    ['destination has a bad priority', (s) => (s['destination'] = { ...makeDestination(), priority: 5 })],
    ['destination has bad labelIds', (s) => (s['destination'] = { ...makeDestination(), labelIds: [1] })],
    ['failures is not an array', (s) => (s['failures'] = {})],
    ['a failure has a bad kind', (s) => (s['failures'] = [{ ...makeFailure(), kind: 'ticket' }])],
    ['a failure has non-numeric attempts', (s) => (s['failures'] = [{ ...makeFailure(), attempts: '3' }])],
    ['flash is missing', (s) => delete s['flash']],
    ['flash.status is a number', (s) => (s['flash'] = { status: 1, error: null })],
  ];

  it.each(broken)('rejects a payload where %s', (_name, mutate) => {
    const settings: Record<string, unknown> = { ...makeSettings() };
    mutate(settings);
    expect(isSettings(settings)).toBe(false);
  });
});

describe('entity guards', () => {
  it('isConnection / isDestination / isFailureItem accept valid entities', () => {
    expect(isConnection(makeConnection({ status: 'needs_reconnect', lastError: 'boom' }))).toBe(true);
    expect(isDestination(makeDestination({ sendMode: 'manual', priority: 0, projectId: null }))).toBe(true);
    expect(isFailureItem(makeFailure({ kind: 'comment', message: null, occurredAt: null }))).toBe(true);
  });

  it('reject entities with the wrong shape', () => {
    expect(isConnection({})).toBe(false);
    expect(isDestination({ ...makeDestination(), sendMode: 'sometimes' })).toBe(false);
    expect(isFailureItem({ ...makeFailure(), linkId: 5 })).toBe(false);
  });
});

describe('API response guards', () => {
  it('isTeamsResponse', () => {
    expect(isTeamsResponse({ teams: [{ id: '1', name: 'Support', key: 'SUP' }] })).toBe(true);
    expect(isTeamsResponse({ teams: [] })).toBe(true);
    expect(isTeamsResponse({ teams: [{ id: '1', name: 'Support' }] })).toBe(false);
    expect(isTeamsResponse({})).toBe(false);
  });

  it('isTeamOptions', () => {
    expect(isTeamOptions(makeOptions())).toBe(true);
    expect(isTeamOptions({ ...makeOptions(), labels: [{ id: '1', name: 'Bug' }] })).toBe(false);
    expect(isTeamOptions({ ...makeOptions(), states: [{ id: '1', name: 'Todo' }] })).toBe(false);
    expect(isTeamOptions({ ...makeOptions(), projects: 'none' })).toBe(false);
    expect(isTeamOptions({ ...makeOptions(), members: [{ id: 1, name: 'A' }] })).toBe(false);
  });

  it('isDestinationResponse allows a null destination', () => {
    expect(isDestinationResponse({ destination: null })).toBe(true);
    expect(isDestinationResponse({ destination: makeDestination() })).toBe(true);
    expect(isDestinationResponse({ destination: {} })).toBe(false);
    expect(isDestinationResponse({})).toBe(false);
  });

  it('isSavedDestinationResponse requires a destination', () => {
    expect(isSavedDestinationResponse({ destination: makeDestination() })).toBe(true);
    expect(isSavedDestinationResponse({ destination: null })).toBe(false);
  });

  it('isRetryResponse requires ok: true', () => {
    expect(isRetryResponse({ ok: true })).toBe(true);
    expect(isRetryResponse({ ok: false })).toBe(false);
    expect(isRetryResponse({})).toBe(false);
  });
});

describe('error body guards', () => {
  it('isMessageBody', () => {
    expect(isMessageBody({ message: 'Nope' })).toBe(true);
    expect(isMessageBody({ message: 1 })).toBe(false);
    expect(isMessageBody(null)).toBe(false);
  });

  it('isFieldErrorsBody', () => {
    expect(isFieldErrorsBody({ errors: { teamId: ['Required'] } })).toBe(true);
    expect(isFieldErrorsBody({ errors: { teamId: 'Required' } })).toBe(false);
    expect(isFieldErrorsBody({})).toBe(false);
  });
});
