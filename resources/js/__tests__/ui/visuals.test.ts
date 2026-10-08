import { describe, expect, it } from 'vitest';
import {
  emojiOf,
  hideBrokenImage,
  ICON_NAMES,
  ICON_SVG,
  memberVisual,
  NO_PROJECT_VISUAL,
  PRIORITY_SVG,
  priorityVisual,
  projectVisual,
  safeColor,
  safeImageUrl,
  STATUS_KINDS,
  STATUS_SVG,
  stateVisual,
  statusKind,
  teamVisual,
  TEAM_DEFAULT_VISUAL,
  UNASSIGNED_VISUAL,
  visualClasses,
  visualMarkup,
  visualStyle,
  visualText,
  type SelectVisual,
} from '../../ui/visuals';

describe('safeColor', () => {
  it('normalises hex colours and rejects everything else', () => {
    expect(safeColor('#ABC')).toBe('#aabbcc');
    expect(safeColor('#5E6AD2')).toBe('#5e6ad2');
    expect(safeColor('red')).toBeNull();
    expect(safeColor('#12')).toBeNull();
    expect(safeColor('url(javascript:alert(1))')).toBeNull();
    expect(safeColor(null)).toBeNull();
    expect(safeColor(undefined)).toBeNull();
  });
});

describe('safeImageUrl', () => {
  it('accepts only absolute http(s) URLs', () => {
    expect(safeImageUrl('https://public.linear.app/a.png')).toBe('https://public.linear.app/a.png');
    expect(safeImageUrl('http://localhost/a.png')).toBe('http://localhost/a.png');
    expect(safeImageUrl('javascript:alert(1)')).toBeNull();
    expect(safeImageUrl('data:image/svg+xml;base64,AAAA')).toBeNull();
    expect(safeImageUrl('/relative.png')).toBeNull();
    expect(safeImageUrl('not a url')).toBeNull();
    expect(safeImageUrl('')).toBeNull();
    expect(safeImageUrl(null)).toBeNull();
    expect(safeImageUrl(undefined)).toBeNull();
  });
});

describe('statusKind', () => {
  it('maps every known workflow state type to itself', () => {
    for (const kind of STATUS_KINDS) {
      expect(statusKind(kind)).toBe(kind);
    }
  });

  it('treats an unknown or empty type as a plain circle', () => {
    expect(statusKind('something-new')).toBe('unstarted');
    expect(statusKind('')).toBe('unstarted');
    expect(statusKind('STARTED')).toBe('unstarted');
  });
});

describe('emojiOf', () => {
  it.each([
    ['🚀', '🚀'],
    ['  🛟  ', '🛟'],
    ['👩‍💻', '👩‍💻'],
    ['🇳🇱', '🇳🇱'],
    ['🚀 launch', '🚀'],
    ['✅', '✅'],
    ['⭐', '⭐'],
  ])('finds the emoji in %j', (icon, expected) => {
    expect(emojiOf(icon)).toBe(expected);
  });

  it.each(['Bug', 'Rocket', '1', 'A🚀', '', '   ', ':not_a_known_code:', ':', '::'])('does not treat %j as an emoji', (icon) => {
    expect(emojiOf(icon)).toBeNull();
  });

  it('is null for a missing icon', () => {
    expect(emojiOf(null)).toBeNull();
    expect(emojiOf(undefined)).toBeNull();
  });

  it('resolves known shortcodes defensively, in any case', () => {
    expect(emojiOf(':rocket:')).toBe('🚀');
    expect(emojiOf(':ROCKET:')).toBe('🚀');
    expect(emojiOf(':chart_with_upwards_trend:')).toBe('📈');
  });
});

describe('teamVisual', () => {
  it('uses the emoji icon on a solid tile in the team colour', () => {
    expect(teamVisual({ key: 'SUP', name: 'Support', color: '#5E6AD2', icon: '🛟' })).toEqual({
      kind: 'tile',
      glyph: '🛟',
      tone: 'solid',
      color: '#5e6ad2',
    });
  });

  it('falls back to the first letters of the key for icon names, missing icons and unknown shortcodes', () => {
    expect(teamVisual({ key: 'eng', name: 'Engineering', color: null, icon: 'Bug' }).glyph).toBe('EN');
    expect(teamVisual({ key: 'D', name: 'Design', color: null, icon: null }).glyph).toBe('D');
    expect(teamVisual({ key: 'X', name: 'X', color: null, icon: ':unknown:' }).glyph).toBe('X');
  });

  it('uses the name, then a question mark, when there is no key', () => {
    expect(teamVisual({ key: '', name: 'design', color: null, icon: null }).glyph).toBe('D');
    expect(teamVisual({ key: ' ', name: '', color: null, icon: null }).glyph).toBe('?');
  });

  it('drops an invalid colour so the accent colour applies', () => {
    expect(teamVisual({ key: 'A', name: 'A', color: 'blue', icon: null }).color).toBeNull();
  });
});

describe('projectVisual', () => {
  it('shows an emoji on a soft tile in the project colour', () => {
    expect(projectVisual({ color: '#4cb782', icon: '📥' })).toEqual({ kind: 'tile', glyph: '📥', tone: 'soft', color: '#4cb782' });
  });

  it('shows a tinted box glyph for icon names and missing icons', () => {
    expect(projectVisual({ color: '#4cb782', icon: 'Mobile' })).toEqual({ kind: 'icon', name: 'project', color: '#4cb782' });
    expect(projectVisual({ color: 'nope', icon: null })).toEqual({ kind: 'icon', name: 'project', color: null });
  });
});

describe('memberVisual', () => {
  it('keeps a valid image URL, the initials and the background colour', () => {
    expect(
      memberVisual({ name: 'Ada Lovelace', avatarUrl: 'https://x.test/a.png', initials: 'al', avatarBackgroundColor: '#5E6AD2' }),
    ).toEqual({ kind: 'avatar', url: 'https://x.test/a.png', initials: 'AL', color: '#5e6ad2' });
  });

  it('derives initials from the name when Linear sends none', () => {
    const base = { avatarUrl: null, initials: null, avatarBackgroundColor: null };

    expect(memberVisual({ ...base, name: 'ada lovelace' }).initials).toBe('AL');
    expect(memberVisual({ ...base, name: 'Ada Augusta King Lovelace' }).initials).toBe('AA');
    expect(memberVisual({ ...base, name: 'grace' }).initials).toBe('G');
    expect(memberVisual({ ...base, name: 'grace', initials: '  ' }).initials).toBe('G');
    expect(memberVisual({ ...base, name: '   ' }).initials).toBe('?');
  });

  it('limits initials to three characters and drops unsafe URLs and colours', () => {
    const visual = memberVisual({ name: 'x', avatarUrl: 'javascript:alert(1)', initials: 'abcdef', avatarBackgroundColor: 'red' });

    expect(visual).toEqual({ kind: 'avatar', url: null, initials: 'ABC', color: null });
  });
});

describe('stateVisual, priorityVisual and the "none" visuals', () => {
  it('describes a workflow state', () => {
    expect(stateVisual({ type: 'completed', color: '#5E6AD2' })).toEqual({ kind: 'status', status: 'completed', color: '#5e6ad2' });
    expect(stateVisual({ type: 'weird', color: null })).toEqual({ kind: 'status', status: 'unstarted', color: null });
  });

  it('describes a priority level', () => {
    expect(priorityVisual(3)).toEqual({ kind: 'priority', level: 3 });
  });

  it('has muted generic glyphs for the empty choices', () => {
    expect(UNASSIGNED_VISUAL).toEqual({ kind: 'icon', name: 'user-empty', color: null });
    expect(NO_PROJECT_VISUAL).toEqual({ kind: 'icon', name: 'project-empty', color: null });
    expect(TEAM_DEFAULT_VISUAL).toEqual({ kind: 'icon', name: 'status-empty', color: null });
  });
});

describe('visualStyle', () => {
  it('sets background and a readable foreground for avatars and solid tiles', () => {
    expect(visualStyle({ kind: 'avatar', url: null, initials: 'A', color: '#ffffff' })).toEqual({
      '--linear-visual-bg': '#ffffff',
      '--linear-visual-fg': '#111827',
    });
    expect(visualStyle({ kind: 'tile', glyph: 'S', tone: 'solid', color: '#000000' })).toEqual({
      '--linear-visual-bg': '#000000',
      '--linear-visual-fg': '#ffffff',
    });
  });

  it('keeps the text colour for soft tiles so emoji are not recoloured', () => {
    expect(visualStyle({ kind: 'tile', glyph: '🚀', tone: 'soft', color: '#4cb782' })).toEqual({
      '--linear-visual-bg': '#4cb782',
      '--linear-visual-fg': 'inherit',
    });
  });

  it('sets the tint for statuses and icons', () => {
    expect(visualStyle({ kind: 'status', status: 'completed', color: '#5e6ad2' })).toEqual({
      '--linear-visual-color': '#5e6ad2',
      '--linear-visual-fg': '#ffffff',
    });
    expect(visualStyle({ kind: 'icon', name: 'project', color: '#4cb782' })).toEqual({ '--linear-visual-color': '#4cb782' });
  });

  it('leaves the stylesheet defaults when there is no colour, or nothing to colour', () => {
    const uncoloured: SelectVisual[] = [
      { kind: 'avatar', url: null, initials: 'A', color: null },
      { kind: 'tile', glyph: 'A', tone: 'solid', color: null },
      { kind: 'status', status: 'started', color: null },
      { kind: 'icon', name: 'project', color: null },
      { kind: 'priority', level: 2 },
    ];

    for (const visual of uncoloured) {
      expect(visualStyle(visual)).toEqual({});
    }
    expect(visualStyle(null)).toEqual({});
    expect(visualStyle(undefined)).toEqual({});
  });
});

describe('visualClasses', () => {
  it('names the kind, and the tone or status where it matters', () => {
    expect(visualClasses({ kind: 'tile', glyph: 'A', tone: 'soft', color: null })).toEqual(['linear-visual--tile', 'linear-visual--soft']);
    expect(visualClasses({ kind: 'status', status: 'canceled', color: null })).toEqual([
      'linear-visual--status',
      'linear-visual--status-canceled',
    ]);
    expect(visualClasses({ kind: 'avatar', url: null, initials: 'A', color: null })).toEqual(['linear-visual--avatar']);
    expect(visualClasses({ kind: 'priority', level: 0 })).toEqual(['linear-visual--priority']);
    expect(visualClasses({ kind: 'icon', name: 'project', color: null })).toEqual(['linear-visual--icon']);
    expect(visualClasses(null)).toEqual([]);
    expect(visualClasses(undefined)).toEqual([]);
  });
});

describe('visualText', () => {
  it('returns the initials or glyph of text visuals and nothing otherwise', () => {
    expect(visualText({ kind: 'avatar', url: null, initials: 'AL', color: null })).toBe('AL');
    expect(visualText({ kind: 'tile', glyph: '🚀', tone: 'soft', color: null })).toBe('🚀');
    expect(visualText({ kind: 'status', status: 'started', color: null })).toBe('');
    expect(visualText(null)).toBe('');
  });
});

describe('visualMarkup', () => {
  it('has a distinct static SVG for every status kind', () => {
    for (const kind of STATUS_KINDS) {
      const markup = visualMarkup({ kind: 'status', status: kind, color: null });

      expect(markup).toBe(STATUS_SVG[kind]);
      expect(markup.startsWith('<svg')).toBe(true);
      expect(markup).toContain('aria-hidden="true"');
    }

    expect(new Set(STATUS_KINDS.filter((kind) => kind !== 'duplicate').map((kind) => STATUS_SVG[kind])).size).toBe(6);
    expect(STATUS_SVG.backlog).toContain('stroke-dasharray');
    expect(STATUS_SVG.started).toContain('rotate(-90');
    expect(STATUS_SVG.completed).toContain('<path');
    expect(STATUS_SVG.canceled).toBe(STATUS_SVG.duplicate);
  });

  it('draws every generic icon', () => {
    for (const name of ICON_NAMES) {
      expect(visualMarkup({ kind: 'icon', name, color: null })).toBe(ICON_SVG[name]);
    }
  });

  it('draws priorities: dashes, an urgent square, and 3/2/1 filled bars', () => {
    const filled = (level: 2 | 3 | 4): number => (PRIORITY_SVG[level].match(/class="linear-visual__bar"/g) ?? []).length;

    expect(PRIORITY_SVG[0].match(/linear-visual__bar--off/g)).toHaveLength(3);
    expect(PRIORITY_SVG[1]).toContain('linear-visual__urgent');
    expect([filled(2), filled(3), filled(4)]).toEqual([3, 2, 1]);
    expect(visualMarkup({ kind: 'priority', level: 4 })).toBe(PRIORITY_SVG[4]);
  });

  it('is empty for text visuals and for nothing', () => {
    expect(visualMarkup({ kind: 'avatar', url: null, initials: 'A', color: null })).toBe('');
    expect(visualMarkup({ kind: 'tile', glyph: 'A', tone: 'solid', color: null })).toBe('');
    expect(visualMarkup(null)).toBe('');
  });

  it('never contains script or event handler attributes', () => {
    const everything = [...Object.values(STATUS_SVG), ...Object.values(ICON_SVG), ...Object.values(PRIORITY_SVG)].join('');

    expect(everything).not.toMatch(/<script|\son\w+=/i);
  });
});

describe('hideBrokenImage', () => {
  it('hides the failing image so the initials underneath show', () => {
    const image = document.createElement('img');
    image.addEventListener('error', hideBrokenImage);

    image.dispatchEvent(new Event('error'));

    expect(image.hidden).toBe(true);
  });

  it('ignores events that do not come from an element', () => {
    const event = new Event('error');

    expect(() => {
      hideBrokenImage(event);
    }).not.toThrow();
  });
});
