import { describe, expect, it } from 'vitest';
import {
  ACCENT_CONTRAST_PROPERTY,
  ACCENT_PROPERTY,
  applyBrand,
  isValidHexColor,
  normalizeHexColor,
  readableTextColor,
} from '../brand';

describe('isValidHexColor', () => {
  it.each(['#fff', '#FFF', '#fff8', '#5E6AD2', '#5e6ad2', '#000000', '#5e6ad2cc', '#5E6AD2CC'])('accepts %s', (value) => {
    expect(isValidHexColor(value)).toBe(true);
  });

  it.each(['', 'fff', '#ff', '#12345', '#1234567', '#123456789', '#ggg', 'red', 'rgb(0,0,0)', ' #fff'])(
    'rejects %j',
    (value) => {
      expect(isValidHexColor(value)).toBe(false);
    },
  );
});

describe('normalizeHexColor', () => {
  it('lowercases six-digit colours', () => {
    expect(normalizeHexColor('#5E6AD2')).toBe('#5e6ad2');
  });

  it('expands three-digit shorthand', () => {
    expect(normalizeHexColor('#AbC')).toBe('#aabbcc');
  });

  it('keeps and expands alpha channels', () => {
    expect(normalizeHexColor('#5E6AD2CC')).toBe('#5e6ad2cc');
    expect(normalizeHexColor('#AbCd')).toBe('#aabbccdd');
  });

  it('returns null for anything that is not a hex colour', () => {
    expect(normalizeHexColor('blue')).toBeNull();
  });
});

describe('readableTextColor', () => {
  it('uses white text on dark backgrounds', () => {
    expect(readableTextColor('#000000')).toBe('#ffffff');
    expect(readableTextColor('#5E6AD2')).toBe('#ffffff');
  });

  it('uses dark text on light backgrounds', () => {
    expect(readableTextColor('#ffffff')).toBe('#111827');
    expect(readableTextColor('#fde68a')).toBe('#111827');
  });

  it('ignores the alpha channel', () => {
    expect(readableTextColor('#ffffff00')).toBe('#111827');
    expect(readableTextColor('#000000ff')).toBe('#ffffff');
  });

  it('falls back to white for invalid input', () => {
    expect(readableTextColor('nope')).toBe('#ffffff');
  });

  it('handles both sides of the sRGB linearisation threshold', () => {
    // #010101 has channels below the 0.03928 cut-off, #808080 above it.
    expect(readableTextColor('#010101')).toBe('#ffffff');
    expect(readableTextColor('#808080')).toBe('#111827');
  });
});

describe('applyBrand', () => {
  it('sets the accent and a readable contrast colour on the target', () => {
    const target = document.createElement('div');

    expect(applyBrand({ color: '#FFF' }, target)).toBe(true);

    expect(target.style.getPropertyValue(ACCENT_PROPERTY)).toBe('#ffffff');
    expect(target.style.getPropertyValue(ACCENT_CONTRAST_PROPERTY)).toBe('#111827');
  });

  it('accepts colours with an alpha channel', () => {
    const target = document.createElement('div');

    expect(applyBrand({ color: '#5E6AD2CC' }, target)).toBe(true);

    expect(target.style.getPropertyValue(ACCENT_PROPERTY)).toBe('#5e6ad2cc');
  });

  it('ignores an invalid colour and clears earlier overrides', () => {
    const target = document.createElement('div');
    applyBrand({ color: '#e5484d' }, target);

    expect(applyBrand({ color: 'javascript:alert(1)' }, target)).toBe(false);

    expect(target.style.getPropertyValue(ACCENT_PROPERTY)).toBe('');
    expect(target.style.getPropertyValue(ACCENT_CONTRAST_PROPERTY)).toBe('');
  });
});
