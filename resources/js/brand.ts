import type { Brand } from './types';

/** Linear's own indigo; used when the host does not configure a brand colour. */
export const DEFAULT_ACCENT = '#5E6AD2';

export const ACCENT_PROPERTY = '--linear-accent';
export const ACCENT_CONTRAST_PROPERTY = '--linear-accent-contrast';

const LIGHT_TEXT = '#ffffff';
const DARK_TEXT = '#111827';

const HEX_COLOR = /^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i;

/**
 * True for `#rgb`, `#rgba`, `#rrggbb` and `#rrggbbaa` colours (case-insensitive).
 * This mirrors the validation the PHP `BrandData` applies to `brand.color`.
 */
export function isValidHexColor(value: string): boolean {
  return HEX_COLOR.test(value);
}

/**
 * Normalises a valid hex colour to lowercase `#rrggbb` (or `#rrggbbaa`),
 * expanding the 3- and 4-digit shorthands. Returns `null` when the value is
 * not a hex colour.
 */
export function normalizeHexColor(value: string): string | null {
  if (!isValidHexColor(value)) {
    return null;
  }

  const digits = value.slice(1).toLowerCase();
  const full =
    digits.length <= 4
      ? digits
          .split('')
          .map((digit) => digit + digit)
          .join('')
      : digits;

  return `#${full}`;
}

/** WCAG relative luminance of a normalised hex colour (any alpha channel is ignored). */
function relativeLuminance(hex: string): number {
  const [red, green, blue] = [1, 3, 5].map((start) => {
    const channel = parseInt(hex.slice(start, start + 2), 16) / 255;
    return channel <= 0.03928 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
  }) as [number, number, number];

  return 0.2126 * red + 0.7152 * green + 0.0722 * blue;
}

function contrastRatio(first: number, second: number): number {
  const [lighter, darker] = first >= second ? [first, second] : [second, first];
  return (lighter + 0.05) / (darker + 0.05);
}

/** Picks white or near-black text, whichever is more legible on `hex`. */
export function readableTextColor(hex: string): string {
  const normalized = normalizeHexColor(hex);

  if (normalized === null) {
    return LIGHT_TEXT;
  }

  const background = relativeLuminance(normalized);

  return contrastRatio(background, relativeLuminance(LIGHT_TEXT)) >=
    contrastRatio(background, relativeLuminance(DARK_TEXT))
    ? LIGHT_TEXT
    : DARK_TEXT;
}

/**
 * Applies the brand accent colour as the `--linear-accent` custom property on
 * `target`. An invalid colour is ignored (and any previous override cleared),
 * so the stylesheet default stays in effect.
 *
 * @returns whether the brand colour was applied.
 */
export function applyBrand(brand: Pick<Brand, 'color'>, target: HTMLElement): boolean {
  const accent = normalizeHexColor(brand.color);

  if (accent === null) {
    target.style.removeProperty(ACCENT_PROPERTY);
    target.style.removeProperty(ACCENT_CONTRAST_PROPERTY);
    return false;
  }

  target.style.setProperty(ACCENT_PROPERTY, accent);
  target.style.setProperty(ACCENT_CONTRAST_PROPERTY, readableTextColor(accent));
  return true;
}
