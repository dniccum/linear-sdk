import { afterEach, describe, expect, it } from 'vitest';
import { readSettings, SettingsError, SETTINGS_ELEMENT_ID } from '../settings';
import { makeSettings } from './fixtures';

function renderPayload(text: string): void {
  document.body.innerHTML = `<script type="application/json" id="${SETTINGS_ELEMENT_ID}">${text}</script>`;
}

afterEach(() => {
  document.body.innerHTML = '';
});

describe('readSettings', () => {
  it('returns the parsed payload when it is valid', () => {
    const settings = makeSettings();
    renderPayload(JSON.stringify(settings));

    expect(readSettings(document)).toEqual(settings);
  });

  it('treats a missing urls.login as no login redirect', () => {
    const settings = makeSettings();
    const urls: Partial<typeof settings.urls> = { ...settings.urls };
    delete urls.login;
    renderPayload(JSON.stringify({ ...settings, urls }));

    expect(readSettings(document)).toEqual({ ...settings, urls: { ...settings.urls, login: '' } });
  });

  it('keeps a configured login URL', () => {
    renderPayload(JSON.stringify(makeSettings({ urls: { ...makeSettings().urls, login: 'https://example.com/login' } })));

    expect(readSettings(document).urls.login).toBe('https://example.com/login');
  });

  it('throws a SettingsError when the element is missing', () => {
    expect(() => readSettings(document)).toThrow(SettingsError);
    expect(() => readSettings(document)).toThrow(/Missing <script id="linear-settings"/);
  });

  it('throws a SettingsError (with the cause) when the JSON is invalid', () => {
    renderPayload('{not json');

    const error = captureError(() => readSettings(document));

    expect(error).toBeInstanceOf(SettingsError);
    expect(error.message).toMatch(/not valid JSON/);
    expect(error.cause).toBeInstanceOf(SyntaxError);
    expect(error.name).toBe('SettingsError');
  });

  it('throws a SettingsError when the JSON has the wrong shape', () => {
    renderPayload(JSON.stringify({ configured: true }));

    expect(() => readSettings(document)).toThrow(/do not match the expected shape/);
  });
});

function captureError(callback: () => unknown): Error {
  try {
    callback();
  } catch (error) {
    if (error instanceof Error) {
      return error;
    }
  }

  throw new Error('Expected the callback to throw.');
}
