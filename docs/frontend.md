# Front-end development

> [!NOTE]
> The configuration page is part of the Laravel integration only. It is rendered by Blade views and components and served by Laravel routes (see [Framework support](../README.md#framework-support)).

The configuration page is a small TypeScript application built with [Alpine.js](https://alpinejs.dev) and [Vite](https://vite.dev). The Blade view renders a mount element plus the page's data as JSON; the bundle in `public/build` takes it from there. Everything it needs from the server is described in [`docs/http-contract.md`](http-contract.md).

- [Stack and requirements](#stack-and-requirements)
- [Architecture and folder map](#architecture-and-folder-map)
- [Data flow: Blade JSON to Alpine](#data-flow-blade-json-to-alpine)
- [How components talk to each other](#how-components-talk-to-each-other)
- [Running the dev server against the workbench](#running-the-dev-server-against-the-workbench)
- [Rebuilding and committing assets](#rebuilding-and-committing-assets)
- [The select component](#the-select-component)
- [Select visuals](#select-visuals)
- [Expired sessions and the login redirect](#expired-sessions-and-the-login-redirect)
- [The back link](#the-back-link)
- [Adding a component, step by step](#adding-a-component-step-by-step)
- [TypeScript conventions](#typescript-conventions)
- [Testing](#testing)
- [Linting](#linting)
- [Scripts](#scripts)
- [Publishing and customising assets (for package users)](#publishing-and-customising-assets-for-package-users)
- [Building a fully custom UI](#building-a-fully-custom-ui)
- [Notes: accessibility, CSP, localisation](#notes-accessibility-csp-localisation)

## Stack and requirements

| Tool | Version | Why this one |
|---|---|---|
| Node.js | `>= 22.12` | Required by Vitest 5. CI runs Node 22. |
| Alpine.js | 3.17 | Runtime. The only production dependency. |
| TypeScript | 6.0 | Newest release `typescript-eslint` supports (its peer range is `< 6.1`); TypeScript 7 is not usable with it yet. |
| Vite | 8 | Build and dev server. |
| Vitest | 5 (+ `@vitest/coverage-v8`) | Test runner and coverage. |
| jsdom | 29 | DOM for tests. |
| ESLint | 10 + `typescript-eslint` 8 | Flat config, type-aware rules. |

`package.json` is `private: true` (`@dniccum/linear-sdk-ui`); it is never published to npm. The compiled output in `public/build` is what ships with the Composer package.

```bash
npm install
```

## Architecture and folder map

```
resources/
  css/linear.css            Hand-written styles (BEM-ish `.linear-*`, CSS custom properties)
  js/
    main.ts                 Bootstrap: imports the CSS and calls mount(). Nothing else.
    mount.ts                mount(): read settings -> brand -> register components -> render -> Alpine.start()
    settings.ts             readSettings(): finds #linear-settings, parses and validates it (SettingsError)
    schema.ts               Runtime guards for every contract payload (isSettings, isTeamOptions, ...)
    guards.ts               Tiny guard combinators (shape, nullable, arrayOf, oneOf, ...)
    brand.ts                isValidHexColor, normalizeHexColor, readableTextColor, applyBrand
    format.ts               formatDateTime, pluralize
    types/index.ts          TypeScript mirror of docs/http-contract.md + `declare global { Window.Alpine }`
    api/
      client.ts             createClient(): typed fetch wrapper (getTeams, getTeamOptions, saveDestination, ...)
      errors.ts             ApiError (status, fieldErrors, reconnect) and errorMessage()
    components/
      index.ts              registerComponents(): the only place Alpine.data(...) is called
      linear-app.ts         Root `linearApp`: owns connection, destination, failures and flash state
      connection-card.ts    `connectionCard`: disconnect confirmation and API key form state
      destination-form.ts   `destinationForm`: teams, options, validation, save, remove
      destination-draft.ts  Pure helpers: form draft <-> payload, pruning, field-error grouping
      failures-list.ts      `failuresList`: retry with optimistic removal
      select.ts             `linearSelect`: reusable accessible custom select (shadcn/ui style)
      events.ts             Typed event names + emit/reportReconnect/focusRef helpers
    ui/visuals.ts           SelectVisual descriptors, their builders/validation, and the static SVG icons
    ui/template.ts          The whole UI as Alpine-annotated HTML strings + selectTemplate() + renderFatalError()
    __tests__/              Vitest tests (mirrors the source layout) and shared fixtures
public/build/               Compiled assets and `.vite/manifest.json` (committed)
```

Design rules that explain the layout:

- **`main.ts` stays a bootstrap.** All logic lives in `mount()` so it can be tested with a fake Alpine.
- **`types/` mirrors the PHP DTOs; `schema.ts` enforces them.** Each guard is declared as `Guard<Settings>`, `Guard<Destination>`, ... so the compiler fails if an interface and its guard drift apart.
- **Components are plain objects.** Every `Alpine.data` factory is an ordinary function returning a typed object, so unit tests call it directly with fakes; no Alpine needed.
- **No template files.** `ui/template.ts` builds the HTML from template literals so the Blade view only has to render `<div data-linear-app>`. The strings contain no interpolated data (see below).

## Data flow: Blade JSON to Alpine

```
Blade view                           bundle (resources/js)
-----------                          ---------------------
<div data-linear-app>                 main.ts
<script type="application/json"        └─ mount()
        id="linear-settings">            ├─ querySelector('[data-linear-app]')            -> 'missing-root'
  { ...Settings payload... }             ├─ readSettings(document)
</script>                                │    ├─ JSON.parse                                 -> SettingsError
<script type="module" src="...main">     │    └─ isSettings(parsed) runtime guard          -> SettingsError
                                         │   (failure: renderFatalError() + 'invalid-settings')
                                         ├─ applyBrand(settings.brand, container)   --linear-accent
                                         ├─ registerComponents(Alpine, { settings, client })
                                         ├─ container.innerHTML = renderApp()        static template
                                         └─ Alpine.start()
                                              └─ x-data="linearApp()" builds state from `settings`
```

1. The server renders `<script type="application/json" id="linear-settings">` containing the `Settings` payload from the contract (CSRF token, URLs, connection, destination, failures, flash, back link).
2. `readSettings()` parses it and runs `isSettings()`. Anything missing or malformed produces a `SettingsError`, and `mount()` replaces the page with a friendly, accessible error (`renderFatalError`) instead of a half-working UI. `mount()` returns `'mounted' | 'missing-root' | 'invalid-settings'` rather than throwing.
3. `createClient(settings)` captures the CSRF token and URL templates. `registerComponents()` binds that `settings`/`client` pair into every `Alpine.data` factory.
4. `renderApp()` returns the markup. It never interpolates runtime data: URLs, the CSRF token, names and messages all reach the DOM through Alpine bindings (`x-text`, `:href`, `:value`), which set properties instead of parsing HTML. That keeps the templates static and XSS-safe.
5. `Alpine.start()` instantiates `linearApp()` (root state copied from `settings`) and the nested `connectionCard`, `destinationForm` and `failuresList` components.

Connect, API-key and disconnect are ordinary HTML form posts (CSRF `_token`, and `_method=DELETE` for disconnect) that navigate the whole page; the server redirects back with a flash message that arrives in `settings.flash`. Destination changes and retries go through the JSON API via `createClient()`.

## How components talk to each other

State flows **down** through Alpine's scope chain and changes flow **up** as DOM events.

- Child templates may *read* root state (`connection`, `failures`, `settings`, `flash`, `formatDate()`, ...). Alpine merges parent scopes into children.
- Children never *write* root state. They dispatch a bubbling `CustomEvent` from their own host element, and the root listens (`x-on:...` on the root element in `ui/template.ts`).

| Event | `detail` | Dispatched by | Root reaction |
|---|---|---|---|
| `linear-flash` | `{ kind: 'status' \| 'error', message }` | destination form, failures list | Shows the banner |
| `linear-destination-changed` | `{ destination: Destination \| null }` | destination form | Updates the summary line |
| `linear-reconnect-required` | `{ message }` | any component that gets a 409 `reconnect` | Marks the connection `needs_reconnect`, shows the error, hides the destination form |
| `linear-failure-removed` | `{ id }` | failures list (optimistic) | Removes the item |
| `linear-failure-restored` | `{ failure, index }` | failures list (request failed) | Puts the item back at `index` |

Event names are defined once in `components/events.ts` (`LINEAR_EVENTS`) with their payload types in `LinearEventMap` (`types/index.ts`). Names deliberately use dashes, not colons.

> **Why events are dispatched from a host element and not `this.$dispatch`:** Alpine binds magics such as `$dispatch`, `$refs` and `$el` to the element that *invoked* a method (the button that was clicked), not to the component. After an `await`, that button may already have been removed by an `x-if` or `x-for`, and an event dispatched from a detached element never reaches the root. So each component receives its own host element once, from its `x-data` expression (`x-data="failuresList($el)"`), and dispatches from that. The same reasoning is why focus management uses `data-linear-ref="name"` attributes looked up inside the host (`focusRef()` in `events.ts`) instead of `x-ref`/`$refs`. `$nextTick` is the one magic that is safe to use, because it is element-independent.

## Running the dev server against the workbench

The backend resolves the entry from the Vite dev server instead of `public/build` when `LINEAR_VITE_DEV_URL` is set (`config('linear.vite_dev_url')`). The manifest is not consulted in that mode.

You need two terminals:

```bash
# 1. Vite dev server with HMR on http://localhost:5173
npm run dev

# 2. The Testbench workbench app, pointed at the dev server
LINEAR_VITE_DEV_URL=http://localhost:5173 composer serve
```

Then open the URL `composer serve` prints (the workbench `start` path is `/linear`).

Notes:

- `composer serve` first runs `npm run build` (so the workbench also works without the dev server) and then `testbench serve`. With the env var set, the page ignores that build and loads `http://localhost:5173/@vite/client` and `http://localhost:5173/resources/js/main.ts`.
- `vite.config.ts` pins port `5173` (`strictPort`), enables CORS (the page is served from another origin, `localhost:8000`) and sets `server.origin` so asset URLs generated by Vite are absolute and point at the dev server. If you change the port, change it in `vite.config.ts` **and** in `LINEAR_VITE_DEV_URL`.
- Edits to `resources/js/**` and `resources/css/**` hot-reload. Because the root component's state is created once at `Alpine.start()`, a change to a template string or component usually triggers a full reload; that is expected.
- If the page shows an unstyled header only, the bundle did not load: check the browser console for a blocked request to port 5173 and that `LINEAR_VITE_DEV_URL` has no trailing path.
- To exercise states that are awkward to reach with real data (outages, `409` reconnects, validation errors), edit the workbench seed data or stub `window.fetch` in the browser console; the client only uses `fetch`.

## Rebuilding and committing assets

The compiled bundle is **committed** so Composer users never need Node.

```bash
npm run build
git add public/build
```

- Output: `public/build/assets/main-[hash].js`, `public/build/assets/main-[hash].css` and the manifest at `public/build/.vite/manifest.json`.
- The manifest entry is keyed by the source path, `resources/js/main.ts`, and lists its stylesheet under `css`. This is what `Dniccum\Linear\Support\LinearAssets` reads to emit `<link>`/`<script type="module">` tags.
- Builds are deterministic (content-hashed names, no source maps, no absolute paths), so the same sources always produce the same files.
- **CI enforces this.** `.github/workflows/frontend.yml` runs `npm ci`, `typecheck`, `lint`, `test:coverage`, `build`, then `git diff --exit-code -- public/build`. If you change anything under `resources/` (or dependency versions) without rebuilding and committing, that last step fails.
- Always run `npm run build` with the locked dependencies (`npm ci`, not `npm install`) before committing, so a stray newer minor version does not change the output.

`public/build` must stay out of `.gitignore` (only the repo-root `/build` is ignored, which is a different directory) and `node_modules` must stay ignored.

## The select component

The five destination dropdowns (team, priority, project, status, assignee) are not native `<select>`s. They use `linearSelect` (`components/select.ts`), a custom select styled after shadcn/ui's Select and built on the WAI-ARIA *select-only combobox* pattern.

What it renders (via `selectTemplate()` in `ui/template.ts`):

- a `<button type="button" role="combobox">` trigger that keeps DOM focus the whole time (`aria-haspopup`, `aria-expanded`, `aria-controls`, `aria-labelledby`, `aria-activedescendant`, `aria-invalid`, `aria-busy`, `aria-describedby`);
- a `role="listbox"` popup with `role="option"` rows (`aria-selected`, a check icon on the selected row, a muted "No options" row when empty);
- a hidden `<input name="...">` carrying the value, so form semantics survive.

Behaviour: Enter, Space and the arrow keys open it; while open the arrows, Home and End move the highlight, Enter or Space choose, Escape closes and refocuses the trigger, Tab closes without selecting, and typing letters jumps to a matching item (the buffer resets after 500 ms). A click outside, another select opening, or a window resize closes it. The popup opens below the trigger and flips above it when there is more room there; its height is capped at 320px (or the available room) and it scrolls. Choosing the value that is already selected closes the popup but does not call `onSelect`, matching a native `change` event.

The component never owns the value; it reads and writes the parent's state through closures that Alpine evaluates in the *enclosing* component's scope:

```ts
selectTemplate({
  id: 'linear-project',               // trigger id; the <label> needs for="linear-project" and id="linear-project-label"
  name: 'projectId',                  // hidden input name
  value: 'draft.projectId',           // expression: current value (string | number)
  items: 'projectItems',              // expression: SelectItem[] ({ value, label, disabled? })
  onSelect: "selectField('projectId', value)", // runs when a different item is chosen; `value` is the choice
  invalid: "hasError('projectId')",   // optional: placeholder, disabled, busy, invalid, describedBy, ref
  describedBy: 'linear-project-error',
})
```

To reuse it: build the items in a getter on the parent component (a `''` item is the "none" choice and is shown muted), add a handler that writes the value, put `selectTemplate({...})` in the field next to a `<label id="{id}-label" for="{id}">`, and style nothing else: the `.linear-select*` classes in `linear.css` (trigger, content, item, item--active, item--selected, check, chevron, empty) cover light, dark and mobile. `data-linear-ref` set through `ref` lands on the trigger, so `focusRef()` focuses the button. Use `selectTemplate` rather than writing the markup by hand so the ids and ARIA wiring stay consistent.

Tests: `__tests__/components/select.test.ts` drives the component directly (keyboard, typeahead, placement with mocked geometry, scrolling); `integration.test.ts` drives the real rendered selects with Alpine (`pick(app, id, label)` clicks the trigger and an option).

## Select visuals

Each select item can carry an optional leading visual (`SelectItem.visual`, typed `SelectVisual`), drawn in the open list and, for the selected item, in the trigger before the label. The logic lives in `ui/visuals.ts`, which has no Alpine or DOM dependency apart from `hideBrokenImage`.

| `kind` | Fields | Rendered as | Used for |
|---|---|---|---|
| `avatar` | `url` (`http(s)` or `null`), `initials`, `color` | A 20px circle with the initials on `color`, and, when there is a `url`, an `<img>` over it (`alt=""`, `loading="lazy"`, `referrerpolicy="no-referrer"`). On `error` the image is hidden (`onVisualError`), leaving the initials. | assignee |
| `tile` | `glyph` (emoji or 1-3 letters), `tone` (`solid` or `soft`), `color` | A rounded 20px tile; `solid` fills it with `color` and picks readable text, `soft` tints it and keeps the emoji colours. | team (solid), project with an emoji (soft) |
| `status` | `status` (`StatusKind`), `color` | Linear-style SVG tinted with `color`: dashed circle (backlog), ring (unstarted, and any unknown type), ring with a half pie (started), disc with a check (completed), disc with an x (canceled, duplicate), ring with an arrow (triage). | status |
| `priority` | `level` (0-4) | Three muted dashes, an orange rounded square with `!`, or a three-bar signal with 3/2/1 bars filled. | priority |
| `icon` | `name` (`user-empty`, `project`, `project-empty`, `status-empty`), `color` | A generic glyph; dashed ones are the muted "none" choices. | "Unassigned", "No project", "Team default", project without an emoji |

The `destination-form.ts` item getters build them with `teamVisual()`, `projectVisual()`, `stateVisual()`, `memberVisual()` and `priorityVisual()` (plus the `UNASSIGNED_VISUAL`, `NO_PROJECT_VISUAL` and `TEAM_DEFAULT_VISUAL` constants). The builders are where API data is checked, once:

- colours go through `normalizeHexColor()` and become a lowercase hex string or `null` (the stylesheet then uses the accent or muted colour);
- avatar URLs must parse as `http:` or `https:`, anything else (`javascript:`, `data:`, relative) becomes `null`;
- a status `type` is narrowed to the closed `StatusKind` list, unknown types draw as an empty circle;
- an `icon` is an emoji only if `emojiOf()` finds one at its start (or it is a known `:shortcode:`); otherwise teams show letters from their key and projects show the box glyph. `icon` is never treated as a URL.

**Rendering is safe by construction.** `selectTemplate()` renders the visual with `visualTemplate()`: initials and emoji through `x-text`, colours through `:style` custom properties (`--linear-visual-bg`, `--linear-visual-fg`, `--linear-visual-color`) produced by `visualStyle()`, the image through `:src`. The only `x-html` is `visualMarkup(visual)`, which returns a static SVG chosen from `STATUS_SVG`, `ICON_SVG` or `PRIORITY_SVG` by a closed enum, so no API string is ever parsed as HTML. The whole visual is `aria-hidden`; the accessible name stays the item label, and keyboard and ARIA behaviour is unchanged. Styles are the `.linear-visual*` classes in `linear.css`.

Old servers: `withTeamsDefaults()` and `withTeamOptionsDefaults()` in `schema.ts` run before the response guards and turn any missing (or non-string) `color`, `icon`, `avatarUrl`, `initials` or `avatarBackgroundColor` into `null`, so payloads from before these fields existed still validate and the selects simply show no colours.

### Adding a visual

1. Add the descriptor to the `SelectVisual` union in `ui/visuals.ts` (a new `kind` with only validated fields: hex strings or `null`, closed enums, `http(s)` URLs) and a builder that takes the API object and validates it. Add a test for each rejected input.
2. Teach the helpers about it: `visualStyle()` (the CSS custom properties), `visualClasses()` (BEM modifier), and either `visualText()` (text content) or `visualMarkup()` (a new static SVG constant, with no interpolated data). The `switch` statements make the compiler point at each place.
3. If it needs a new element shape, extend `visualTemplate()` in `ui/template.ts` (keep text in `x-text`, never put API data in `x-html`), and add its CSS under `.linear-visual--<kind>` in `linear.css`, using `--linear-visual-*` with theme defaults so it works in light, dark and at 375px.
4. Set `visual` in the item getter (`teamItems`, `projectItems`, ...) in `destination-form.ts`.
5. Tests: `__tests__/ui/visuals.test.ts` (builder, style, markup), `__tests__/ui/template.test.ts` (markup is decorative and static) and the `select visuals` block in `__tests__/integration.test.ts` (rendered in trigger and list). Coverage stays at 100% with no new exclusions.

## Expired sessions and the login redirect

The settings payload carries `urls.login` (`''` when the host has no login page; the client treats a payload without the key as `''`, see `withSettingsDefaults()` in `schema.ts`). `createClient()` takes an injectable third argument, `redirect` (default `location.assign`). When any request fails with HTTP 401 or 419:

- with a login URL, the browser is sent there, at most once however many requests fail together, and the request rejects with "Your session has expired. Redirecting to sign in…";
- with no login URL (or if `redirect` throws), it rejects with "Your session has expired. Please sign in again.", and a later failure tries the redirect again.

The server's own wording is never shown for these two statuses. Because the request still rejects, existing handlers restore their state as usual (loading flags reset, the failures list puts the optimistic item back). 403, 409, 422, 503 and the rest are not treated as login redirects.

## The back link

The settings payload carries `back: null | { label: string; url: string }` (typed as `BackLink` in `types/index.ts`). Both values are configured and resolved on the server; `null` means the link is disabled or its URL could not be resolved. A payload without the key (an older server) is treated as `null` by `withSettingsDefaults()` in `schema.ts`.

`isBackLink` validates it strictly: `label` must be a non-blank string, and `url` must pass `isSafeLinkUrl()`, which accepts only an absolute `http(s)` URL or a root-relative path starting with a single `/`. `javascript:`, `data:`, `mailto:`, protocol-relative (`//host`), bare words and anything containing whitespace or control characters are rejected, and a rejected payload fails `isSettings` like any other malformed field (the page shows the friendly fatal error).

`ui/template.ts` renders it above the header inside `<template x-if="settings.back">`: a plain `<a class="linear-back" :href="settings.back.url">` holding a decorative 16px `arrow-left`-style chevron (`aria-hidden`) and the label through `x-text`. It is a normal link (no extra landmark), so it is keyboard reachable and uses the shared `:focus-visible` outline. The label and URL only reach the DOM through `x-text` and `:href`, never as HTML. Styling is `.linear-back` in `resources/css/linear.css` (a quiet ghost-button link: muted text, hover background and text colour, 36px high).

## Adding a component, step by step

Example: a "Notifications" card that loads and saves a setting through a new endpoint.

1. **Define the contract first.** Add the endpoint and payload to [`docs/http-contract.md`](http-contract.md) and the matching PHP DTO. The TypeScript types must mirror the DTO.
2. **Mirror the types.** Add interfaces to `resources/js/types/index.ts` (camelCase keys, `string | null` for nullable PHP properties, unions for enums). Add any new event to `LinearEventMap`.
3. **Add a runtime guard.** In `schema.ts`, declare it against the interface so drift fails to compile:

   ```ts
   export const isNotifications: Guard<Notifications> = shape<Notifications>({
     enabled: isBoolean,
     channel: nullable(isString),
   });
   ```

4. **Extend the client.** Add a method to `LinearClient` and `createClient()` in `api/client.ts`. Reuse `request()` so headers, CSRF, error narrowing (`ApiError`) and response validation come for free. If the URL comes from the settings payload, add it to `Urls` (`types/index.ts`) and to `isUrls` (`schema.ts`), plus `ClientConfig` if the client needs it.
5. **Write the component** in `components/notifications-card.ts`:

   ```ts
   export interface NotificationsCardComponent {
     enabled: boolean;
     saving: boolean;
     save(): Promise<void>;
   }

   export function notificationsCard({ host, client }: Deps): NotificationsCardComponent {
     const component: NotificationsCardComponent & ThisType<NotificationsCardComponent & AlpineMagics> = {
       enabled: false,
       saving: false,
       async save() {
         this.saving = true;
         try {
           await client.saveNotifications({ enabled: this.enabled });
           emit(host, LINEAR_EVENTS.flash, { kind: 'status', message: 'Saved.' });
         } catch (error) {
           emit(host, LINEAR_EVENTS.flash, { kind: 'error', message: errorMessage(error) });
           reportReconnect(host, error);
         } finally {
           this.saving = false;
         }
       },
     };
     return component;
   }
   ```

   Rules of thumb: take the host element and dispatch from it; use `this` for state (the object is wrapped in a reactive proxy, so mutate through `this`, never through a captured variable); keep logic in methods/getters and keep template expressions trivial.
6. **Register it** in `components/index.ts` (`COMPONENT_NAMES` + `registry.data(...)`).
7. **Add markup** in `ui/template.ts` as a new section function and include it in `renderApp()`. Use `x-data="notificationsCard($el)"`. Never interpolate data into the string; bind with Alpine. Give every control a `<label>`, group radios/checkboxes in a `<fieldset>`, and put errors in `role="alert"`/`aria-live` regions.
8. **Style it** in `resources/css/linear.css` with `.linear-*` BEM classes and the existing custom properties (`--linear-accent`, `--linear-surface`, ...). Check dark mode and a 375px viewport.
9. **Test it** (see below): unit tests for the component and, for template wiring, a flow in `__tests__/integration.test.ts`. Coverage must stay at 100%.
10. **Verify and rebuild:** `npm run typecheck && npm run lint && npm run test:coverage && npm run build`, then commit `public/build`.

## TypeScript conventions

`tsconfig.json` is as strict as TypeScript allows:

| Flag | Effect |
|---|---|
| `strict`, `noImplicitAny`, `useUnknownInCatchVariables` | No implicit `any`; `catch (e)` is `unknown` and must be narrowed. |
| `noUncheckedIndexedAccess` | `array[i]` and `record[key]` are `T \| undefined`; handle it (`?.`, `?? fallback`). |
| `exactOptionalPropertyTypes` | `{ a?: string }` does not accept `a: undefined`; omit the key instead. |
| `noImplicitReturns`, `noFallthroughCasesInSwitch` | Every code path returns; no accidental fall-through. |
| `noUnusedLocals`, `noUnusedParameters` | Dead code does not compile (prefix intentionally unused params with `_`). |
| `verbatimModuleSyntax`, `isolatedModules` | Type-only imports must use `import type`. |
| `moduleResolution: bundler`, `target: ES2022`, `lib: ES2022 + DOM` | Matches what Vite builds. |

Conventions:

- **No `any`, no `!`.** ESLint errors on `any` and non-null assertions. Use `unknown` plus a guard, or fix the type.
- **Types mirror the PHP DTOs.** `types/index.ts` is a 1:1 copy of the payloads in [`docs/http-contract.md`](http-contract.md), with the same camelCase keys. When a DTO changes, change the interface *and* its guard in `schema.ts` in the same commit.
- **Validate at the boundary, trust inside.** The two untrusted inputs are the embedded settings JSON (`readSettings`) and API responses (`api/client.ts` validates each against its guard and throws `ApiError` on a mismatch). Past that, code uses the precise types.
- **Errors are values, not strings.** Everything the API layer throws is an `ApiError` (`status`, `message`, `fieldErrors` for 422, `reconnect` for 409; `status: 0` for network failures). Use `errorMessage(error)` to display anything caught.
- **Annotate exported functions.** `explicit-function-return-type` is enforced, so public signatures are explicit.
- **Component objects use `ThisType`.** Declare the component as an interface, then build it as `Interface & ThisType<Interface & AlpineMagics>` so `this` is fully typed inside methods and getters without per-method annotations.
- **Prefer pure helpers.** Anything that does not need component state belongs in a plain function (`destination-draft.ts`, `brand.ts`, `format.ts`), which is trivial to test.
- **Imports:** `import type { ... }` for types (`consistent-type-imports`).

## Testing

```bash
npm test                # vitest run
npm run test:coverage   # + v8 coverage; fails below 100% for lines, branches, functions and statements
```

Vitest runs in `jsdom`. Tests live in `resources/js/__tests__/` (mirroring the source tree) and `setup.ts` empties `document.body` after every test.

There are three layers:

1. **Pure and unit tests**: guards, schema, brand, formatting, `ApiError`, the draft helpers, and every component factory called directly as a plain object. Dependencies (`LinearClient`, the host element, `$nextTick`) are fakes from `fixtures.ts`.
2. **`mount()` and `main.ts`**: `mount(document, fakeAlpine)` covers success, missing root, missing JSON, invalid JSON and invalid shape; `main.test.ts` imports the real bootstrap.
3. **Integration tests** (`integration.test.ts`): the real Alpine runtime renders the real templates in jsdom with only `fetch` mocked. They catch what unit tests cannot: typos in Alpine expressions, `x-if`/`x-for` lifecycles, event wiring, and accessibility of the rendered page.

Helpers in `__tests__/fixtures.ts`: `makeSettings()` and friends (valid payloads with overrides), `jsonResponse()`, `deferred()` (control the order of async responses), `captureEvents()`, `makeHost()` and `withMagics()`.

Tips and gotchas:

- Mock `fetch` only (`vi.stubGlobal('fetch', ...)` or inject a `FetchLike` into `createClient`). Never mock the client in integration tests.
- In integration tests call `vi.resetModules()` and dynamically import `../mount` so each test gets a fresh Alpine instance, and call `window.Alpine.stopObservingMutations()` afterwards so an old instance does not touch the next test's DOM.
- Alpine updates the DOM asynchronously; assert with `await vi.waitFor(() => expect(...))`, not fixed sleeps.
- `<template x-if>`/`x-for` siblings are DOM siblings of the rendered nodes. Use `:nth-of-type`, not `:nth-child`, when selecting repeated items.
- Coverage is measured over `resources/js/**/*.ts` excluding tests. The only exclusion is `types/index.ts`, which contains only interfaces and `declare global` and compiles to an empty module. If a branch is genuinely unreachable, delete the dead code rather than adding an ignore comment.

## Linting

```bash
npm run lint        # eslint resources/js
npm run lint:fix
```

`eslint.config.js` is a flat config using `@eslint/js` recommended plus `typescript-eslint` **type-checked** recommended rules (it needs `tsconfig.json`, so lint after a `tsc` pass if you see parser errors). On top of those it enforces:

- `@typescript-eslint/no-explicit-any`: error
- `@typescript-eslint/no-non-null-assertion`: error
- `@typescript-eslint/consistent-type-imports`: error
- `@typescript-eslint/explicit-function-return-type`: error (expressions and contextually typed callbacks are allowed)
- `eqeqeq`, `no-console` (only `console.error`/`console.warn` allowed)

Test files relax `explicit-function-return-type`, `require-await` and `unbound-method` only, because mock handlers and `expect(spy.method)` trip them.

## Scripts

| Script | What it does |
|---|---|
| `npm run dev` | Vite dev server on `http://localhost:5173` (HMR, CORS, absolute `server.origin`). |
| `npm run build` | Production build into `public/build` (empties it first) with `.vite/manifest.json`. |
| `npm run typecheck` | `tsc --noEmit` over `resources/js` and the config files. |
| `npm run lint` / `lint:fix` | ESLint over `resources/js`. |
| `npm test` | `vitest run`. |
| `npm run test:coverage` | `vitest run --coverage` with 100% thresholds. |

## Publishing and customising assets (for package users)

Package users never run Node. They publish the compiled bundle (and optionally the views) with Artisan.

```bash
# Copy public/build to public/vendor/linear (path set by config('linear.assets_path'))
php artisan vendor:publish --tag=linear-assets

# Copy the Blade views to resources/views/vendor/linear
php artisan vendor:publish --tag=linear-views
```

Re-publish assets after upgrading the package (`--force` overwrites the previous build). This is required: the manifest is read from the installed package but the files are served from the published copy, so stale assets make the page link to bundles that no longer exist. Automate it with a `post-update-cmd` script in the application's `composer.json` (`@php artisan vendor:publish --tag=linear-assets --ansi --force`); see the README. The `@linearAssets` directive (and `<x-linear::assets />`) reads the manifest and emits the `<link>` and `<script type="module">` tags with `asset('vendor/linear/...')` URLs.

Ways to customise, from least to most invasive:

1. **Brand.** `config('linear.brand')` (`name`, `logo`, `color`) arrives as `settings.brand`. The colour is validated (3, 4, 6 or 8 digit hex) and applied as `--linear-accent`, with a readable `--linear-accent-contrast` computed for button text. Invalid colours are ignored and the default `#5E6AD2` stays.
2. **CSS custom properties.** Override tokens from your own stylesheet loaded after the bundle's, for example:

   ```css
   .linear-app {
     --linear-radius: 4px;
     --linear-surface: #fffdf8;
     --linear-font: "Inter", system-ui, sans-serif;
   }
   ```

   Available tokens: `--linear-accent`, `--linear-accent-contrast`, `--linear-bg`, `--linear-surface`, `--linear-surface-muted`, `--linear-border`, `--linear-border-strong`, `--linear-text`, `--linear-text-muted`, `--linear-success[-bg]`, `--linear-warning[-bg]`, `--linear-danger[-bg]`, `--linear-radius`, `--linear-radius-sm`, `--linear-shadow`, `--linear-font`. Dark mode follows `prefers-color-scheme`; override the tokens inside a `@media (prefers-color-scheme: dark)` block to restyle it.
3. **Layout.** The standalone page is `linear::layout` + `linear::settings`. Publish the views and edit them, or embed `<x-linear::settings />` in your own layout. The app is a self-contained panel (own background and text colour), so it stays readable inside any host layout; it only fills the viewport when it is a direct child of `<body>`.
4. **Replace the bundle.** Point `config('linear.assets_path')` at your own build, or skip `@linearAssets` (`<x-linear::settings :assets="false" />`) and load your own script. Your script must read the same `#linear-settings` JSON (see the contract) and mount into `[data-linear-app]`.

## Building a fully custom UI

You do not have to use this bundle. There are two supported ways to build your own.

### Against the HTTP contract

Everything the bundle does uses the routes in [`docs/http-contract.md`](http-contract.md). Render your own page, feed it the settings payload (`app(\Dniccum\Linear\Linear::class)->settingsFor($owner)->toArray()`, or `toScriptJson()` for a `<script type="application/json">` block), and call the endpoints:

```ts
const res = await fetch(settings.urls.teams, {
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-CSRF-TOKEN': settings.csrf,
  },
  credentials: 'same-origin',
});
const { teams } = await res.json();
```

Remember:

- Send `Accept: application/json` and the CSRF token (`X-CSRF-TOKEN`) on every call; the API group also forces JSON responses.
- `urls.teamOptions` and `urls.retry` contain the literal `{team}` and `{link}` placeholders: substitute them with `encodeURIComponent(id)`.
- Validation failures are Laravel's `422 { message, errors: { field: string[] } }`; field keys are the request keys (`labelIds.0` for array items). Outages are `503 { message }`. A connection that must be re-authorised is `409 { message, reconnect: true }`; send the user to `urls.connect` (OAuth) or `urls.apiKey` (API key mode).
- Connect, API key and disconnect are form posts that redirect: `POST urls.apiKey` with an `api_key` field, `DELETE urls.disconnect` (`_method=DELETE` on a POST form), both with a `_token` CSRF field. Their result comes back in `settings.flash` on the next render.
- You can reuse the typed pieces from this repo (`types/index.ts`, `api/client.ts`) in your own TypeScript project by copying them; they have no dependency on Alpine.

### Against the PHP Actions

If you would rather not go through HTTP at all (a Livewire or Inertia screen, a console command, a queue job), call the Actions in `src/Actions` directly. They are what the controllers use, so behaviour is identical:

| Action | Purpose |
|---|---|
| `BuildSettings` | Build the `Settings` payload for an owner. |
| `BuildConnectUrl` | Start OAuth (state + PKCE) and return the Linear authorisation URL. |
| `HandleOAuthCallback` | Finish OAuth and store the connection. |
| `SaveApiKey` | Store a personal API key (API key mode). |
| `DisconnectLinear` | Remove the connection. |
| `ListTeams`, `ListTeamOptions` | The data behind `GET teams` and `GET teams/{team}/options`. |
| `SaveDestination`, `DeleteDestination` | Persist or remove the destination. |
| `RetryFailedSync` | Retry a failed issue or comment delivery. |

Resolve them from the container and call `execute()` (every action takes the owner model first; for example `app(ListTeams::class)->execute($owner)` or `app(SaveDestination::class)->execute($owner, $destination, $sendMode)`; see each class for its exact signature). You can turn off the built-in routes entirely with `config('linear.routes.*')` or `Linear::ignoreRoutes()` and keep only the Actions.

## Notes: accessibility, CSP, localisation

- **Accessibility.** One `h1`, labelled `section`s, a `label` for every control (the custom selects link their `<label>` through `for` and `aria-labelledby`), `fieldset`/`legend` for the send-mode radios and label checkboxes, `aria-describedby` for hints and errors, `aria-invalid` on failing fields, persistent `role="status"`/`role="alert"` live regions for banners, `aria-busy` + a visible and screen-reader caption while options load, visible `:focus-visible` outlines, focus moved to the right control after disclosure/removal, `prefers-reduced-motion` and `forced-colors` support. The integration tests assert label and `aria-describedby` integrity on the rendered page; keep them green when you add UI.
- **Content Security Policy.** Alpine's standard build evaluates expressions with `new Function`, which needs `'unsafe-eval'`. Strict-CSP hosts can switch to `@alpinejs/csp`, but its expression grammar is more limited (no inline `&&`/ternaries), so the templates and components would need adjusting. The templates already keep logic in getters and methods to make that practical, with one exception: the select visuals use a few inline conditions (`item.visual?.kind === 'tile'`) that would move into helpers. The avatar images are loaded directly from Linear, so a strict policy also needs `img-src` to allow their hosts (see the README); the initials fallback shows when they are blocked.
- **Localisation.** User-facing strings are English literals in `ui/template.ts` and the component files. To localise, send a dictionary in the settings payload (extend `Settings` and `isSettings`) and look strings up where they are produced.
