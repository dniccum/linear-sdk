import type { LinearClient } from '../api/client';
import type { Settings } from '../types';
import { connectionCard } from './connection-card';
import { destinationForm } from './destination-form';
import { failuresList } from './failures-list';
import { linearApp } from './linear-app';
import { linearSelect, type SelectConfig } from './select';

/** The slice of Alpine's API used to register components (`Alpine.data`). */
export interface ComponentRegistry {
  /**
   * `factory` receives whatever the `x-data` expression passes: the host
   * element (`$el`) and, for `linearSelect` only, its configuration.
   */
  data(name: string, factory: (host: HTMLElement, config: SelectConfig) => object): void;
}

export interface ComponentContext {
  settings: Settings;
  client: LinearClient;
}

/** Component names as used in `x-data="..."` by `ui/template.ts`. */
export const COMPONENT_NAMES = {
  app: 'linearApp',
  connection: 'connectionCard',
  destination: 'destinationForm',
  failures: 'failuresList',
  select: 'linearSelect',
} as const;

/** Registers every Alpine component with its dependencies bound. */
export function registerComponents(registry: ComponentRegistry, { settings, client }: ComponentContext): void {
  registry.data(COMPONENT_NAMES.app, () => linearApp(settings));
  registry.data(COMPONENT_NAMES.connection, (host) => connectionCard(host));
  registry.data(COMPONENT_NAMES.destination, (host) =>
    destinationForm({ host, destination: settings.destination, client }),
  );
  registry.data(COMPONENT_NAMES.failures, (host) => failuresList({ host, client }));
  registry.data(COMPONENT_NAMES.select, (host, config) => linearSelect(host, config));
}
