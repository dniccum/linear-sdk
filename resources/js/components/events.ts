/**
 * Child components never reach into the root `linearApp` state. They dispatch
 * these bubbling DOM events from their own host element and the root listens
 * for them (`x-on:...` in `ui/template.ts`), so every cross-component
 * interaction is explicit and typed.
 */
import { isApiError } from '../api/errors';
import type { AlpineMagics, LinearEventMap } from '../types';

export const LINEAR_EVENTS = {
  flash: 'linear-flash',
  destinationChanged: 'linear-destination-changed',
  reconnectRequired: 'linear-reconnect-required',
  failureRemoved: 'linear-failure-removed',
  failureRestored: 'linear-failure-restored',
} as const satisfies Record<string, keyof LinearEventMap>;

/** Dispatches a typed, bubbling `CustomEvent` from `host`. */
export function emit<K extends keyof LinearEventMap>(host: EventTarget, name: K, detail: LinearEventMap[K]): void {
  host.dispatchEvent(new CustomEvent(name, { detail, bubbles: true }));
}

/**
 * Tells the root the Linear connection needs re-authorising when `error` is a
 * 409 `reconnect` response.
 *
 * @returns whether the error was a reconnect request.
 */
export function reportReconnect(host: EventTarget, error: unknown): boolean {
  if (isApiError(error) && error.reconnect) {
    emit(host, LINEAR_EVENTS.reconnectRequired, { message: error.message });
    return true;
  }

  return false;
}

/**
 * Moves keyboard focus to the `data-linear-ref="name"` element inside `host`
 * once Alpine has finished updating the DOM.
 */
export function focusRef(magics: Pick<AlpineMagics, '$nextTick'>, host: ParentNode, name: string): void {
  void magics.$nextTick(() => {
    host.querySelector<HTMLElement>(`[data-linear-ref="${name}"]`)?.focus();
  });
}
