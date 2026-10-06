import { describe, expect, it } from 'vitest';
import { ApiError, errorMessage, GENERIC_ERROR_MESSAGE, isApiError } from '../../api/errors';

describe('ApiError', () => {
  it('carries status, message, field errors and reconnect', () => {
    const cause = new Error('socket hang up');
    const error = new ApiError('Invalid', {
      status: 422,
      fieldErrors: { teamId: ['Required'] },
      reconnect: false,
      cause,
    });

    expect(error).toBeInstanceOf(Error);
    expect(error.name).toBe('ApiError');
    expect(error.message).toBe('Invalid');
    expect(error.status).toBe(422);
    expect(error.fieldErrors).toEqual({ teamId: ['Required'] });
    expect(error.reconnect).toBe(false);
    expect(error.cause).toBe(cause);
  });

  it('defaults field errors to empty and reconnect to false', () => {
    const error = new ApiError('Boom', { status: 500 });

    expect(error.fieldErrors).toEqual({});
    expect(error.reconnect).toBe(false);
  });

  it('exposes isValidation and isNetwork helpers', () => {
    expect(new ApiError('x', { status: 422 }).isValidation).toBe(true);
    expect(new ApiError('x', { status: 500 }).isValidation).toBe(false);
    expect(new ApiError('x', { status: 0 }).isNetwork).toBe(true);
    expect(new ApiError('x', { status: 503 }).isNetwork).toBe(false);
  });

  it('can be reconnect-flagged', () => {
    expect(new ApiError('x', { status: 409, reconnect: true }).reconnect).toBe(true);
  });
});

describe('isApiError', () => {
  it('recognises ApiError instances only', () => {
    expect(isApiError(new ApiError('x', { status: 400 }))).toBe(true);
    expect(isApiError(new Error('x'))).toBe(false);
    expect(isApiError('x')).toBe(false);
  });
});

describe('errorMessage', () => {
  it('uses the message of an Error', () => {
    expect(errorMessage(new Error('Nope'))).toBe('Nope');
    expect(errorMessage(new ApiError('Linear is down', { status: 503 }))).toBe('Linear is down');
  });

  it('falls back to a generic message for empty messages and non-errors', () => {
    expect(errorMessage(new Error(''))).toBe(GENERIC_ERROR_MESSAGE);
    expect(errorMessage('a string')).toBe(GENERIC_ERROR_MESSAGE);
    expect(errorMessage(undefined)).toBe(GENERIC_ERROR_MESSAGE);
  });
});
