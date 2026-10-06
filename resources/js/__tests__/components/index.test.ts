import { describe, expect, it, vi } from 'vitest';
import type { LinearClient } from '../../api/client';
import { COMPONENT_NAMES, registerComponents, type ComponentRegistry } from '../../components';
import type { FailuresListComponent } from '../../components/failures-list';
import { captureEvents, makeDestination, makeFailure, makeHost, makeSettings, withMagics } from '../fixtures';

function setup() {
  const factories = new Map<string, (host: HTMLElement) => object>();
  const registry: ComponentRegistry = {
    data: (name, factory) => {
      factories.set(name, factory);
    },
  };
  const client: LinearClient = {
    getTeams: vi.fn().mockResolvedValue([]),
    getTeamOptions: vi.fn(),
    saveDestination: vi.fn(),
    deleteDestination: vi.fn(),
    retryIssue: vi.fn().mockResolvedValue(undefined),
  };
  const settings = makeSettings({ destination: makeDestination({ teamId: 'saved-team' }) });

  registerComponents(registry, { settings, client });

  return { factories, client, settings };
}

describe('registerComponents', () => {
  it('registers the four components under their x-data names', () => {
    const { factories } = setup();

    expect([...factories.keys()].sort()).toEqual(
      ['connectionCard', 'destinationForm', 'failuresList', 'linearApp'].sort(),
    );
    expect(COMPONENT_NAMES).toEqual({
      app: 'linearApp',
      connection: 'connectionCard',
      destination: 'destinationForm',
      failures: 'failuresList',
    });
  });

  it('builds a root component from the settings', () => {
    const { factories, settings } = setup();

    const app = factories.get('linearApp')?.(makeHost());

    expect(app).toMatchObject({ settings, connection: settings.connection });
  });

  it('builds a connection card', () => {
    const { factories } = setup();

    expect(factories.get('connectionCard')?.(makeHost())).toMatchObject({ confirmingDisconnect: false });
  });

  it('builds a destination form bound to the saved destination', () => {
    const { factories } = setup();

    const form = factories.get('destinationForm')?.(makeHost());

    expect(form).toMatchObject({ draft: { teamId: 'saved-team' }, hasSavedDestination: true });
  });

  it('builds a failures list bound to the client and host', async () => {
    const { factories, client } = setup();
    const host = makeHost();
    const events = captureEvents(host, ['linear-failure-removed']);
    const list = withMagics(factories.get('failuresList')?.(host) as FailuresListComponent);

    await list.retry(makeFailure({ id: 'f', linkId: 'l' }), 0);

    expect(client.retryIssue).toHaveBeenCalledWith('l');
    expect(events).toEqual([{ name: 'linear-failure-removed', detail: { id: 'f' } }]);
  });
});
