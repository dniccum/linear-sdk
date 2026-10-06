/**
 * Formats an ISO 8601 timestamp for display in the viewer's locale.
 * Returns an empty string for `null` and the raw input when it is not a date.
 */
export function formatDateTime(iso: string | null, locale?: string): string {
  if (iso === null) {
    return '';
  }

  const date = new Date(iso);

  if (Number.isNaN(date.getTime())) {
    return iso;
  }

  return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
}

/** `pluralize(1, 'attempt')` -> `1 attempt`; `pluralize(3, 'attempt')` -> `3 attempts`. */
export function pluralize(count: number, singular: string): string {
  return `${count} ${singular}${count === 1 ? '' : 's'}`;
}
