/**
 * Leading visuals for the custom selects: team and project tiles, member
 * avatars, workflow status icons and priority icons.
 *
 * A visual is described by a small, typed `SelectVisual` object that the item
 * builders in `components/destination-form.ts` create from API data (through
 * the `*Visual()` builders below) and `selectTemplate()` renders. Everything
 * that comes from the API is checked here, once, so the template never has to
 * trust it:
 *
 *   - colours are normalised hex strings or `null` (never CSS from the server);
 *   - avatar URLs are `http(s)` URLs or `null`;
 *   - status types are narrowed to a closed `StatusKind` list;
 *   - the only HTML ever produced is the static SVG in this file, chosen by
 *     those closed enums. Names, initials and emoji reach the DOM through
 *     `x-text` bindings.
 *
 * Colours are applied as CSS custom properties (`--linear-visual-*`) so the
 * stylesheet decides how they combine with the light and dark themes.
 *
 * To add a new kind of visual, see "Adding a visual" in `docs/frontend.md`.
 */
import { normalizeHexColor, readableTextColor } from '../brand';
import type { Member, Priority, Project, Team, WorkflowState } from '../types';

/** Linear's workflow state types, plus a fallback for any type this page does not know. */
export const STATUS_KINDS = ['triage', 'backlog', 'unstarted', 'started', 'completed', 'canceled', 'duplicate'] as const;
export type StatusKind = (typeof STATUS_KINDS)[number];

/** Generic glyphs for the "none" choices and for items without styling. */
export const ICON_NAMES = ['user-empty', 'project', 'project-empty', 'status-empty'] as const;
export type IconName = (typeof ICON_NAMES)[number];

export interface AvatarVisual {
  readonly kind: 'avatar';
  /** An `http(s)` URL, or `null` to show the initials only. */
  readonly url: string | null;
  readonly initials: string;
  /** Normalised hex background for the initials, or `null` for the theme default. */
  readonly color: string | null;
}

export interface TileVisual {
  readonly kind: 'tile';
  /** An emoji, or one to three letters. */
  readonly glyph: string;
  /** `solid` fills the tile with `color`; `soft` tints it. */
  readonly tone: 'solid' | 'soft';
  /** Normalised hex colour, or `null` for the accent colour. */
  readonly color: string | null;
}

export interface StatusVisual {
  readonly kind: 'status';
  readonly status: StatusKind;
  readonly color: string | null;
}

export interface PriorityVisual {
  readonly kind: 'priority';
  readonly level: Priority;
}

export interface IconVisual {
  readonly kind: 'icon';
  readonly name: IconName;
  /** Normalised hex colour used to tint the glyph, or `null` for the muted default. */
  readonly color: string | null;
}

export type SelectVisual = AvatarVisual | TileVisual | StatusVisual | PriorityVisual | IconVisual;

// --- Validation -------------------------------------------------------------

/** A normalised hex colour, or `null` when `value` is missing or not a hex colour. */
export function safeColor(value: string | null | undefined): string | null {
  return value === null || value === undefined ? null : normalizeHexColor(value);
}

/** The URL when it is an absolute `http(s)` URL (so never `javascript:` or `data:`), otherwise `null`. */
export function safeImageUrl(value: string | null | undefined): string | null {
  if (value === null || value === undefined) {
    return null;
  }

  try {
    const { protocol } = new URL(value);
    return protocol === 'https:' || protocol === 'http:' ? value : null;
  } catch {
    return null;
  }
}

/** Narrows a workflow state `type` from the API; unknown types fall back to `unstarted` (an empty circle). */
export function statusKind(type: string): StatusKind {
  return STATUS_KINDS.find((kind) => kind === type) ?? 'unstarted';
}

// --- Emoji ------------------------------------------------------------------

/** One emoji: a flag, or a pictograph with optional skin tone / variation selectors, joined by ZWJs. */
const EMOJI =
  /^(?:\p{Regional_Indicator}{2}|\p{Extended_Pictographic}(?:[\u{1F3FB}-\u{1F3FF}]|\uFE0F)*(?:\u200D\p{Extended_Pictographic}(?:[\u{1F3FB}-\u{1F3FF}]|\uFE0F)*)*)/u;
const SHORTCODE = /^:([a-z0-9_+-]+):$/i;

/** The few shortcodes Linear has been seen to send for emoji icons. Anything else is treated as a name. */
const SHORTCODES: Readonly<Record<string, string>> = {
  rocket: '🚀',
  bug: '🐛',
  star: '⭐',
  fire: '🔥',
  zap: '⚡',
  bulb: '💡',
  dart: '🎯',
  books: '📚',
  book: '📖',
  bell: '🔔',
  lock: '🔒',
  key: '🔑',
  gear: '⚙️',
  wrench: '🔧',
  hammer: '🔨',
  package: '📦',
  inbox_tray: '📥',
  speech_balloon: '💬',
  sparkles: '✨',
  heart: '❤️',
  rainbow: '🌈',
  tada: '🎉',
  lifebuoy: '🛟',
  computer: '💻',
  iphone: '📱',
  chart_with_upwards_trend: '📈',
  white_check_mark: '✅',
};

/**
 * The emoji in a Linear `icon` value, or `null` when it is not an emoji.
 *
 * Linear's `icon` is a free string: an emoji ("🚀"), a legacy shortcode
 * (":rocket:"), or the name of one of its built-in icons ("Bug", "Rocket").
 * Only the first two produce a glyph; for a name this returns `null` and the
 * caller falls back to letters or a generic glyph. A leading emoji followed by
 * other text yields just that emoji.
 */
export function emojiOf(icon: string | null | undefined): string | null {
  const value = icon?.trim() ?? '';
  const shortcode = SHORTCODE.exec(value)?.[1];

  if (shortcode !== undefined) {
    return SHORTCODES[shortcode.toLowerCase()] ?? null;
  }

  return EMOJI.exec(value)?.[0] ?? null;
}

// --- Builders ---------------------------------------------------------------

/** Up to `max` leading characters, upper-cased. */
function letters(value: string, max: number): string {
  return Array.from(value.trim()).slice(0, max).join('').toUpperCase();
}

/** A team's colour tile: its emoji, or otherwise the first letters of its key. */
export function teamVisual(team: Pick<Team, 'key' | 'name' | 'color' | 'icon'>): TileVisual {
  const emoji = emojiOf(team.icon);

  return {
    kind: 'tile',
    glyph: emoji ?? (letters(team.key, 2) || letters(team.name, 1) || '?'),
    tone: 'solid',
    color: safeColor(team.color),
  };
}

/** A project's emoji on a soft tint of its colour, or otherwise a box glyph in its colour. */
export function projectVisual(project: Pick<Project, 'color' | 'icon'>): TileVisual | IconVisual {
  const color = safeColor(project.color);
  const emoji = emojiOf(project.icon);

  return emoji === null ? { kind: 'icon', name: 'project', color } : { kind: 'tile', glyph: emoji, tone: 'soft', color };
}

/** A member's avatar. The initials (from Linear, or derived from the name) show until, or without, the image. */
export function memberVisual(member: Pick<Member, 'name' | 'avatarUrl' | 'initials' | 'avatarBackgroundColor'>): AvatarVisual {
  const initials =
    letters(member.initials ?? '', 3) ||
    letters(
      member.name
        .split(/\s+/)
        .map((word) => word.charAt(0))
        .join(''),
      2,
    ) ||
    '?';

  return {
    kind: 'avatar',
    url: safeImageUrl(member.avatarUrl),
    initials,
    color: safeColor(member.avatarBackgroundColor),
  };
}

export function stateVisual(state: Pick<WorkflowState, 'type' | 'color'>): StatusVisual {
  return { kind: 'status', status: statusKind(state.type), color: safeColor(state.color) };
}

export function priorityVisual(level: Priority): PriorityVisual {
  return { kind: 'priority', level };
}

/** "Unassigned". */
export const UNASSIGNED_VISUAL: IconVisual = { kind: 'icon', name: 'user-empty', color: null };
/** "No project". */
export const NO_PROJECT_VISUAL: IconVisual = { kind: 'icon', name: 'project-empty', color: null };
/** "Team default" status. */
export const TEAM_DEFAULT_VISUAL: IconVisual = { kind: 'icon', name: 'status-empty', color: null };

// --- Rendering --------------------------------------------------------------

/** The inline style (CSS custom properties) that colours a visual; empty when the stylesheet defaults apply. */
export function visualStyle(visual: SelectVisual | null | undefined): Record<string, string> {
  switch (visual?.kind) {
    case 'avatar':
      return visual.color === null
        ? {}
        : { '--linear-visual-bg': visual.color, '--linear-visual-fg': readableTextColor(visual.color) };
    case 'tile':
      return visual.color === null
        ? {}
        : {
            '--linear-visual-bg': visual.color,
            '--linear-visual-fg': visual.tone === 'solid' ? readableTextColor(visual.color) : 'inherit',
          };
    case 'status':
      return visual.color === null
        ? {}
        : { '--linear-visual-color': visual.color, '--linear-visual-fg': readableTextColor(visual.color) };
    case 'icon':
      return visual.color === null ? {} : { '--linear-visual-color': visual.color };
    default:
      return {};
  }
}

/** The BEM modifiers for a visual's wrapper, for `:class`. */
export function visualClasses(visual: SelectVisual | null | undefined): string[] {
  if (visual === null || visual === undefined) {
    return [];
  }

  switch (visual.kind) {
    case 'tile':
      return ['linear-visual--tile', `linear-visual--${visual.tone}`];
    case 'status':
      return ['linear-visual--status', `linear-visual--status-${visual.status}`];
    default:
      return [`linear-visual--${visual.kind}`];
  }
}

const SVG_OPEN =
  '<svg class="linear-visual__svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false">';

const svg = (inner: string): string => `${SVG_OPEN}${inner}</svg>`;

const RING = '<circle cx="8" cy="8" r="6.25" fill="none" stroke="currentColor" stroke-width="1.5"/>';
const DISC = '<circle cx="8" cy="8" r="7" fill="currentColor"/>';

/** Linear-style status icons, tinted with `currentColor` (the state colour). */
export const STATUS_SVG: Readonly<Record<StatusKind, string>> = {
  triage: svg(
    `${RING}<path d="M8 4.75v4.5M6.1 7.5 8 9.4l1.9-1.9" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>`,
  ),
  backlog: svg(
    '<circle cx="8" cy="8" r="6.25" fill="none" stroke="currentColor" stroke-width="1.5" stroke-dasharray="1.9 2.4" stroke-linecap="round"/>',
  ),
  unstarted: svg(RING),
  started: svg(
    `${RING}<circle cx="8" cy="8" r="2.5" fill="none" stroke="currentColor" stroke-width="5" stroke-dasharray="7.85 15.7" transform="rotate(-90 8 8)"/>`,
  ),
  completed: svg(
    `${DISC}<path d="m5 8.2 2.2 2.2L11.2 5.9" fill="none" stroke="var(--linear-visual-fg, #fff)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>`,
  ),
  canceled: svg(
    `${DISC}<path d="m5.6 5.6 4.8 4.8m0-4.8-4.8 4.8" fill="none" stroke="var(--linear-visual-fg, #fff)" stroke-width="1.6" stroke-linecap="round"/>`,
  ),
  // Linear draws duplicates like cancellations; the grey colour comes from the workflow state.
  duplicate: svg(
    `${DISC}<path d="m5.6 5.6 4.8 4.8m0-4.8-4.8 4.8" fill="none" stroke="var(--linear-visual-fg, #fff)" stroke-width="1.6" stroke-linecap="round"/>`,
  ),
};

/** Generic glyphs; they use `currentColor`, which the stylesheet sets from `--linear-visual-color` or the muted text colour. */
export const ICON_SVG: Readonly<Record<IconName, string>> = {
  'user-empty': svg(
    '<circle cx="8" cy="8" r="6.9" fill="none" stroke="currentColor" stroke-width="1.2" stroke-dasharray="2 2.2" stroke-linecap="round"/><circle cx="8" cy="6.4" r="1.9" fill="none" stroke="currentColor" stroke-width="1.2"/><path d="M4.6 12.1c.5-1.5 1.8-2.3 3.4-2.3s2.9.8 3.4 2.3" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>',
  ),
  project: svg(
    '<path d="M8 1.9 13.2 4.8v6.4L8 14.1 2.8 11.2V4.8z" fill="currentColor" fill-opacity=".18" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><path d="M2.9 4.9 8 7.9l5.1-3M8 7.9v6" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>',
  ),
  'project-empty': svg(
    '<path d="M8 1.9 13.2 4.8v6.4L8 14.1 2.8 11.2V4.8z" fill="none" stroke="currentColor" stroke-width="1.3" stroke-dasharray="2.2 2" stroke-linejoin="round"/>',
  ),
  'status-empty': svg('<circle cx="8" cy="8" r="6.25" fill="none" stroke="currentColor" stroke-width="1.5"/>'),
};

const BARS: readonly { x: number; y: number; height: number }[] = [
  { x: 1.75, y: 9.5, height: 4.75 },
  { x: 6.5, y: 6.25, height: 8 },
  { x: 11.25, y: 3, height: 11.25 },
];

const BARS_FILLED: Readonly<Record<Priority, number>> = { 0: 0, 1: 3, 2: 3, 3: 2, 4: 1 };

function priorityMarkup(level: Priority): string {
  if (level === 0) {
    return svg(
      [1.75, 6.5, 11.25]
        .map((x) => `<rect class="linear-visual__bar linear-visual__bar--off" x="${x}" y="7.25" width="3" height="1.5" rx=".75"/>`)
        .join(''),
    );
  }

  if (level === 1) {
    return svg(
      '<rect class="linear-visual__urgent" x="1" y="1" width="14" height="14" rx="3.5"/><path d="M8 4.4v4.1" fill="none" stroke="#fff" stroke-width="1.7" stroke-linecap="round"/><circle cx="8" cy="11.3" r="1" fill="#fff"/>',
    );
  }

  const filled = BARS_FILLED[level];

  return svg(
    BARS.map(
      ({ x, y, height }, index) =>
        `<rect class="linear-visual__bar${index < filled ? '' : ' linear-visual__bar--off'}" x="${x}" y="${y}" width="3" height="${height}" rx=".75"/>`,
    ).join(''),
  );
}

/** Pre-rendered markup per priority level (static strings, no API data). */
export const PRIORITY_SVG: Readonly<Record<Priority, string>> = {
  0: priorityMarkup(0),
  1: priorityMarkup(1),
  2: priorityMarkup(2),
  3: priorityMarkup(3),
  4: priorityMarkup(4),
};

/**
 * The static SVG for visuals drawn as icons (status, priority, icon), for
 * `x-html`. It is picked from the tables above by a closed enum, so it never
 * contains API data. Avatars and tiles render as text and return `''`.
 */
export function visualMarkup(visual: SelectVisual | null | undefined): string {
  switch (visual?.kind) {
    case 'status':
      return STATUS_SVG[visual.status];
    case 'priority':
      return PRIORITY_SVG[visual.level];
    case 'icon':
      return ICON_SVG[visual.name];
    default:
      return '';
  }
}

/** The glyph or initials a text-based visual shows; `''` for the icon kinds. */
export function visualText(visual: SelectVisual | null | undefined): string {
  switch (visual?.kind) {
    case 'avatar':
      return visual.initials;
    case 'tile':
      return visual.glyph;
    default:
      return '';
  }
}

/**
 * `error` handler for avatar images: hides the broken image so the initials
 * underneath show. Used for a blocked host (a strict Content-Security-Policy),
 * an expired URL, or no network.
 */
export function hideBrokenImage(event: Event): void {
  if (event.target instanceof HTMLElement) {
    event.target.hidden = true;
  }
}
