import { afterEach } from 'vitest';

// Every test starts with an empty document so leftover hosts or app markup
// from an earlier test can never satisfy a later assertion.
afterEach(() => {
  document.body.replaceChildren();
  document.documentElement.removeAttribute('style');
});
