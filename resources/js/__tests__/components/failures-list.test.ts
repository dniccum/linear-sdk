import { describe, expect, it, vi } from 'vitest';
import { ApiError } from '../../api/errors';
import { failuresList } from '../../components/failures-list';
import { LINEAR_EVENTS } from '../../components/events';
import { captureEvents, deferred, makeFailure, makeHost, withMagics } from '../fixtures';

const EVENT_NAMES = Object.values(LINEAR_EVENTS);

function setup(retryIssue = vi.fn<(linkId: string) => Promise<void>>().mockResolvedValue(undefined)) {
  const host = makeHost(['heading']);
  const events = captureEvents(host, EVENT_NAMES);
  const list = withMagics(failuresList({ host, client: { retryIssue } }));
  return { host, events, list, retryIssue };
}

describe('labels', () => {
  it('names the failure kinds', () => {
    const { list } = setup();

    expect(list.kindLabel('issue')).toBe('Issue');
    expect(list.kindLabel('comment')).toBe('Comment');
  });

  it('pluralises attempts', () => {
    const { list } = setup();

    expect(list.attemptsLabel(1)).toBe('1 attempt');
    expect(list.attemptsLabel(4)).toBe('4 attempts');
  });

  it('returns the stored error for an id, or an empty string', () => {
    const { list } = setup();
    list.errors['f1'] = 'Nope';

    expect(list.errorFor('f1')).toBe('Nope');
    expect(list.errorFor('f2')).toBe('');
  });
});

describe('retry', () => {
  it('removes the failure optimistically, calls the API and announces success', async () => {
    const pending = deferred<void>();
    const { host, events, list, retryIssue } = setup(vi.fn(() => pending.promise));
    const failure = makeFailure({ id: 'f1', linkId: 'link-9', subject: 'Checkout broke' });

    const retrying = list.retry(failure, 0);

    // Removed before the request settles.
    expect(events.map((event) => event.name)).toEqual(['linear-failure-removed']);
    expect(events[0]?.detail).toEqual({ id: 'f1' });
    expect(retryIssue).toHaveBeenCalledWith('link-9');
    expect(document.activeElement).toBe(host.querySelector('[data-linear-ref="heading"]'));

    pending.resolve();
    await retrying;

    expect(events.map((event) => event.name)).toEqual(['linear-failure-removed', 'linear-flash']);
    expect(events[1]?.detail).toEqual({ kind: 'status', message: 'Retry requested for "Checkout broke".' });
    expect(list.errors).toEqual({});
  });

  it('restores the failure and records the error when the API call fails', async () => {
    const { events, list } = setup(vi.fn().mockRejectedValue(new ApiError('Linear is down', { status: 503 })));
    const failure = makeFailure({ id: 'f1' });
    list.errors['f1'] = 'previous error';

    await list.retry(failure, 2);

    expect(events.map((event) => event.name)).toEqual(['linear-failure-removed', 'linear-failure-restored']);
    expect(events[1]?.detail).toEqual({ failure, index: 2 });
    expect(list.errors['f1']).toBe('Linear is down');
  });

  it('clears a previous error as soon as a new retry starts', async () => {
    const pending = deferred<void>();
    const { list } = setup(vi.fn(() => pending.promise));
    list.errors['f1'] = 'previous error';

    const retrying = list.retry(makeFailure({ id: 'f1' }), 0);

    expect(list.errors).toEqual({});
    pending.resolve();
    await retrying;
  });

  it('also asks to reconnect when the failure is a reconnect response', async () => {
    const { events, list } = setup(
      vi.fn().mockRejectedValue(new ApiError('Reconnect Linear.', { status: 409, reconnect: true })),
    );

    await list.retry(makeFailure(), 0);

    expect(events.map((event) => event.name)).toEqual([
      'linear-failure-removed',
      'linear-failure-restored',
      'linear-reconnect-required',
    ]);
    expect(events[2]?.detail).toEqual({ message: 'Reconnect Linear.' });
  });

  it('uses a generic message for non-API errors', async () => {
    const { list } = setup(vi.fn().mockRejectedValue('weird'));

    await list.retry(makeFailure({ id: 'f1' }), 0);

    expect(list.errors['f1']).toBe('Something went wrong. Please try again.');
  });
});
