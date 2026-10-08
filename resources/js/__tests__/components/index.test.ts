import { describe, expect, it, vi } from 'vitest';
import type { LinearClient } from '../../api/client';
import { COMPONENT_NAMES, registerComponents, type ComponentRegistry } from '../../components';
import type { FailuresListComponent } from '../../components/failures-list';
import type { SelectConfig } from '../../components/select';
import { captureEvents, makeDestination, makeFailure, makeHost, makeSettings, withMagics } from '../fixtures';

function setup() {
  const factories = new Map<string, (host: HTMLElement, config: SelectConfig) => object>();
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

const SELECT_CONFIG: SelectConfig = { id: 'x', value: () => 'a', items: () => [{ value: 'a', label: 'A' }], onSelect: () => undefined };

/** Builds a registered component the way Alpine does: `x-data="name($el, config)"`. */
function build(factories: Map<string, (host: HTMLElement, config: SelectConfig) => object>, name: string, host = makeHost()): object | undefined {
  return factories.get(name)?.(host, SELECT_CONFIG);
}

describe('registerComponents', () => {
  it('registers the five components under their x-data names', () => {
    const { factories } = setup();

    expect([...factories.keys()].sort()).toEqual(
      ['connectionCard', 'destinationForm', 'failuresList', 'linearApp', 'linearSelect'].sort(),
    );
    expect(COMPONENT_NAMES).toEqual({
      app: 'linearApp',
      connection: 'connectionCard',
      destination: 'destinationForm',
      failures: 'failuresList',
      select: 'linearSelect',
    });
  });

  it('builds a root component from the settings', () => {
    const { factories, settings } = setup();

    const app = build(factories, 'linearApp');

    expect(app).toMatchObject({ settings, connection: settings.connection });
  });

  it('builds a connection card', () => {
    const { factories } = setup();

    expect(build(factories, 'connectionCard')).toMatchObject({ confirmingDisconnect: false });
  });

  it('builds a destination form bound to the saved destination', () => {
    const { factories } = setup();

    const form = build(factories, 'destinationForm');

    expect(form).toMatchObject({ draft: { teamId: 'saved-team' }, hasSavedDestination: true });
  });

  it('builds a select bound to its host and configuration', () => {
    const { factories } = setup();

    expect(build(factories, 'linearSelect')).toMatchObject({ open: false, label: 'A', selectedValue: 'a' });
  });

  it('builds a failures list bound to the client and host', async () => {
    const { factories, client } = setup();
    const host = makeHost();
    const events = captureEvents(host, ['linear-failure-removed']);
    const list = withMagics(build(factories, 'failuresList', host) as FailuresListComponent);

    await list.retry(makeFailure({ id: 'f', linkId: 'l' }), 0);

    expect(client.retryIssue).toHaveBeenCalledWith('l');
    expect(events).toEqual([{ name: 'linear-failure-removed', detail: { id: 'f' } }]);
  });
});
