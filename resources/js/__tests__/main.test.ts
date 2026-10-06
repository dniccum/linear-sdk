import { afterEach, describe, expect, it, vi } from 'vitest';
import { jsonResponse, makeSettings } from './fixtures';

afterEach(() => {
  window.Alpine.stopObservingMutations();
});

describe('main', () => {
  it('boots the app on the current document when imported', async () => {
    vi.resetModules();
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ teams: [] })));
    const settings = makeSettings({ connection: null, destination: null, failures: [] });
    document.body.innerHTML = `<div data-linear-app></div><script type="application/json" id="linear-settings">${JSON.stringify(settings)}</script>`;

    await import('../main');

    await vi.waitFor(() => {
      expect(document.querySelector('.linear-header__title')?.textContent).toBe('Linear integration');
    });
    expect(document.querySelector('[data-linear-app]')?.classList.contains('linear-app')).toBe(true);
    expect(document.querySelector('.linear-badge')?.textContent).toBe('Not connected');
  });
});
