import { describe, expect, it } from 'vitest';
import {
  arrayOf,
  isBoolean,
  isNumber,
  isRecord,
  isString,
  literal,
  nullable,
  oneOf,
  recordOf,
  shape,
} from '../guards';

describe('primitive guards', () => {
  it('isRecord accepts plain objects only', () => {
    expect(isRecord({})).toBe(true);
    expect(isRecord({ a: 1 })).toBe(true);
    expect(isRecord(null)).toBe(false);
    expect(isRecord([])).toBe(false);
    expect(isRecord('text')).toBe(false);
    expect(isRecord(undefined)).toBe(false);
  });

  it('isString / isBoolean / isNumber check the primitive type', () => {
    expect(isString('')).toBe(true);
    expect(isString(1)).toBe(false);
    expect(isBoolean(false)).toBe(true);
    expect(isBoolean('false')).toBe(false);
    expect(isNumber(0)).toBe(true);
    expect(isNumber(Number.NaN)).toBe(false);
    expect(isNumber(Number.POSITIVE_INFINITY)).toBe(false);
    expect(isNumber('1')).toBe(false);
  });
});

describe('combinators', () => {
  it('nullable accepts null or the wrapped type', () => {
    const guard = nullable(isString);
    expect(guard(null)).toBe(true);
    expect(guard('a')).toBe(true);
    expect(guard(undefined)).toBe(false);
    expect(guard(1)).toBe(false);
  });

  it('arrayOf requires an array whose every item passes', () => {
    const guard = arrayOf(isNumber);
    expect(guard([])).toBe(true);
    expect(guard([1, 2])).toBe(true);
    expect(guard([1, 'x'])).toBe(false);
    expect(guard('nope')).toBe(false);
    expect(guard({ length: 0 })).toBe(false);
  });

  it('recordOf requires an object whose every value passes', () => {
    const guard = recordOf(arrayOf(isString));
    expect(guard({})).toBe(true);
    expect(guard({ a: ['x'], b: [] })).toBe(true);
    expect(guard({ a: 'x' })).toBe(false);
    expect(guard([])).toBe(false);
    expect(guard(null)).toBe(false);
  });

  it('oneOf accepts only the listed values', () => {
    const modes = oneOf(['a', 'b'] as const);
    const digits = oneOf([0, 1] as const);
    expect(modes('a')).toBe(true);
    expect(modes('c')).toBe(false);
    expect(digits(0)).toBe(true);
    expect(digits('0')).toBe(false);
  });

  it('literal accepts exactly one value', () => {
    const yes = literal(true);
    expect(yes(true)).toBe(true);
    expect(yes(false)).toBe(false);
    expect(yes('true')).toBe(false);
  });

  it('shape requires every key to pass its guard and ignores extras', () => {
    interface Point {
      x: number;
      label: string | null;
    }
    const isPoint = shape<Point>({ x: isNumber, label: nullable(isString) });

    expect(isPoint({ x: 1, label: null })).toBe(true);
    expect(isPoint({ x: 1, label: 'a', extra: true })).toBe(true);
    expect(isPoint({ x: 1 })).toBe(false);
    expect(isPoint({ x: '1', label: null })).toBe(false);
    expect(isPoint(null)).toBe(false);
    expect(isPoint([])).toBe(false);
  });
});
