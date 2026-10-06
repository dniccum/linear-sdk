import { describe, expect, it } from 'vitest';
import { linearApp } from '../../components/linear-app';
import type { Settings } from '../../types';
import { makeConnection, makeDestination, makeFailure, makeSettings } from '../fixtures';

function appWith(overrides: Partial<Settings> = {}) {
  return linearApp(makeSettings(overrides));
}

describe('initial state', () => {
  it('copies connection, destination, failures and flash from the settings', () => {
    const settings = makeSettings({ flash: { status: 'Hello', error: null } });
    const app = linearApp(settings);

    expect(app.connection).toEqual(settings.connection);
    expect(app.destination).toEqual(settings.destination);
    expect(app.failures).toEqual(settings.failures);
    expect(app.flash).toEqual({ status: 'Hello', error: null });
    expect(app.settings).toBe(settings);

    expect(app.connection).not.toBe(settings.connection);
    expect(app.destination).not.toBe(settings.destination);
    expect(app.failures).not.toBe(settings.failures);
    expect(app.failures[0]).not.toBe(settings.failures[0]);
    expect(app.flash).not.toBe(settings.flash);
  });

  it('keeps a missing connection and destination null', () => {
    const app = appWith({ connection: null, destination: null });

    expect(app.connection).toBeNull();
    expect(app.destination).toBeNull();
  });
});

describe('connection view', () => {
  it.each([
    ['active connection', {}, 'active', 'Connected', 'success'],
    ['connection needing reconnect', { connection: makeConnection({ status: 'needs_reconnect' }) }, 'needs_reconnect', 'Needs reconnect', 'warning'],
    ['no connection', { connection: null }, 'disconnected', 'Not connected', 'muted'],
    ['not configured', { connection: null, configured: false }, 'unconfigured', 'Not configured', 'muted'],
  ] as const)('%s', (_name, overrides, view, label, tone) => {
    const app = appWith(overrides);

    expect(app.connectionView).toBe(view);
    expect(app.statusLabel).toBe(label);
    expect(app.statusTone).toBe(tone);
  });

  it('describes each view', () => {
    expect(appWith().connectionDescription).toMatch(/is connected/);
    expect(appWith({ connection: makeConnection({ status: 'needs_reconnect' }) }).connectionDescription).toMatch(/revoked or expired/);
    expect(appWith({ connection: null, configured: false }).connectionDescription).toMatch(/Add your Linear credentials/);
    expect(appWith({ connection: null }).connectionDescription).toMatch(/Authorise access/);
    expect(appWith({ connection: null, authMode: 'api_key' }).connectionDescription).toMatch(/Paste a Linear API key/);
  });

  it('derives the flags the template uses', () => {
    const active = appWith();
    expect(active.isConnected).toBe(true);
    expect(active.needsReconnect).toBe(false);
    expect(active.canConfigure).toBe(true);
    expect(active.actionRequired).toBe(false);
    expect(active.showConnectLink).toBe(false);
    expect(active.showApiKeyForm).toBe(false);

    const stale = appWith({ connection: makeConnection({ status: 'needs_reconnect' }) });
    expect(stale.isConnected).toBe(true);
    expect(stale.needsReconnect).toBe(true);
    expect(stale.canConfigure).toBe(false);
    expect(stale.actionRequired).toBe(true);
    expect(stale.showConnectLink).toBe(true);
    expect(stale.showApiKeyForm).toBe(false);

    const none = appWith({ connection: null });
    expect(none.isConnected).toBe(false);
    expect(none.actionRequired).toBe(true);
    expect(none.showConnectLink).toBe(true);

    const apiKey = appWith({ connection: null, authMode: 'api_key' });
    expect(apiKey.showConnectLink).toBe(false);
    expect(apiKey.showApiKeyForm).toBe(true);

    const unconfigured = appWith({ connection: null, configured: false });
    expect(unconfigured.actionRequired).toBe(false);
    expect(unconfigured.showConnectLink).toBe(false);
    expect(unconfigured.showApiKeyForm).toBe(false);
  });

  it('labels the connect and API key actions', () => {
    const stale = appWith({ connection: makeConnection({ status: 'needs_reconnect' }) });
    expect(stale.connectLabel).toBe('Reconnect to Linear');
    expect(stale.apiKeyLabel).toBe('Replace API key');

    const fresh = appWith({ connection: null });
    expect(fresh.connectLabel).toBe('Connect with Linear');
    expect(fresh.apiKeyLabel).toBe('Connect');
  });

  it('builds the organisation label', () => {
    expect(appWith().organizationLabel).toBe('Acme Inc');
    expect(appWith({ connection: makeConnection({ organizationName: null }) }).organizationLabel).toBe('Linear workspace');
    expect(appWith({ connection: null }).organizationLabel).toBe('Linear workspace');
  });

  it('builds the account label from name and email', () => {
    expect(appWith().accountLabel).toBe('Ada Lovelace (ada@example.com)');
    expect(appWith({ connection: makeConnection({ userEmail: null }) }).accountLabel).toBe('Ada Lovelace');
    expect(appWith({ connection: makeConnection({ userName: null }) }).accountLabel).toBe('ada@example.com');
    expect(appWith({ connection: makeConnection({ userName: null, userEmail: null }) }).accountLabel).toBe('');
    expect(appWith({ connection: null }).accountLabel).toBe('');
  });
});

describe('destination summary', () => {
  it('is null without a destination', () => {
    expect(appWith({ destination: null }).destinationSummary).toBeNull();
  });

  it('describes automatic and manual sending', () => {
    expect(appWith().destinationSummary).toBe('New issues are sent to Support automatically.');
    expect(appWith({ destination: makeDestination({ sendMode: 'manual' }) }).destinationSummary).toBe(
      'Issues are sent to Support when you choose to send them.',
    );
  });

  it('falls back when the team name is unknown', () => {
    expect(appWith({ destination: makeDestination({ teamName: null }) }).destinationSummary).toBe(
      'New issues are sent to the selected team automatically.',
    );
  });
});

describe('failures', () => {
  it('reports whether there are failures and summarises them', () => {
    const one = appWith({ failures: [makeFailure()] });
    expect(one.hasFailures).toBe(true);
    expect(one.failuresSummary).toBe('1 delivery failed. Retry once the underlying problem is fixed.');

    const many = appWith({ failures: [makeFailure(), makeFailure({ id: 'failure-2' })] });
    expect(many.failuresSummary).toBe('2 deliveries failed. Retry once the underlying problem is fixed.');

    expect(appWith({ failures: [] }).hasFailures).toBe(false);
  });

  it('removes a failure by id', () => {
    const app = appWith({ failures: [makeFailure({ id: 'a' }), makeFailure({ id: 'b' })] });

    app.onFailureRemoved({ id: 'a' });

    expect(app.failures.map((failure) => failure.id)).toEqual(['b']);
  });

  it('restores a failure at its original index', () => {
    const app = appWith({ failures: [makeFailure({ id: 'a' }), makeFailure({ id: 'c' })] });

    app.onFailureRestored({ failure: makeFailure({ id: 'b' }), index: 1 });

    expect(app.failures.map((failure) => failure.id)).toEqual(['a', 'b', 'c']);
  });

  it('clamps the restore index to the end of the list', () => {
    const app = appWith({ failures: [makeFailure({ id: 'a' })] });

    app.onFailureRestored({ failure: makeFailure({ id: 'z' }), index: 99 });

    expect(app.failures.map((failure) => failure.id)).toEqual(['a', 'z']);
  });

  it('does not restore a failure that is already listed', () => {
    const app = appWith({ failures: [makeFailure({ id: 'a' })] });

    app.onFailureRestored({ failure: makeFailure({ id: 'a' }), index: 0 });

    expect(app.failures).toHaveLength(1);
  });
});

describe('flash banner', () => {
  it('shows a status or an error, replacing whatever was there', () => {
    const app = appWith({ flash: { status: 'old', error: 'old error' } });

    app.onFlash({ kind: 'status', message: 'Saved' });
    expect(app.flash).toEqual({ status: 'Saved', error: null });

    app.onFlash({ kind: 'error', message: 'Broken' });
    expect(app.flash).toEqual({ status: null, error: 'Broken' });
  });

  it('dismisses one banner without touching the other', () => {
    const app = appWith({ flash: { status: 'ok', error: 'bad' } });

    app.dismissStatus();
    expect(app.flash).toEqual({ status: null, error: 'bad' });

    app.dismissError();
    expect(app.flash).toEqual({ status: null, error: null });

    app.flash = { status: 'ok', error: 'bad' };
    app.dismissError();
    expect(app.flash).toEqual({ status: 'ok', error: null });
  });
});

describe('events from children', () => {
  it('stores the destination the form saved or removed', () => {
    const app = appWith({ destination: null });
    const saved = makeDestination({ teamName: 'Engineering' });

    app.onDestinationChanged({ destination: saved });
    expect(app.destination).toEqual(saved);

    app.onDestinationChanged({ destination: null });
    expect(app.destination).toBeNull();
  });

  it('marks the connection as needing reconnect and shows the message', () => {
    const app = appWith();

    app.onReconnectRequired({ message: 'Please reconnect.' });

    expect(app.connection).toMatchObject({ status: 'needs_reconnect', lastError: 'Please reconnect.' });
    expect(app.flash).toEqual({ status: null, error: 'Please reconnect.' });
    expect(app.canConfigure).toBe(false);
  });

  it('still shows the message when there is no connection', () => {
    const app = appWith({ connection: null });

    app.onReconnectRequired({ message: 'Please reconnect.' });

    expect(app.connection).toBeNull();
    expect(app.flash.error).toBe('Please reconnect.');
  });
});

describe('formatDate', () => {
  it('delegates to the date formatter', () => {
    const app = appWith();

    expect(app.formatDate(null)).toBe('');
    expect(app.formatDate('not a date')).toBe('not a date');
    expect(app.formatDate('2026-01-15T10:30:00Z')).toMatch(/2026/);
  });
});
