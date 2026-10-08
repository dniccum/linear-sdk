/**
 * `linearSelect`: a reusable, accessible custom select (the WAI-ARIA
 * "select-only combobox" pattern) styled after shadcn/ui's Select.
 *
 * The trigger is a `<button role="combobox">` that keeps DOM focus the whole
 * time; the popup is a `role="listbox"` whose highlighted option is announced
 * through `aria-activedescendant`. The markup comes from `selectTemplate()` in
 * `ui/template.ts`.
 *
 * The component never owns the value. It reads and writes the parent's state
 * through the closures in `SelectConfig`, which Alpine evaluates in the
 * enclosing component's scope:
 *
 *   x-data="linearSelect($el, { id: 'x', value: () => draft.x, items: () => xItems, onSelect: (value) => pick(value) })"
 */
import type { AlpineMagics } from '../types';
import {
  hideBrokenImage,
  visualClasses,
  visualMarkup,
  visualStyle,
  visualText,
  type SelectVisual,
} from '../ui/visuals';

export type SelectValue = string | number;

export interface SelectItem {
  readonly value: SelectValue;
  readonly label: string;
  readonly disabled?: boolean;
  /** A decorative leading visual (see `ui/visuals.ts`), shown in the list and in the trigger when selected. */
  readonly visual?: SelectVisual;
}

export interface SelectConfig {
  /** The trigger's `id`; option ids are derived from it (see `optionId`). */
  readonly id: string;
  /** The current value. Compared with each item's `value` using `===`. */
  readonly value: () => SelectValue;
  readonly items: () => readonly SelectItem[];
  /** Called when the user picks a value different from the current one. */
  readonly onSelect: (value: SelectValue) => void;
  /** Text shown (muted) when no item matches the current value. */
  readonly placeholder?: () => string;
  readonly disabled?: () => boolean;
  readonly invalid?: () => boolean;
  readonly busy?: () => boolean;
}

export type SelectPlacement = 'bottom' | 'top';

/** Dispatched from a select's host when it opens, so every other select closes. */
export const SELECT_OPENED_EVENT = 'linear-select-opened';

/** The popup's maximum height in pixels (shadcn uses 24rem; 320px suits this page). */
export const SELECT_MAX_HEIGHT = 320;

/** How long a pause resets the typeahead buffer. */
export const TYPEAHEAD_RESET_MS = 500;

const OPEN_KEYS: readonly string[] = ['Enter', ' ', 'ArrowDown', 'ArrowUp'];
const MIN_HEIGHT = 96;
const VIEWPORT_MARGIN = 8;
const TRIGGER_GAP = 4;
const LIST_PADDING = 4;

const SELECTORS = {
  trigger: '.linear-select__trigger',
  content: '.linear-select__content',
  options: '[role="option"]',
} as const;

/** The DOM id of the option at `index`; used by the template and `aria-activedescendant`. */
export function optionId(id: string, index: number): string {
  return `${id}-option-${index}`;
}

/** Indexes of the items that can be highlighted and chosen. */
function enabledIndexes(items: readonly SelectItem[]): number[] {
  return items.flatMap((item, index) => (item.disabled === true ? [] : [index]));
}

export interface SelectComponent {
  open: boolean;
  /** Index of the highlighted option, or `-1`. */
  activeIndex: number;
  placement: SelectPlacement;
  maxHeight: number;

  readonly entries: readonly SelectItem[];
  readonly selectedIndex: number;
  /** The value rendered into the hidden input. */
  readonly selectedValue: string;
  readonly label: string;
  /** The selected item's visual, or `null` when it has none (or nothing is selected). */
  readonly selectedVisual: SelectVisual | null;
  readonly isPlaceholder: boolean;
  readonly isDisabled: boolean;
  readonly isInvalid: boolean;
  readonly isBusy: boolean;
  readonly activeDescendant: string | null;
  readonly contentStyle: Record<string, string>;

  /** Helpers the template calls to turn a `SelectVisual` into classes, style, markup and text. */
  visualClasses: typeof visualClasses;
  visualStyle: typeof visualStyle;
  visualMarkup: typeof visualMarkup;
  visualText: typeof visualText;
  /** `error` handler for avatar images. */
  onVisualError: typeof hideBrokenImage;

  optionId(index: number): string;
  isSelected(index: number): boolean;
  toggle(): void;
  show(): void;
  hide(refocus?: boolean): void;
  choose(index: number): void;
  highlight(index: number): void;
  onKeydown(event: KeyboardEvent): void;
  /** Moves the highlight for arrow/Home/End/typeahead keys; reports whether `key` was one of them. */
  navigate(key: string, typing: boolean): boolean;
  onOutside(event: Event): void;
  onViewportChange(): void;
}

/** Creates the `x-data="linearSelect($el, {...})"` component for the select rooted at `host`. */
export function linearSelect(host: HTMLElement, config: SelectConfig): SelectComponent {
  let typeahead = '';
  let lastKeyAt = 0;

  const find = (selector: string): HTMLElement | null => host.querySelector<HTMLElement>(selector);

  const component: SelectComponent & ThisType<SelectComponent & AlpineMagics> = {
    open: false,
    activeIndex: -1,
    placement: 'bottom',
    maxHeight: SELECT_MAX_HEIGHT,
    visualClasses,
    visualStyle,
    visualMarkup,
    visualText,
    onVisualError: hideBrokenImage,

    get entries() {
      return config.items();
    },

    get selectedIndex() {
      const value = config.value();
      return this.entries.findIndex((item) => item.value === value);
    },

    get selectedValue() {
      return String(config.value());
    },

    get label() {
      return this.entries[this.selectedIndex]?.label ?? config.placeholder?.() ?? '';
    },

    get selectedVisual() {
      return this.entries[this.selectedIndex]?.visual ?? null;
    },

    get isPlaceholder() {
      const selected = this.entries[this.selectedIndex];
      return selected === undefined || selected.value === '';
    },

    get isDisabled() {
      return config.disabled?.() ?? false;
    },

    get isInvalid() {
      return config.invalid?.() ?? false;
    },

    get isBusy() {
      return config.busy?.() ?? false;
    },

    get activeDescendant() {
      return this.open && this.activeIndex >= 0 ? this.optionId(this.activeIndex) : null;
    },

    get contentStyle() {
      return { 'max-height': `${this.maxHeight}px` };
    },

    optionId(index) {
      return optionId(config.id, index);
    },

    isSelected(index) {
      return index === this.selectedIndex;
    },

    toggle() {
      if (this.open) {
        this.hide();
      } else {
        this.show();
      }
    },

    show() {
      if (this.open || this.isDisabled) {
        return;
      }

      // Safari and Firefox on macOS do not focus a button when it is clicked.
      find(SELECTORS.trigger)?.focus();
      host.dispatchEvent(new CustomEvent(SELECT_OPENED_EVENT, { bubbles: true }));

      const enabled = enabledIndexes(this.entries);
      this.activeIndex = enabled.includes(this.selectedIndex) ? this.selectedIndex : (enabled[0] ?? -1);
      this.placement = 'bottom';
      this.maxHeight = SELECT_MAX_HEIGHT;
      this.open = true;

      // The popup has to be rendered before it can be measured.
      void this.$nextTick(() => {
        if (this.open) {
          place(this);
          scrollActiveIntoView(this.activeIndex);
        }
      });
    },

    hide(refocus = false) {
      this.open = false;
      this.activeIndex = -1;
      typeahead = '';

      if (refocus) {
        find(SELECTORS.trigger)?.focus();
      }
    },

    choose(index) {
      const item = this.entries[index];

      if (item === undefined || item.disabled === true) {
        return;
      }

      const changed = item.value !== config.value();

      this.hide(true);

      if (changed) {
        config.onSelect(item.value);
      }
    },

    highlight(index) {
      if (index !== this.activeIndex && this.entries[index]?.disabled !== true) {
        this.activeIndex = index;
      }
    },

    onKeydown(event) {
      if (this.isDisabled || event.ctrlKey || event.metaKey || event.altKey) {
        return;
      }

      const { key } = event;
      // A space continues a word being typed; otherwise it selects or opens.
      const typing = key.length === 1 && (key !== ' ' || (typeahead !== '' && Date.now() - lastKeyAt <= TYPEAHEAD_RESET_MS));

      if (!this.open) {
        if (OPEN_KEYS.includes(key)) {
          event.preventDefault();
          this.show();
        } else if (typing || key === 'Home' || key === 'End') {
          event.preventDefault();
          this.show();
          this.navigate(key, typing);
        }

        return;
      }

      if (key === 'Tab') {
        this.hide();
      } else if (key === 'Escape') {
        event.preventDefault();
        this.hide(true);
      } else if ((key === 'Enter' || key === ' ') && !typing) {
        event.preventDefault();

        if (this.activeIndex < 0) {
          this.hide(true);
        } else {
          this.choose(this.activeIndex);
        }
      } else if (this.navigate(key, typing)) {
        event.preventDefault();
      }
    },

    navigate(key, typing) {
      const enabled = enabledIndexes(this.entries);
      let next: number | undefined;

      if (typing) {
        next = search(this, key);
      } else if (key === 'ArrowDown') {
        next = enabled.find((index) => index > this.activeIndex);
      } else if (key === 'ArrowUp') {
        const from = this.activeIndex < 0 ? this.entries.length : this.activeIndex;
        next = [...enabled].reverse().find((index) => index < from);
      } else if (key === 'Home') {
        next = enabled[0];
      } else if (key === 'End') {
        next = enabled[enabled.length - 1];
      } else {
        return false;
      }

      if (next !== undefined) {
        this.activeIndex = next;
        scrollActiveIntoView(next);
      }

      return true;
    },

    onOutside(event) {
      const { target } = event;

      if (this.open && !(target instanceof Node && host.contains(target))) {
        this.hide();
      }
    },

    onViewportChange() {
      this.hide();
    },
  };

  /** Typeahead: the first enabled item after the highlighted one whose label starts with what was typed. */
  function search(self: SelectComponent, char: string): number | undefined {
    const now = Date.now();

    typeahead = now - lastKeyAt > TYPEAHEAD_RESET_MS ? char : typeahead + char;
    lastKeyAt = now;

    const entries = self.entries;
    const repeated = [...typeahead].every((typed) => typed === typeahead.charAt(0));
    const query = (repeated ? typeahead.charAt(0) : typeahead).toLowerCase();
    const start = (query.length === 1 ? self.activeIndex + 1 : Math.max(self.activeIndex, 0)) % Math.max(entries.length, 1);
    const indexed = entries.map((item, index) => ({ item, index }));

    return [...indexed.slice(start), ...indexed.slice(0, start)].find(
      ({ item }) => item.disabled !== true && item.label.toLowerCase().startsWith(query),
    )?.index;
  }

  /** Positions the popup below the trigger, or above it when there is more room there. */
  function place(self: SelectComponent): void {
    const trigger = find(SELECTORS.trigger);
    const content = find(SELECTORS.content);

    if (trigger === null || content === null) {
      return;
    }

    const rect = trigger.getBoundingClientRect();
    const below = window.innerHeight - rect.bottom - TRIGGER_GAP - VIEWPORT_MARGIN;
    const above = rect.top - TRIGGER_GAP - VIEWPORT_MARGIN;
    const wanted = Math.min(SELECT_MAX_HEIGHT, content.scrollHeight);

    self.placement = below >= wanted || below >= above ? 'bottom' : 'top';
    self.maxHeight = Math.max(MIN_HEIGHT, Math.min(SELECT_MAX_HEIGHT, self.placement === 'bottom' ? below : above));
  }

  /** Scrolls the popup (not the page) just enough to show the option at `index`. */
  function scrollActiveIntoView(index: number): void {
    const content = find(SELECTORS.content);
    const option = content?.querySelectorAll<HTMLElement>(SELECTORS.options)[index];

    if (content === null || option === undefined) {
      return;
    }

    const top = option.offsetTop - LIST_PADDING;
    const bottom = option.offsetTop + option.offsetHeight + LIST_PADDING;

    if (top < content.scrollTop) {
      content.scrollTop = Math.max(0, top);
    } else if (bottom > content.scrollTop + content.clientHeight) {
      content.scrollTop = bottom - content.clientHeight;
    }
  }

  return component;
}
