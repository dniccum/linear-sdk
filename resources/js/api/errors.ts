export interface ApiErrorOptions {
  /** HTTP status; `0` means the request never reached the server. */
  status: number;
  /** Laravel validation messages keyed by field (HTTP 422). */
  fieldErrors?: Record<string, string[]>;
  /** The connection must be re-authorised (HTTP 409 with `reconnect: true`). */
  reconnect?: boolean;
  cause?: unknown;
}

/** Every failure of the JSON API surfaces as one of these. */
export class ApiError extends Error {
  readonly status: number;
  readonly fieldErrors: Readonly<Record<string, string[]>>;
  readonly reconnect: boolean;

  constructor(message: string, options: ApiErrorOptions) {
    super(message, { cause: options.cause });
    this.name = 'ApiError';
    this.status = options.status;
    this.fieldErrors = options.fieldErrors ?? {};
    this.reconnect = options.reconnect ?? false;
  }

  /** The request failed validation (HTTP 422). */
  get isValidation(): boolean {
    return this.status === 422;
  }

  /** The request never produced an HTTP response (offline, DNS, CORS, ...). */
  get isNetwork(): boolean {
    return this.status === 0;
  }
}

export function isApiError(error: unknown): error is ApiError {
  return error instanceof ApiError;
}

export const GENERIC_ERROR_MESSAGE = 'Something went wrong. Please try again.';

/** A displayable message for anything thrown by the API layer. */
export function errorMessage(error: unknown): string {
  return error instanceof Error && error.message !== '' ? error.message : GENERIC_ERROR_MESSAGE;
}
