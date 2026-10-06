import { errorMessage } from '../api/errors';
import type { LinearClient } from '../api/client';
import { pluralize } from '../format';
import type { AlpineMagics, FailureItem, FailureKind } from '../types';
import { emit, focusRef, LINEAR_EVENTS, reportReconnect } from './events';

export interface FailuresListDeps {
  /** The component's own `x-data` element; events are dispatched from it. */
  host: HTMLElement;
  client: Pick<LinearClient, 'retryIssue'>;
}

/**
 * Retry behaviour for the recent-failures card (`x-data="failuresList($el)"`).
 *
 * The list itself lives on the root `linearApp`. A retry removes the item
 * optimistically (`linear-failure-removed`) and puts it back
 * (`linear-failure-restored`) with an inline error if the request fails.
 */
export interface FailuresListComponent {
  /** Retry error messages by failure id. */
  errors: Record<string, string>;

  kindLabel(kind: FailureKind): string;
  attemptsLabel(attempts: number): string;
  errorFor(id: string): string;
  retry(failure: FailureItem, index: number): Promise<void>;
}

export function failuresList({ host, client }: FailuresListDeps): FailuresListComponent {
  const component: FailuresListComponent & ThisType<FailuresListComponent & AlpineMagics> = {
    errors: {},

    kindLabel(kind) {
      return kind === 'issue' ? 'Issue' : 'Comment';
    },

    attemptsLabel(attempts) {
      return pluralize(attempts, 'attempt');
    },

    errorFor(id) {
      return this.errors[id] ?? '';
    },

    async retry(failure, index) {
      delete this.errors[failure.id];
      emit(host, LINEAR_EVENTS.failureRemoved, { id: failure.id });
      focusRef(this, host, 'heading');

      try {
        await client.retryIssue(failure.linkId);
        emit(host, LINEAR_EVENTS.flash, { kind: 'status', message: `Retry requested for "${failure.subject}".` });
      } catch (error) {
        emit(host, LINEAR_EVENTS.failureRestored, { failure, index });
        this.errors[failure.id] = errorMessage(error);
        reportReconnect(host, error);
      }
    },
  };

  return component;
}
