import { describe, expect, it, vi } from 'vitest';
import {
  linearSelect,
  optionId,
  SELECT_MAX_HEIGHT,
  SELECT_OPENED_EVENT,
  TYPEAHEAD_RESET_MS,
  type SelectComponent,
  type SelectConfig,
  type SelectItem,
  type SelectValue,
} from '../../components/select';
import { withMagics } from '../fixtures';

const FRUIT: readonly SelectItem[] = [
  { value: '', label: 'No fruit' },
  { value: 'apple', label: 'Apple' },
  { value: 'apricot', label: 'Apricot' },
  { value: 'banana', label: 'Banana', disabled: true },
  { value: 'cherry', label: 'Cherry' },
];

interface Setup {
  host: HTMLElement;
  trigger: HTMLButtonElement;
  content: HTMLElement;
  select: SelectComponent;
  state: { value: SelectValue; items: readonly SelectItem[]; disabled: boolean };
  onSelect: ReturnType<typeof vi.fn<(value: SelectValue) => void>>;
}

/** A host with the same trigger/content/option structure `selectTemplate()` renders. */
function setup(overrides: Partial<SelectConfig> = {}, items: readonly SelectItem[] = FRUIT, value: SelectValue = ''): Setup {
  const host = document.createElement('div');
  const trigger = document.createElement('button');
  const content = document.createElement('div');
  trigger.className = 'linear-select__trigger';
  content.className = 'linear-select__content';
  host.append(trigger, content);
  document.body.append(host);

  const state = { value, items, disabled: false };
  const onSelect = vi.fn<(value: SelectValue) => void>();
  const config: SelectConfig = {
    id: 'fruit',
    value: () => state.value,
    items: () => state.items,
    onSelect,
    disabled: () => state.disabled,
    ...overrides,
  };

  const select = withMagics(linearSelect(host, config));
  renderOptions(content, items.length);

  return { host, trigger, content, select, state, onSelect };
}

function renderOptions(content: HTMLElement, count: number): void {
  content.replaceChildren(
    ...Array.from({ length: count }, () => {
      const option = document.createElement('div');
      option.setAttribute('role', 'option');
      return option;
    }),
  );
}

function press(select: SelectComponent, key: string, init: KeyboardEventInit = {}): KeyboardEvent {
  const event = new KeyboardEvent('keydown', { key, cancelable: true, bubbles: true, ...init });
  select.onKeydown(event);
  return event;
}

function defineLayout(element: HTMLElement, layout: Record<string, number>): void {
  for (const [name, value] of Object.entries(layout)) {
    Object.defineProperty(element, name, { value, configurable: true, writable: true });
  }
}

describe('optionId', () => {
  it('derives option ids from the trigger id', () => {
    expect(optionId('linear-team', 3)).toBe('linear-team-option-3');
    expect(setup().select.optionId(1)).toBe('fruit-option-1');
  });
});

describe('derived state', () => {
  it('shows the label of the selected item', () => {
    const { select } = setup({}, FRUIT, 'apricot');

    expect(select.selectedIndex).toBe(2);
    expect(select.label).toBe('Apricot');
    expect(select.isPlaceholder).toBe(false);
    expect(select.selectedValue).toBe('apricot');
    expect(select.isSelected(2)).toBe(true);
    expect(select.isSelected(1)).toBe(false);
  });

  it('shows the empty item muted, like a placeholder', () => {
    const { select } = setup();

    expect(select.label).toBe('No fruit');
    expect(select.isPlaceholder).toBe(true);
    expect(select.selectedValue).toBe('');
  });

  it('falls back to the placeholder when no item matches', () => {
    const { select } = setup({ placeholder: () => 'Pick a fruit' }, FRUIT, 'durian');

    expect(select.selectedIndex).toBe(-1);
    expect(select.label).toBe('Pick a fruit');
    expect(select.isPlaceholder).toBe(true);
  });

  it('falls back to an empty label without a placeholder', () => {
    expect(setup({}, FRUIT, 'durian').select.label).toBe('');
  });

  it('compares numbers strictly and submits them as strings', () => {
    const items: SelectItem[] = [
      { value: 0, label: 'None' },
      { value: 1, label: 'Urgent' },
    ];
    const { select, state } = setup({}, items, 0);

    expect(select.label).toBe('None');
    expect(select.isPlaceholder).toBe(false);
    expect(select.selectedValue).toBe('0');

    state.value = '1';
    expect(select.selectedIndex).toBe(-1);
    state.value = 1;
    expect(select.label).toBe('Urgent');
  });

  it('reports disabled, invalid and busy from the config, defaulting to false', () => {
    const plain = setup().select;
    expect([plain.isDisabled, plain.isInvalid, plain.isBusy]).toEqual([false, false, false]);

    const flagged = setup({ disabled: () => true, invalid: () => true, busy: () => true }).select;
    expect([flagged.isDisabled, flagged.isInvalid, flagged.isBusy]).toEqual([true, true, true]);
  });

  it('exposes the popup max height as a style', () => {
    const { select } = setup();

    expect(select.contentStyle).toEqual({ 'max-height': `${SELECT_MAX_HEIGHT}px` });
    select.maxHeight = 123;
    expect(select.contentStyle).toEqual({ 'max-height': '123px' });
  });
});

describe('opening and closing', () => {
  it('opens on the selected item and focuses the trigger', () => {
    const { select, trigger } = setup({}, FRUIT, 'cherry');

    select.show();

    expect(select.open).toBe(true);
    expect(select.activeIndex).toBe(4);
    expect(select.activeDescendant).toBe('fruit-option-4');
    expect(document.activeElement).toBe(trigger);
  });

  it('highlights the first enabled item when nothing is selected', () => {
    const items: SelectItem[] = [
      { value: 'a', label: 'A', disabled: true },
      { value: 'b', label: 'B' },
    ];
    const { select } = setup({}, items, 'zzz');

    select.show();

    expect(select.activeIndex).toBe(1);
  });

  it('does not highlight a selected item that is disabled', () => {
    const { select } = setup({}, FRUIT, 'banana');

    select.show();

    expect(select.activeIndex).toBe(0);
  });

  it('has no active descendant while closed or without a highlight', () => {
    const { select } = setup({}, []);

    expect(select.activeDescendant).toBeNull();
    select.show();
    expect(select.open).toBe(true);
    expect(select.activeIndex).toBe(-1);
    expect(select.activeDescendant).toBeNull();
  });

  it('announces itself so other selects can close', () => {
    const { select, host } = setup();
    const listener = vi.fn();
    document.addEventListener(SELECT_OPENED_EVENT, listener);

    select.show();
    select.show(); // already open: no second announcement

    document.removeEventListener(SELECT_OPENED_EVENT, listener);
    expect(listener).toHaveBeenCalledTimes(1);
    expect(listener.mock.calls[0]?.[0]).toMatchObject({ target: host, bubbles: true });
  });

  it('refuses to open while disabled', () => {
    const { select, state } = setup();
    state.disabled = true;

    select.show();

    expect(select.open).toBe(false);
  });

  it('toggles', () => {
    const { select } = setup();

    select.toggle();
    expect(select.open).toBe(true);
    select.toggle();
    expect(select.open).toBe(false);
  });

  it('hide resets the highlight and optionally refocuses the trigger', () => {
    const { select, trigger } = setup();
    select.show();
    trigger.blur();

    select.hide();
    expect(select.open).toBe(false);
    expect(select.activeIndex).toBe(-1);
    expect(document.activeElement).not.toBe(trigger);

    select.show();
    trigger.blur();
    select.hide(true);
    expect(document.activeElement).toBe(trigger);
  });

  it('tolerates a host without a trigger or popup', () => {
    const host = document.createElement('div');
    const select = withMagics(linearSelect(host, { id: 'bare', value: () => '', items: () => FRUIT, onSelect: () => undefined }));

    select.show();
    select.highlight(1);
    expect(select.open).toBe(true);
    expect(() => {
      select.hide(true);
    }).not.toThrow();
  });

  it('measures only if it is still open once the popup is rendered', () => {
    const { select } = setup();
    let queued: (() => void) | undefined;
    Object.assign(select, {
      $nextTick: (callback?: () => void) => {
        queued = callback;
        return Promise.resolve();
      },
    });

    select.show();
    select.hide();
    queued?.();

    expect(select.placement).toBe('bottom');
    expect(select.maxHeight).toBe(SELECT_MAX_HEIGHT);
    expect(select.placed).toBe(false);
  });

  it('stays unplaced until it has been measured, then reveals the popup', () => {
    const { select } = setup();
    let queued: (() => void) | undefined;
    Object.assign(select, {
      $nextTick: (callback?: () => void) => {
        queued = callback;
        return Promise.resolve();
      },
    });

    select.show();

    expect(select.open).toBe(true);
    expect(select.placed).toBe(false);

    queued?.();

    expect(select.placed).toBe(true);
  });

  it('is unplaced again once it closes, ready for the next opening', () => {
    const { select } = setup();

    select.show();
    expect(select.placed).toBe(true);

    select.hide();
    expect(select.placed).toBe(false);
  });
});

describe('choosing', () => {
  it('reports a different value, closes and refocuses the trigger', () => {
    const { select, onSelect, trigger } = setup();
    select.show();
    trigger.blur();

    select.choose(4);

    expect(onSelect).toHaveBeenCalledExactlyOnceWith('cherry');
    expect(select.open).toBe(false);
    expect(document.activeElement).toBe(trigger);
  });

  it('chooses the empty item like any other (the "none" option)', () => {
    const { select, onSelect } = setup({}, FRUIT, 'apple');

    select.choose(0);

    expect(onSelect).toHaveBeenCalledExactlyOnceWith('');
  });

  it('stays quiet when the value does not change but still closes', () => {
    const { select, onSelect } = setup({}, FRUIT, 'apple');
    select.show();

    select.choose(1);

    expect(onSelect).not.toHaveBeenCalled();
    expect(select.open).toBe(false);
  });

  it('ignores disabled and unknown items', () => {
    const { select, onSelect } = setup();
    select.show();

    select.choose(3);
    select.choose(99);
    select.choose(-1);

    expect(onSelect).not.toHaveBeenCalled();
    expect(select.open).toBe(true);
  });

  it('reports numbers as numbers', () => {
    const items: SelectItem[] = [
      { value: 0, label: 'None' },
      { value: 3, label: 'Medium' },
    ];
    const { select, onSelect } = setup({}, items, 0);

    select.choose(1);

    expect(onSelect).toHaveBeenCalledExactlyOnceWith(3);
  });
});

describe('highlight', () => {
  it('follows the pointer but skips disabled items', () => {
    const { select } = setup();
    select.show();

    select.highlight(2);
    expect(select.activeIndex).toBe(2);
    select.highlight(3);
    expect(select.activeIndex).toBe(2);
    select.highlight(2);
    expect(select.activeIndex).toBe(2);
  });
});

describe('keyboard: closed', () => {
  it.each(['Enter', ' ', 'ArrowDown', 'ArrowUp'])('%j opens the popup on the selected item', (key) => {
    const { select } = setup({}, FRUIT, 'apricot');

    const event = press(select, key);

    expect(select.open).toBe(true);
    expect(select.activeIndex).toBe(2);
    expect(event.defaultPrevented).toBe(true);
  });

  it('Home and End open and jump to the first and last enabled item', () => {
    const first = setup({}, FRUIT, 'apple');
    expect(press(first.select, 'Home').defaultPrevented).toBe(true);
    expect([first.select.open, first.select.activeIndex]).toEqual([true, 0]);

    const last = setup({}, FRUIT, 'apple');
    press(last.select, 'End');
    expect([last.select.open, last.select.activeIndex]).toEqual([true, 4]);
  });

  it('typing opens the popup on the first match', () => {
    const { select } = setup({}, FRUIT, '');

    const event = press(select, 'c');

    expect(select.open).toBe(true);
    expect(select.activeIndex).toBe(4);
    expect(event.defaultPrevented).toBe(true);
  });

  it('ignores other keys, shortcuts and a disabled select', () => {
    const { select, state } = setup();

    expect(press(select, 'Escape').defaultPrevented).toBe(false);
    expect(press(select, 'Tab').defaultPrevented).toBe(false);
    expect(press(select, 'a', { ctrlKey: true }).defaultPrevented).toBe(false);
    expect(press(select, 'a', { metaKey: true }).defaultPrevented).toBe(false);
    expect(press(select, 'ArrowDown', { altKey: true }).defaultPrevented).toBe(false);
    expect(select.open).toBe(false);

    state.disabled = true;
    press(select, 'ArrowDown');
    expect(select.open).toBe(false);
  });
});

describe('keyboard: open', () => {
  function opened(value: SelectValue = 'apple', items: readonly SelectItem[] = FRUIT): Setup {
    const context = setup({}, items, value);
    context.select.show();
    return context;
  }

  it('ArrowDown and ArrowUp move between enabled items and stop at the ends', () => {
    const { select } = opened('apple');

    expect(press(select, 'ArrowDown').defaultPrevented).toBe(true);
    expect(select.activeIndex).toBe(2);
    press(select, 'ArrowDown'); // skips the disabled Banana
    expect(select.activeIndex).toBe(4);
    press(select, 'ArrowDown');
    expect(select.activeIndex).toBe(4);

    press(select, 'ArrowUp');
    expect(select.activeIndex).toBe(2);
    press(select, 'ArrowUp');
    press(select, 'ArrowUp');
    press(select, 'ArrowUp');
    expect(select.activeIndex).toBe(0);
  });

  it('ArrowUp with nothing highlighted goes to the last item, ArrowDown to the first', () => {
    const { select } = opened();
    select.activeIndex = -1;
    press(select, 'ArrowUp');
    expect(select.activeIndex).toBe(4);

    select.activeIndex = -1;
    press(select, 'ArrowDown');
    expect(select.activeIndex).toBe(0);
  });

  it('Home and End jump to the first and last enabled item', () => {
    const { select } = opened('apricot');

    press(select, 'End');
    expect(select.activeIndex).toBe(4);
    press(select, 'Home');
    expect(select.activeIndex).toBe(0);
  });

  it('keeps the highlight when there is nowhere to go', () => {
    const { select } = opened('apple', [{ value: 'x', label: 'X', disabled: true }]);

    press(select, 'Home');
    press(select, 'End');
    press(select, 'ArrowDown');

    expect(select.activeIndex).toBe(-1);
  });

  it.each(['Enter', ' '])('%j chooses the highlighted item', (key) => {
    const { select, onSelect } = opened('apple');
    press(select, 'ArrowDown');

    const event = press(select, key);

    expect(event.defaultPrevented).toBe(true);
    expect(onSelect).toHaveBeenCalledExactlyOnceWith('apricot');
    expect(select.open).toBe(false);
  });

  it('Enter without a highlight just closes', () => {
    const { select, onSelect } = opened('apple', []);

    press(select, 'Enter');

    expect(onSelect).not.toHaveBeenCalled();
    expect(select.open).toBe(false);
  });

  it('Escape closes, refocuses the trigger and selects nothing', () => {
    const { select, onSelect, trigger } = opened();
    press(select, 'ArrowDown');
    trigger.blur();

    const event = press(select, 'Escape');

    expect(event.defaultPrevented).toBe(true);
    expect(select.open).toBe(false);
    expect(document.activeElement).toBe(trigger);
    expect(onSelect).not.toHaveBeenCalled();
  });

  it('Tab closes without selecting and lets focus move on', () => {
    const { select, onSelect } = opened();
    press(select, 'ArrowDown');

    const event = press(select, 'Tab');

    expect(event.defaultPrevented).toBe(false);
    expect(select.open).toBe(false);
    expect(onSelect).not.toHaveBeenCalled();
  });

  it('leaves unrelated keys and shortcuts alone', () => {
    const { select } = opened();

    expect(press(select, 'F5').defaultPrevented).toBe(false);
    expect(press(select, 'ArrowDown', { metaKey: true }).defaultPrevented).toBe(false);
    expect(select.open).toBe(true);
  });
});

describe('typeahead', () => {
  function opened(value: SelectValue = ''): Setup {
    const context = setup({}, FRUIT, value);
    context.select.show();
    return context;
  }

  it('highlights the first label starting with the typed letter', () => {
    const { select } = opened();

    const event = press(select, 'c');

    expect(select.activeIndex).toBe(4);
    expect(event.defaultPrevented).toBe(true);
  });

  it('is case-insensitive', () => {
    const { select } = opened();

    press(select, 'A');

    expect(select.activeIndex).toBe(1);
  });

  it('cycles through matches when the same letter repeats', () => {
    const { select } = opened();

    press(select, 'a');
    expect(select.activeIndex).toBe(1);
    press(select, 'a');
    expect(select.activeIndex).toBe(2);
    press(select, 'a');
    expect(select.activeIndex).toBe(1);
  });

  it('narrows the match as more letters are typed', () => {
    const { select } = opened();

    press(select, 'a');
    press(select, 'p');
    press(select, 'r');

    expect(select.activeIndex).toBe(2);
  });

  it('starts over after a pause', () => {
    const now = vi.spyOn(Date, 'now');
    const { select } = opened();

    now.mockReturnValue(1_000);
    press(select, 'a');
    now.mockReturnValue(1_000 + TYPEAHEAD_RESET_MS + 1);
    press(select, 'c');

    expect(select.activeIndex).toBe(4);
  });

  it('keeps accumulating within the pause window and lets space continue the word', () => {
    const now = vi.spyOn(Date, 'now');
    const items: SelectItem[] = [
      { value: 1, label: 'Alpha' },
      { value: 2, label: 'Apple pie' },
    ];
    const context = setup({}, items, 99);
    context.select.show();

    now.mockReturnValue(5_000);
    press(context.select, 'a');
    now.mockReturnValue(5_000 + TYPEAHEAD_RESET_MS);
    press(context.select, 'p');
    press(context.select, 'p');
    press(context.select, 'l');
    press(context.select, 'e');
    const space = press(context.select, ' ');
    press(context.select, 'p');

    expect(space.defaultPrevented).toBe(true);
    expect(context.select.open).toBe(true);
    expect(context.select.activeIndex).toBe(1);
  });

  it('treats a space after a pause as a selection', () => {
    const now = vi.spyOn(Date, 'now');
    const { select, onSelect } = opened();

    now.mockReturnValue(1_000);
    press(select, 'c');
    now.mockReturnValue(1_000 + TYPEAHEAD_RESET_MS + 1);
    press(select, ' ');

    expect(onSelect).toHaveBeenCalledExactlyOnceWith('cherry');
  });

  it('ignores disabled items and keeps the highlight when nothing matches', () => {
    const { select } = opened('apple');

    press(select, 'b');
    expect(select.activeIndex).toBe(1);
    press(select, 'z');
    expect(select.activeIndex).toBe(1);
  });

  it('does nothing without items', () => {
    const { select } = setup({}, []);
    select.show();

    expect(press(select, 'a').defaultPrevented).toBe(true);
    expect(select.activeIndex).toBe(-1);
  });

  it('forgets what was typed when the popup closes', () => {
    const now = vi.spyOn(Date, 'now');
    now.mockReturnValue(1_000);
    const { select } = opened();

    press(select, 'a');
    select.hide();
    select.show();
    press(select, 'p'); // 'ap' would match Apple; 'p' alone matches nothing

    expect(select.activeIndex).toBe(0);
  });
});

describe('outside interaction', () => {
  it('closes on an event outside the host only', () => {
    const { select, host, trigger } = setup();
    select.show();

    const inside = new MouseEvent('click', { bubbles: true });
    trigger.dispatchEvent(inside);
    select.onOutside(inside);
    expect(select.open).toBe(true);

    const outside = new MouseEvent('click', { bubbles: true });
    document.body.dispatchEvent(outside);
    select.onOutside(outside);
    expect(select.open).toBe(false);
    expect(host.isConnected).toBe(true);
  });

  it('closes when the target is not a node, such as the window', () => {
    const { select } = setup();
    select.show();
    const event = new Event('click');
    window.dispatchEvent(event);

    select.onOutside(event);

    expect(select.open).toBe(false);
  });

  it('does nothing when already closed', () => {
    const { select } = setup();
    const event = new MouseEvent('click', { bubbles: true });
    document.body.dispatchEvent(event);

    select.onOutside(event);

    expect(select.open).toBe(false);
  });

  it('closes when the viewport changes', () => {
    const { select } = setup();
    select.show();

    select.onViewportChange();

    expect(select.open).toBe(false);
  });
});

describe('placement', () => {
  function rectAt(trigger: HTMLElement, top: number, bottom: number): void {
    vi.spyOn(trigger, 'getBoundingClientRect').mockReturnValue({ top, bottom, left: 0, right: 200, width: 200, height: bottom - top, x: 0, y: top, toJSON: () => ({}) });
  }

  it('opens below when there is room', () => {
    const { select, trigger, content } = setup();
    vi.stubGlobal('innerHeight', 800);
    rectAt(trigger, 100, 142);
    defineLayout(content, { scrollHeight: 200 });

    select.show();

    expect(select.placement).toBe('bottom');
    expect(select.maxHeight).toBe(SELECT_MAX_HEIGHT);
  });

  it('shrinks the popup to the room below when that is the bigger side', () => {
    const { select, trigger, content } = setup();
    vi.stubGlobal('innerHeight', 400);
    rectAt(trigger, 100, 142);
    defineLayout(content, { scrollHeight: 600 });

    select.show();

    expect(select.placement).toBe('bottom');
    expect(select.maxHeight).toBe(400 - 142 - 4 - 8);
  });

  it('flips above when the room below is too small and above is larger', () => {
    const { select, trigger, content } = setup();
    vi.stubGlobal('innerHeight', 700);
    rectAt(trigger, 560, 602);
    defineLayout(content, { scrollHeight: 250 });

    select.show();

    expect(select.placement).toBe('top');
    expect(select.maxHeight).toBe(SELECT_MAX_HEIGHT);
  });

  it('flips above and caps the height to the room above', () => {
    const { select, trigger, content } = setup();
    vi.stubGlobal('innerHeight', 320);
    rectAt(trigger, 250, 292);
    defineLayout(content, { scrollHeight: 400 });

    select.show();

    expect(select.placement).toBe('top');
    expect(select.maxHeight).toBe(250 - 4 - 8);
  });

  it('never makes the popup smaller than a few rows', () => {
    const { select, trigger, content } = setup();
    vi.stubGlobal('innerHeight', 160);
    rectAt(trigger, 100, 142);
    defineLayout(content, { scrollHeight: 400 });

    select.show();

    expect(select.maxHeight).toBeGreaterThanOrEqual(96);
  });

  it('resets to below on every open', () => {
    const { select, trigger, content } = setup();
    vi.stubGlobal('innerHeight', 700);
    rectAt(trigger, 560, 602);
    defineLayout(content, { scrollHeight: 250 });
    select.show();
    expect(select.placement).toBe('top');
    select.hide();

    rectAt(trigger, 100, 142);
    select.show();

    expect(select.placement).toBe('bottom');
  });
});

describe('scrolling the highlighted item into view', () => {
  function layout(content: HTMLElement, scrollTop: number): HTMLElement[] {
    defineLayout(content, { clientHeight: 100, scrollTop });
    const options = [...content.querySelectorAll<HTMLElement>('[role="option"]')];
    options.forEach((option, index) => {
      defineLayout(option, { offsetTop: 4 + index * 32, offsetHeight: 32 });
    });
    return options;
  }

  it('scrolls up to an item above the viewport', () => {
    const { select, content } = setup({}, FRUIT, 'apple');
    layout(content, 120);

    select.show();

    expect(content.scrollTop).toBe(32);
  });

  it('never scrolls above the start of the list', () => {
    const { select, content } = setup({}, FRUIT, '');
    layout(content, 50);

    select.show();

    expect(content.scrollTop).toBe(0);
  });

  it('scrolls down to an item below the viewport', () => {
    const { select, content } = setup({}, FRUIT, 'cherry');
    layout(content, 0);

    select.show();

    expect(content.scrollTop).toBe(4 + 4 * 32 + 32 + 4 - 100);
  });

  it('leaves the scroll position alone when the item is already visible', () => {
    const { select, content } = setup({}, FRUIT, 'apple');
    layout(content, 0);

    select.show();

    expect(content.scrollTop).toBe(0);
  });

  it('follows the keyboard', () => {
    const { select, content } = setup({}, FRUIT, 'apple');
    layout(content, 0);
    select.show();

    press(select, 'End');

    expect(content.scrollTop).toBeGreaterThan(0);
  });

  it('does nothing without a rendered option', () => {
    const { select, content } = setup({}, FRUIT, 'apple');
    defineLayout(content, { clientHeight: 100, scrollTop: 7 });
    content.replaceChildren();

    select.show();

    expect(content.scrollTop).toBe(7);
  });
});

describe('visuals', () => {
  const WITH_VISUALS: readonly SelectItem[] = [
    { value: '', label: 'Nobody', visual: { kind: 'icon', name: 'user-empty', color: null } },
    { value: 'ada', label: 'Ada', visual: { kind: 'avatar', url: null, initials: 'A', color: '#5e6ad2' } },
    { value: 'bob', label: 'Bob' },
  ];

  it('exposes the selected item\'s visual for the trigger', () => {
    const { select, state } = setup({}, WITH_VISUALS, 'ada');

    expect(select.selectedVisual).toEqual({ kind: 'avatar', url: null, initials: 'A', color: '#5e6ad2' });

    state.value = '';
    expect(select.selectedVisual).toEqual({ kind: 'icon', name: 'user-empty', color: null });
  });

  it('has no visual for an item without one, or when nothing matches the value', () => {
    const { select, state } = setup({}, WITH_VISUALS, 'bob');

    expect(select.selectedVisual).toBeNull();

    state.value = 'missing';
    expect(select.selectedVisual).toBeNull();
  });

  it('offers the helpers the template uses to render a visual', () => {
    const { select } = setup({}, WITH_VISUALS, 'ada');
    const visual = select.selectedVisual;

    expect(select.visualText(visual)).toBe('A');
    expect(select.visualStyle(visual)).toMatchObject({ '--linear-visual-bg': '#5e6ad2' });
    expect(select.visualClasses(visual)).toEqual(['linear-visual--avatar']);
    expect(select.visualMarkup({ kind: 'priority', level: 0 })).toContain('<svg');

    const image = document.createElement('img');
    select.onVisualError({ target: image } as unknown as Event);
    expect(image.hidden).toBe(true);
  });
});
