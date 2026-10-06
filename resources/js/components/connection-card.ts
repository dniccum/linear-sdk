import type { AlpineMagics } from '../types';
import { focusRef } from './events';

export const API_KEY_REQUIRED_MESSAGE = 'Enter your Linear API key.';

/**
 * Local UI state of the connection card (`x-data="connectionCard($el)"`).
 *
 * Connecting and disconnecting are plain HTML form posts (with the CSRF token
 * and, for disconnect, a `_method=DELETE` field) so they work as full-page
 * navigations; this component only handles confirmation and double-submit
 * protection. What the card shows comes from the root `linearApp` state.
 */
export interface ConnectionCardComponent {
  apiKey: string;
  apiKeyError: string | null;
  submitting: boolean;
  confirmingDisconnect: boolean;

  askDisconnect(): void;
  cancelDisconnect(): void;
  submitDisconnect(): void;
  submitApiKey(event: Event): void;
}

export function connectionCard(host: HTMLElement): ConnectionCardComponent {
  const component: ConnectionCardComponent & ThisType<ConnectionCardComponent & AlpineMagics> = {
    apiKey: '',
    apiKeyError: null,
    submitting: false,
    confirmingDisconnect: false,

    askDisconnect() {
      this.confirmingDisconnect = true;
      focusRef(this, host, 'confirmDisconnect');
    },

    cancelDisconnect() {
      this.confirmingDisconnect = false;
      focusRef(this, host, 'disconnect');
    },

    submitDisconnect() {
      this.submitting = true;
    },

    submitApiKey(event) {
      if (this.apiKey.trim() === '') {
        event.preventDefault();
        this.apiKeyError = API_KEY_REQUIRED_MESSAGE;
        focusRef(this, host, 'apiKey');
        return;
      }

      this.apiKeyError = null;
      this.submitting = true;
    },
  };

  return component;
}
