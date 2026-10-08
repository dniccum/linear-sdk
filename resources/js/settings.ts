import { isSettings, withSettingsDefaults } from './schema';
import type { Settings } from './types';

/** Id of the `<script type="application/json">` element the Blade view renders. */
export const SETTINGS_ELEMENT_ID = 'linear-settings';

/** Raised when the embedded settings payload is missing, malformed or the wrong shape. */
export class SettingsError extends Error {
  constructor(message: string, options?: ErrorOptions) {
    super(message, options);
    this.name = 'SettingsError';
  }
}

/**
 * Reads and validates the settings JSON embedded in the page.
 *
 * @throws {SettingsError} when the element is missing, is not valid JSON, or
 *   does not match the `Settings` contract.
 */
export function readSettings(root: Document): Settings {
  const element = root.querySelector<HTMLScriptElement>(`script#${SETTINGS_ELEMENT_ID}`);

  if (element === null) {
    throw new SettingsError(`Missing <script id="${SETTINGS_ELEMENT_ID}" type="application/json"> element.`);
  }

  let parsed: unknown;
  try {
    parsed = withSettingsDefaults(JSON.parse(element.text));
  } catch (cause) {
    throw new SettingsError('The embedded Linear settings are not valid JSON.', { cause });
  }

  if (!isSettings(parsed)) {
    throw new SettingsError('The embedded Linear settings do not match the expected shape.');
  }

  return parsed;
}
