import { describe, expect, it } from 'vitest';
import { COMPONENT_NAMES } from '../../components';
import { LINEAR_EVENTS } from '../../components/events';
import { renderApp, renderFatalError, selectTemplate, type SelectSpec } from '../../ui/template';

/**
 * Parses the markup and unwraps every `<template>` (recursively), so controls
 * that Alpine would only render conditionally can be inspected statically.
 */
function parseApp(): HTMLElement {
  const container = document.createElement('div');
  container.innerHTML = renderApp();

  for (let template = container.querySelector('template'); template !== null; template = container.querySelector('template')) {
    template.replaceWith(template.content);
  }

  return container;
}

function ids(container: ParentNode): Set<string> {
  return new Set([...container.querySelectorAll('[id]')].map((element) => element.id));
}

function tokens(value: string | null): string[] {
  return (value ?? '').split(/\s+/).filter((token) => token !== '');
}

describe('renderApp', () => {
  it('is static: it never interpolates runtime data', () => {
    expect(renderApp()).toBe(renderApp());
  });

  it('roots the app at the linearApp component and listens for every child event', () => {
    const root = parseApp().querySelector('.linear-shell');

    expect(root?.getAttribute('x-data')).toBe('linearApp()');
    for (const name of Object.values(LINEAR_EVENTS)) {
      expect(root?.hasAttribute(`x-on:${name}`)).toBe(true);
    }
  });

  it('wires each child component to its own host element', () => {
    const app = parseApp();

    for (const name of [COMPONENT_NAMES.connection, COMPONENT_NAMES.destination, COMPONENT_NAMES.failures]) {
      expect(app.querySelector(`[x-data="${name}($el)"]`)).not.toBeNull();
    }
  });

  it('does not rely on x-ref or $dispatch inside component methods', () => {
    expect(renderApp()).not.toContain('x-ref');
    expect(renderApp()).not.toContain('$dispatch');
  });

  it('has one page heading and labelled card sections', () => {
    const app = parseApp();

    expect(app.querySelectorAll('h1')).toHaveLength(1);
    const sections = [...app.querySelectorAll('section.linear-card')];
    expect(sections).toHaveLength(3);
    const known = ids(app);
    for (const section of sections) {
      expect(known.has(section.getAttribute('aria-labelledby') ?? '')).toBe(true);
    }
  });

  it('groups related controls in fieldsets with legends', () => {
    const legends = [...parseApp().querySelectorAll('fieldset > legend')].map((legend) => legend.textContent);

    expect(legends).toEqual(['Sending mode', 'Labels']);
  });

  it('keeps live regions in the DOM for status and error banners', () => {
    const app = parseApp();

    expect(app.querySelector('.linear-flashes [role="status"][aria-live="polite"]')).not.toBeNull();
    expect(app.querySelector('.linear-flashes [role="alert"]')).not.toBeNull();
  });

  it('labels every form control and gives every button a name', () => {
    const app = parseApp();
    const known = ids(app);

    for (const control of app.querySelectorAll('input:not([type="hidden"]), [role="combobox"]')) {
      const labelled =
        control.closest('label') !== null ||
        [...app.querySelectorAll('label[for]')].some((label) => label.getAttribute('for') === control.id);
      expect(labelled, `control "${control.id || control.getAttribute('name')}" has no label`).toBe(true);
    }

    for (const button of app.querySelectorAll('button')) {
      const named =
        (button.textContent ?? '').trim() !== '' ||
        button.hasAttribute('aria-label') ||
        button.hasAttribute('x-text') ||
        button.querySelector('[x-text]') !== null;
      expect(named, `button has no accessible name: ${button.outerHTML}`).toBe(true);
    }

    for (const element of app.querySelectorAll('[aria-describedby]')) {
      for (const id of tokens(element.getAttribute('aria-describedby'))) {
        expect(known.has(id), `aria-describedby points at missing #${id}`).toBe(true);
      }
    }

    for (const label of app.querySelectorAll('label[for]')) {
      expect(known.has(label.getAttribute('for') ?? ''), 'label[for] points at a missing control').toBe(true);
    }
  });

  it('has unique ids', () => {
    const all = [...parseApp().querySelectorAll('[id]')].map((element) => element.id);

    expect(new Set(all).size).toBe(all.length);
  });

  it('posts connect and disconnect as CSRF-protected forms, spoofing DELETE for disconnect', () => {
    const forms = [...parseApp().querySelectorAll('form[method="post"]')];

    expect(forms.map((form) => form.getAttribute(':action'))).toEqual(['settings.urls.apiKey', 'settings.urls.disconnect']);
    for (const form of forms) {
      expect(form.querySelector('input[type="hidden"][name="_token"]')).not.toBeNull();
    }
    expect(forms[0]?.querySelector('input[name="_method"]')).toBeNull();
    expect(forms[1]?.querySelector('input[name="_method"]')?.getAttribute('value')).toBe('DELETE');
  });
});

describe('renderFatalError', () => {
  it('replaces the container content with an accessible alert', () => {
    const container = document.createElement('div');
    container.innerHTML = '<p>old</p>';

    renderFatalError(container, 'Missing settings');

    const alert = container.querySelector('[role="alert"]');
    expect(alert).not.toBeNull();
    expect(container.querySelector('p')?.textContent).toMatch(/Reload the page/);
    expect(container.querySelector('.linear-fatal__title')?.textContent).toBe('The Linear settings page could not start');
    expect(container.querySelector('.linear-fatal__detail')?.textContent).toBe('Missing settings');
    expect(container.textContent).not.toContain('old');
  });

  it('renders the detail as text, never as HTML', () => {
    const container = document.createElement('div');

    renderFatalError(container, '<img src=x onerror=alert(1)>');

    expect(container.querySelector('img')).toBeNull();
    expect(container.querySelector('.linear-fatal__detail')?.textContent).toBe('<img src=x onerror=alert(1)>');
  });
});

describe('selectTemplate', () => {
  const SPEC: SelectSpec = {
    id: 'linear-x',
    name: 'xId',
    value: 'draft.xId',
    items: 'xItems',
    onSelect: "pick('xId', value)",
  };

  function render(spec: SelectSpec): HTMLElement {
    const container = document.createElement('div');
    container.innerHTML = selectTemplate(spec);
    return container;
  }

  it('renders a combobox button wired to a listbox and a hidden input', () => {
    const select = render(SPEC);
    const trigger = select.querySelector('button.linear-select__trigger');
    const listbox = select.querySelector('[role="listbox"]');

    expect(trigger?.getAttribute('type')).toBe('button');
    expect(trigger?.getAttribute('id')).toBe('linear-x');
    expect(trigger?.getAttribute('role')).toBe('combobox');
    expect(trigger?.getAttribute('aria-haspopup')).toBe('listbox');
    expect(trigger?.getAttribute('aria-controls')).toBe('linear-x-listbox');
    expect(trigger?.getAttribute('aria-labelledby')).toBe('linear-x-label');
    expect(listbox?.getAttribute('id')).toBe('linear-x-listbox');
    expect(listbox?.getAttribute('aria-labelledby')).toBe('linear-x-label');
    expect(select.querySelector('input[type="hidden"]')?.getAttribute('name')).toBe('xId');
    expect(select.querySelector('.linear-select__chevron path')?.getAttribute('d')).toBe('m6 9 6 6 6-6');
    expect(select.querySelector<HTMLTemplateElement>('template[x-for]')?.content.querySelector('.linear-select__check path')?.getAttribute('d')).toBe('M20 6 9 17l-5-5');
  });

  it('renders the leading visual in the trigger and in every option, as decorative static markup', () => {
    const select = render(SPEC);
    const trigger = select.querySelector('button.linear-select__trigger');
    const option = select.querySelector<HTMLTemplateElement>('template[x-for]')?.content.querySelector('[role="option"]');

    for (const [scope, expression] of [
      [trigger, 'selectedVisual'],
      [option, 'item.visual'],
    ] as const) {
      const templates = [...(scope?.querySelectorAll('template') ?? [])].map((template) => template.getAttribute('x-if'));

      expect(templates.some((condition) => condition?.startsWith(`${expression}?.kind === 'avatar'`))).toBe(true);
      expect(templates.some((condition) => condition?.startsWith(`${expression}?.kind === 'tile'`))).toBe(true);
      expect(templates.some((condition) => condition?.startsWith(`${expression} &&`))).toBe(true);
    }

    // Unwrap the templates, as Alpine's x-if would, to inspect what could render.
    for (let template = select.querySelector('template'); template !== null; template = select.querySelector('template')) {
      template.replaceWith(template.content);
    }

    const visuals = [...select.querySelectorAll('.linear-visual')];
    expect(visuals.length).toBeGreaterThan(0);
    expect(visuals.every((visual) => visual.getAttribute('aria-hidden') === 'true')).toBe(true);
    // The label stays the accessible name; the trigger's visuals come before it.
    expect(select.querySelector('button.linear-select__trigger')?.firstElementChild?.classList.contains('linear-visual')).toBe(true);
  });

  it('binds avatar images safely: bound src, empty alt, lazy, no referrer, error fallback', () => {
    const select = render(SPEC);
    const image = [...select.querySelectorAll('template')]
      .map((template) => template.content.querySelector('img') ?? template.content.querySelector('template')?.content.querySelector('img'))
      .find((candidate) => candidate !== null && candidate !== undefined);

    expect(image?.getAttribute(':src')).toMatch(/\.url$/);
    expect(image?.getAttribute('alt')).toBe('');
    expect(image?.getAttribute('loading')).toBe('lazy');
    expect(image?.getAttribute('referrerpolicy')).toBe('no-referrer');
    expect(image?.getAttribute('@error')).toBe('onVisualError($event)');
    expect(image?.hasAttribute('src')).toBe(false);
  });

  it('binds text through x-text and only static SVG through x-html', () => {
    const markup = selectTemplate(SPEC);

    expect(markup.match(/x-html="/g)).toHaveLength(2);
    expect([...markup.matchAll(/x-html="([^"]+)"/g)].every((match) => match[1]?.startsWith('visualMarkup(') === true)).toBe(true);
    expect(markup).toContain('x-text="visualText(');
  });

  it('builds the component configuration from the spec', () => {
    const xData = render(SPEC).querySelector('[x-data]')?.getAttribute('x-data');

    expect(xData).toBe(
      "linearSelect($el, { id: 'linear-x', value: () => draft.xId, items: () => xItems, onSelect: (value) => pick('xId', value) })",
    );
  });

  it('adds the optional bindings and attributes only when given', () => {
    const plain = render(SPEC);
    expect(plain.querySelector('[aria-describedby]')).toBeNull();
    expect(plain.querySelector('[data-linear-ref]')).toBeNull();

    const full = render({
      ...SPEC,
      placeholder: 'ph',
      disabled: 'off',
      invalid: 'bad',
      busy: 'wait',
      describedBy: 'linear-x-error',
      ref: 'x',
    });
    const trigger = full.querySelector('button');

    expect(full.querySelector('[x-data]')?.getAttribute('x-data')).toContain(
      "placeholder: () => ph, disabled: () => off, invalid: () => bad, busy: () => wait",
    );
    expect(trigger?.getAttribute('aria-describedby')).toBe('linear-x-error');
    expect(trigger?.getAttribute('data-linear-ref')).toBe('x');
  });

  it('is used for all five destination fields instead of native selects', () => {
    const app = parseApp();

    expect(app.querySelectorAll('select')).toHaveLength(0);
    expect([...app.querySelectorAll('[role="combobox"]')].map((trigger) => trigger.id)).toEqual([
      'linear-team',
      'linear-priority',
      'linear-project',
      'linear-state',
      'linear-assignee',
    ]);
    expect(app.querySelector('#linear-team')?.getAttribute('data-linear-ref')).toBe('team');
    for (const id of ['team', 'priority', 'project', 'state', 'assignee']) {
      expect(app.querySelector(`label#linear-${id}-label[for="linear-${id}"]`)).not.toBeNull();
    }
  });
});
