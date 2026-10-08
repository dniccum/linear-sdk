import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, ROOT_SELECTOR, type AlpineRuntime } from '../mount';
import { SETTINGS_ELEMENT_ID } from '../settings';
import { makeSettings } from './fixtures';

function fakeAlpine() {
  return { data: vi.fn<AlpineRuntime['data']>(), start: vi.fn<AlpineRuntime['start']>() };
}

function renderPage(settingsJson: string | null, withRoot = true): HTMLElement | null {
  document.body.innerHTML = '';

  if (settingsJson !== null) {
    const script = document.createElement('script');
    script.type = 'application/json';
    script.id = SETTINGS_ELEMENT_ID;
    script.textContent = settingsJson;
    document.body.append(script);
  }

  if (!withRoot) {
    return null;
  }

  const root = document.createElement('div');
  root.setAttribute('data-linear-app', '');
  document.body.append(root);
  return root;
}

beforeEach(() => {
  vi.spyOn(console, 'error').mockImplementation(() => undefined);
});

afterEach(() => {
  document.body.innerHTML = '';
});

describe('mount', () => {
  it('exposes the root selector the Blade view renders', () => {
    expect(ROOT_SELECTOR).toBe('[data-linear-app]');
  });

  it('renders the UI, registers components and starts Alpine', () => {
    const settings = makeSettings({ brand: { name: 'Acme', logo: null, color: '#e5484d' } });
    const root = renderPage(JSON.stringify(settings));
    const alpine = fakeAlpine();

    const result = mount(document, alpine);

    expect(result).toBe('mounted');
    expect(root?.classList.contains('linear-app')).toBe(true);
    expect(root?.querySelector('.linear-shell')?.getAttribute('x-data')).toBe('linearApp()');
    expect(alpine.data.mock.calls.map(([name]) => name).sort()).toEqual([
      'connectionCard',
      'destinationForm',
      'failuresList',
      'linearApp',
      'linearSelect',
    ]);
    expect(alpine.start).toHaveBeenCalledTimes(1);
    expect(window.Alpine).toBeDefined();
  });

  it('applies the brand colour to the app element', () => {
    const settings = makeSettings({ brand: { name: 'Acme', logo: null, color: '#E5484D' } });
    const root = renderPage(JSON.stringify(settings));

    mount(document, fakeAlpine());

    expect(root?.style.getPropertyValue('--linear-accent')).toBe('#e5484d');
  });

  it('leaves the default accent when the brand colour is invalid', () => {
    const settings = makeSettings({ brand: { name: 'Acme', logo: null, color: 'not-a-colour' } });
    const root = renderPage(JSON.stringify(settings));

    expect(mount(document, fakeAlpine())).toBe('mounted');

    expect(root?.style.getPropertyValue('--linear-accent')).toBe('');
  });

  it('binds registered components to the settings', () => {
    const settings = makeSettings({ csrf: 'abc' });
    renderPage(JSON.stringify(settings));
    const alpine = fakeAlpine();

    mount(document, alpine);

    const factory = alpine.data.mock.calls.find(([name]) => name === 'linearApp')?.[1] as
      | (() => { settings: { csrf: string } })
      | undefined;
    expect(factory?.().settings.csrf).toBe('abc');
  });

  it('reports a missing root element without throwing', () => {
    renderPage(JSON.stringify(makeSettings()), false);
    const alpine = fakeAlpine();

    expect(mount(document, alpine)).toBe('missing-root');

    expect(alpine.start).not.toHaveBeenCalled();
    expect(console.error).toHaveBeenCalledWith(expect.stringContaining('[data-linear-app]'));
  });

  describe.each([
    ['the settings element is missing', null, /Missing <script id="linear-settings"/],
    ['the settings are not valid JSON', '{oops', /not valid JSON/],
    ['the settings have the wrong shape', '{"configured":true}', /expected shape/],
  ])('when %s', (_name, json, message) => {
    it('shows a friendly error in the page and does not start Alpine', () => {
      const root = renderPage(json);
      const alpine = fakeAlpine();

      expect(mount(document, alpine)).toBe('invalid-settings');

      expect(alpine.start).not.toHaveBeenCalled();
      expect(alpine.data).not.toHaveBeenCalled();
      expect(root?.querySelector('[role="alert"]')).not.toBeNull();
      expect(root?.textContent).toMatch(message);
      expect(root?.textContent).toMatch(/could not start/);
      expect(console.error).toHaveBeenCalled();
    });
  });
});
