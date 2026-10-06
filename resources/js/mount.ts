import Alpine from 'alpinejs';
import { createClient } from './api/client';
import { errorMessage } from './api/errors';
import { applyBrand } from './brand';
import { registerComponents, type ComponentRegistry } from './components';
import { readSettings } from './settings';
import { renderApp, renderFatalError } from './ui/template';

/** The element the Blade view renders for the app to take over. */
export const ROOT_SELECTOR = '[data-linear-app]';

/** The parts of Alpine `mount()` drives; the real `Alpine` satisfies this. */
export interface AlpineRuntime extends ComponentRegistry {
  start(): void;
}

export type MountResult = 'mounted' | 'missing-root' | 'invalid-settings';

/**
 * Boots the configuration page: reads and validates the embedded settings,
 * applies the brand colour, renders the UI into `[data-linear-app]` and starts
 * Alpine. Failures are reported in the page (or the console) rather than thrown.
 */
export function mount(root: Document = document, alpine: AlpineRuntime = Alpine): MountResult {
  const container = root.querySelector<HTMLElement>(ROOT_SELECTOR);

  if (container === null) {
    console.error(`[linear] No ${ROOT_SELECTOR} element found; the configuration UI was not started.`);
    return 'missing-root';
  }

  let settings;
  try {
    settings = readSettings(root);
  } catch (error) {
    console.error('[linear]', error);
    renderFatalError(container, errorMessage(error));
    return 'invalid-settings';
  }

  container.classList.add('linear-app');
  applyBrand(settings.brand, container);

  registerComponents(alpine, { settings, client: createClient(settings) });
  container.innerHTML = renderApp();

  window.Alpine = Alpine;
  alpine.start();

  return 'mounted';
}
