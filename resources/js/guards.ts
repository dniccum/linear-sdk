/**
 * Tiny runtime type-guard combinators.
 *
 * The server payloads are typed by `types/index.ts`, but TypeScript types are
 * erased at runtime. These combinators let `schema.ts` describe the same shapes
 * once, get a compile-time check that the guard matches the interface, and
 * validate untrusted JSON at the boundary.
 */

export type Guard<T> = (value: unknown) => value is T;

/** A guard per key of `T`; every key is required (use `nullable` for `null`). */
export type Shape<T> = { [K in keyof T]-?: Guard<T[K]> };

export type UnknownRecord = Record<string, unknown>;

export function isRecord(value: unknown): value is UnknownRecord {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export function isString(value: unknown): value is string {
  return typeof value === 'string';
}

export function isBoolean(value: unknown): value is boolean {
  return typeof value === 'boolean';
}

export function isNumber(value: unknown): value is number {
  return typeof value === 'number' && Number.isFinite(value);
}

/** Accepts `null` in addition to whatever `guard` accepts. */
export function nullable<T>(guard: Guard<T>): Guard<T | null> {
  return (value: unknown): value is T | null => value === null || guard(value);
}

export function arrayOf<T>(guard: Guard<T>): Guard<T[]> {
  return (value: unknown): value is T[] => Array.isArray(value) && value.every((item) => guard(item));
}

/** A string-keyed object whose every value satisfies `guard`. */
export function recordOf<T>(guard: Guard<T>): Guard<Record<string, T>> {
  return (value: unknown): value is Record<string, T> =>
    isRecord(value) && Object.values(value).every((item) => guard(item));
}

export function oneOf<T extends string | number>(allowed: readonly T[]): Guard<T> {
  return (value: unknown): value is T => allowed.some((candidate) => candidate === value);
}

export function literal<T extends string | number | boolean>(expected: T): Guard<T> {
  return (value: unknown): value is T => value === expected;
}

/** An object that has (at least) every key in `spec`, each passing its guard. */
export function shape<T extends object>(spec: Shape<T>): Guard<T> {
  const entries = Object.entries<Guard<unknown>>(spec as Record<string, Guard<unknown>>);
  return (value: unknown): value is T => isRecord(value) && entries.every(([key, guard]) => guard(value[key]));
}
