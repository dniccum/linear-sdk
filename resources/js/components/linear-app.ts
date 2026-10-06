import { formatDateTime } from '../format';
import type {
  AuthMode,
  Connection,
  Destination,
  FailureItem,
  Flash,
  LinearEventMap,
  Settings,
} from '../types';

/** Which state the connection card is in; drives its copy and actions. */
export type ConnectionView = 'unconfigured' | 'disconnected' | 'active' | 'needs_reconnect';

export type StatusTone = 'success' | 'warning' | 'muted';

interface ViewCopy {
  label: string;
  tone: StatusTone;
}

const VIEW_COPY: Readonly<Record<ConnectionView, ViewCopy>> = {
  unconfigured: { label: 'Not configured', tone: 'muted' },
  disconnected: { label: 'Not connected', tone: 'muted' },
  active: { label: 'Connected', tone: 'success' },
  needs_reconnect: { label: 'Needs reconnect', tone: 'warning' },
};

const DISCONNECTED_DESCRIPTION: Readonly<Record<AuthMode, string>> = {
  oauth: 'Authorise access to your Linear workspace so issues can be created.',
  api_key: 'Paste a Linear API key so issues can be created.',
};

/**
 * Root component (`x-data="linearApp()"`). It is the single source of truth for
 * the connection, destination, failures and flash banner; child components
 * report changes through the events in `events.ts`.
 */
export interface LinearAppComponent {
  readonly settings: Settings;
  connection: Connection | null;
  destination: Destination | null;
  failures: FailureItem[];
  flash: Flash;

  readonly connectionView: ConnectionView;
  readonly statusLabel: string;
  readonly statusTone: StatusTone;
  readonly connectionDescription: string;
  readonly isConnected: boolean;
  readonly needsReconnect: boolean;
  /** Connected and healthy, so the destination can be configured. */
  readonly canConfigure: boolean;
  /** The user must connect (or reconnect) before anything else works. */
  readonly actionRequired: boolean;
  readonly showConnectLink: boolean;
  readonly showApiKeyForm: boolean;
  readonly connectLabel: string;
  readonly apiKeyLabel: string;
  readonly organizationLabel: string;
  readonly accountLabel: string;
  readonly destinationSummary: string | null;
  readonly hasFailures: boolean;
  readonly failuresSummary: string;

  formatDate(iso: string | null): string;
  dismissStatus(): void;
  dismissError(): void;
  onFlash(detail: LinearEventMap['linear-flash']): void;
  onDestinationChanged(detail: LinearEventMap['linear-destination-changed']): void;
  onReconnectRequired(detail: LinearEventMap['linear-reconnect-required']): void;
  onFailureRemoved(detail: LinearEventMap['linear-failure-removed']): void;
  onFailureRestored(detail: LinearEventMap['linear-failure-restored']): void;
}

export function linearApp(settings: Settings): LinearAppComponent {
  const component: LinearAppComponent & ThisType<LinearAppComponent> = {
    settings,
    // Copies, so runtime changes never mutate the parsed payload.
    connection: settings.connection === null ? null : { ...settings.connection },
    destination: settings.destination === null ? null : { ...settings.destination },
    failures: settings.failures.map((failure) => ({ ...failure })),
    flash: { ...settings.flash },

    get connectionView(): ConnectionView {
      if (this.connection !== null) {
        return this.connection.status;
      }

      return this.settings.configured ? 'disconnected' : 'unconfigured';
    },

    get statusLabel(): string {
      return VIEW_COPY[this.connectionView].label;
    },

    get statusTone(): StatusTone {
      return VIEW_COPY[this.connectionView].tone;
    },

    get connectionDescription(): string {
      switch (this.connectionView) {
        case 'unconfigured':
          return 'Add your Linear credentials to the package configuration, then reload this page.';
        case 'disconnected':
          return DISCONNECTED_DESCRIPTION[this.settings.authMode];
        case 'active':
          return 'Your Linear workspace is connected.';
        case 'needs_reconnect':
          return 'Linear access was revoked or expired. Reconnect to resume creating issues.';
      }
    },

    get isConnected(): boolean {
      return this.connection !== null;
    },

    get needsReconnect(): boolean {
      return this.connectionView === 'needs_reconnect';
    },

    get canConfigure(): boolean {
      return this.connectionView === 'active';
    },

    get actionRequired(): boolean {
      return this.connectionView === 'disconnected' || this.connectionView === 'needs_reconnect';
    },

    get showConnectLink(): boolean {
      return this.actionRequired && this.settings.authMode === 'oauth';
    },

    get showApiKeyForm(): boolean {
      return this.actionRequired && this.settings.authMode === 'api_key';
    },

    get connectLabel(): string {
      return this.needsReconnect ? 'Reconnect to Linear' : 'Connect with Linear';
    },

    get apiKeyLabel(): string {
      return this.needsReconnect ? 'Replace API key' : 'Connect';
    },

    get organizationLabel(): string {
      return this.connection?.organizationName ?? 'Linear workspace';
    },

    get accountLabel(): string {
      const name = this.connection?.userName ?? null;
      const email = this.connection?.userEmail ?? null;

      if (name !== null && email !== null) {
        return `${name} (${email})`;
      }

      return name ?? email ?? '';
    },

    get destinationSummary(): string | null {
      if (this.destination === null) {
        return null;
      }

      const team = this.destination.teamName ?? 'the selected team';

      return this.destination.sendMode === 'automatic'
        ? `New issues are sent to ${team} automatically.`
        : `Issues are sent to ${team} when you choose to send them.`;
    },

    get hasFailures(): boolean {
      return this.failures.length > 0;
    },

    get failuresSummary(): string {
      const count = this.failures.length;

      return `${count} ${count === 1 ? 'delivery' : 'deliveries'} failed. Retry once the underlying problem is fixed.`;
    },

    formatDate(iso) {
      return formatDateTime(iso);
    },

    dismissStatus() {
      this.flash = { status: null, error: this.flash.error };
    },

    dismissError() {
      this.flash = { status: this.flash.status, error: null };
    },

    onFlash({ kind, message }) {
      this.flash = {
        status: kind === 'status' ? message : null,
        error: kind === 'error' ? message : null,
      };
    },

    onDestinationChanged({ destination }) {
      this.destination = destination;
    },

    onReconnectRequired({ message }) {
      if (this.connection !== null) {
        this.connection = { ...this.connection, status: 'needs_reconnect', lastError: message };
      }

      this.flash = { status: null, error: message };
    },

    onFailureRemoved({ id }) {
      this.failures = this.failures.filter((failure) => failure.id !== id);
    },

    onFailureRestored({ failure, index }) {
      if (this.failures.some((existing) => existing.id === failure.id)) {
        return;
      }

      const next = [...this.failures];
      next.splice(Math.min(index, next.length), 0, failure);
      this.failures = next;
    },
  };

  return component;
}
