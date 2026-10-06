import { describe, expect, it } from 'vitest';
import { formatDateTime, pluralize } from '../format';

describe('formatDateTime', () => {
  it('returns an empty string for null', () => {
    expect(formatDateTime(null)).toBe('');
  });

  it('returns the raw value when it is not a date', () => {
    expect(formatDateTime('yesterday-ish')).toBe('yesterday-ish');
  });

  it('formats valid timestamps in the requested locale', () => {
    const iso = '2026-01-15T10:30:00Z';
    const expected = new Intl.DateTimeFormat('en-US', { dateStyle: 'medium', timeStyle: 'short' }).format(
      new Date(iso),
    );

    expect(formatDateTime(iso, 'en-US')).toBe(expected);
  });

  it('uses the default locale when none is given', () => {
    const iso = '2026-01-15T10:30:00Z';
    const expected = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(
      new Date(iso),
    );

    expect(formatDateTime(iso)).toBe(expected);
  });
});

describe('pluralize', () => {
  it('uses the singular for exactly one', () => {
    expect(pluralize(1, 'attempt')).toBe('1 attempt');
  });

  it('uses the plural otherwise', () => {
    expect(pluralize(0, 'attempt')).toBe('0 attempts');
    expect(pluralize(3, 'attempt')).toBe('3 attempts');
  });
});
