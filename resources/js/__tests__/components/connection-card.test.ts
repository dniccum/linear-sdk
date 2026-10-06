import { describe, expect, it } from 'vitest';
import { API_KEY_REQUIRED_MESSAGE, connectionCard } from '../../components/connection-card';
import { makeHost, withMagics } from '../fixtures';

function setup() {
  const host = makeHost(['apiKey', 'disconnect', 'confirmDisconnect']);
  const card = withMagics(connectionCard(host));
  return { host, card };
}

function focusedRef(): string | null {
  return document.activeElement?.getAttribute('data-linear-ref') ?? null;
}

describe('connectionCard', () => {
  it('starts idle', () => {
    const { card } = setup();

    expect(card.apiKey).toBe('');
    expect(card.apiKeyError).toBeNull();
    expect(card.submitting).toBe(false);
    expect(card.confirmingDisconnect).toBe(false);
  });

  it('asks for confirmation and moves focus to the confirm button', () => {
    const { card } = setup();

    card.askDisconnect();

    expect(card.confirmingDisconnect).toBe(true);
    expect(focusedRef()).toBe('confirmDisconnect');
  });

  it('cancels the confirmation and returns focus to the disconnect button', () => {
    const { card } = setup();
    card.askDisconnect();

    card.cancelDisconnect();

    expect(card.confirmingDisconnect).toBe(false);
    expect(focusedRef()).toBe('disconnect');
  });

  it('disables the buttons once the disconnect form is submitted', () => {
    const { card } = setup();

    card.submitDisconnect();

    expect(card.submitting).toBe(true);
  });

  it('blocks submitting a blank API key and focuses the field', () => {
    const { card } = setup();
    card.apiKey = '   ';
    const event = new Event('submit', { cancelable: true });

    card.submitApiKey(event);

    expect(event.defaultPrevented).toBe(true);
    expect(card.apiKeyError).toBe(API_KEY_REQUIRED_MESSAGE);
    expect(card.submitting).toBe(false);
    expect(focusedRef()).toBe('apiKey');
  });

  it('lets a filled-in API key form submit natively', () => {
    const { card } = setup();
    card.apiKey = 'lin_api_abc';
    card.apiKeyError = API_KEY_REQUIRED_MESSAGE;
    const event = new Event('submit', { cancelable: true });

    card.submitApiKey(event);

    expect(event.defaultPrevented).toBe(false);
    expect(card.apiKeyError).toBeNull();
    expect(card.submitting).toBe(true);
  });
});
