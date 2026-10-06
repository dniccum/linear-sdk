import { describe, expect, it, vi } from 'vitest';
import { ApiError } from '../../api/errors';
import { emit, focusRef, LINEAR_EVENTS, reportReconnect } from '../../components/events';
import { captureEvents, makeHost, makeMagics } from '../fixtures';

describe('LINEAR_EVENTS', () => {
  it('uses dash-separated names without colons (safe for x-on attributes)', () => {
    for (const name of Object.values(LINEAR_EVENTS)) {
      expect(name).toMatch(/^linear-[a-z-]+$/);
    }
  });
});

describe('emit', () => {
  it('dispatches a bubbling CustomEvent carrying the detail', () => {
    const parent = document.createElement('div');
    const child = document.createElement('span');
    parent.append(child);
    const events = captureEvents(parent, [LINEAR_EVENTS.flash]);

    emit(child, LINEAR_EVENTS.flash, { kind: 'status', message: 'Saved' });

    expect(events).toEqual([{ name: 'linear-flash', detail: { kind: 'status', message: 'Saved' } }]);
  });
});

describe('reportReconnect', () => {
  it('emits reconnect-required for a reconnect ApiError', () => {
    const host = document.createElement('div');
    const events = captureEvents(host, [LINEAR_EVENTS.reconnectRequired]);

    const handled = reportReconnect(host, new ApiError('Reconnect please', { status: 409, reconnect: true }));

    expect(handled).toBe(true);
    expect(events).toEqual([{ name: 'linear-reconnect-required', detail: { message: 'Reconnect please' } }]);
  });

  it('ignores other ApiErrors and non-ApiErrors', () => {
    const host = document.createElement('div');
    const events = captureEvents(host, [LINEAR_EVENTS.reconnectRequired]);

    expect(reportReconnect(host, new ApiError('Nope', { status: 500 }))).toBe(false);
    expect(reportReconnect(host, new Error('Nope'))).toBe(false);
    expect(events).toEqual([]);
  });
});

describe('focusRef', () => {
  it('focuses the data-linear-ref element after the next tick', () => {
    const host = makeHost(['first', 'second']);
    const magics = makeMagics();

    focusRef(magics, host, 'second');

    expect(magics.$nextTick).toHaveBeenCalledTimes(1);
    expect(document.activeElement).toBe(host.querySelector('[data-linear-ref="second"]'));
  });

  it('does nothing when the element does not exist', () => {
    const host = makeHost();
    const before = document.activeElement;

    expect(() => focusRef(makeMagics(), host, 'missing')).not.toThrow();
    expect(document.activeElement).toBe(before);
  });

  it('waits for nextTick before looking the element up', () => {
    const host = makeHost(['late']);
    const callbacks: Array<() => void> = [];
    const magics = { $nextTick: vi.fn((callback?: () => void) => {
      if (callback) callbacks.push(callback);
      return Promise.resolve();
    }) };

    focusRef(magics, host, 'late');
    expect(document.activeElement).not.toBe(host.firstElementChild);

    callbacks.forEach((callback) => callback());
    expect(document.activeElement).toBe(host.firstElementChild);
  });
});
