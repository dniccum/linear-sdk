import type { LinearClient } from '../api/client';
import { errorMessage, isApiError } from '../api/errors';
import { isValidHexColor } from '../brand';
import type {
  AlpineMagics,
  Destination,
  Label,
  LoadState,
  Team,
  TeamOptions,
} from '../types';
import {
  draftFromDestination,
  draftToPayload,
  emptyDraft,
  emptyOptions,
  groupFieldErrors,
  PRIORITY_OPTIONS,
  pruneDraft,
  type DestinationDraft,
  type PriorityOption,
} from './destination-draft';
import { emit, focusRef, LINEAR_EVENTS, reportReconnect } from './events';
import type { SelectItem, SelectValue } from './select';
import {
  memberVisual,
  NO_PROJECT_VISUAL,
  priorityVisual,
  projectVisual,
  stateVisual,
  TEAM_DEFAULT_VISUAL,
  teamVisual,
  UNASSIGNED_VISUAL,
} from '../ui/visuals';

export const SAVED_MESSAGE = 'Destination saved.';
export const REMOVED_MESSAGE = 'Destination settings removed.';
export const FIELD_ERRORS_MESSAGE = 'Check the highlighted fields and try again.';

/** The draft fields bound to a string-valued select (`''` means "none"). */
export type SelectField = 'projectId' | 'stateId' | 'assigneeId';

export interface DestinationFormDeps {
  /** The component's own `x-data` element; events are dispatched from it. */
  host: HTMLElement;
  /** The destination rendered with the page, or `null` when none is saved. */
  destination: Destination | null;
  client: Pick<LinearClient, 'getTeams' | 'getTeamOptions' | 'saveDestination' | 'deleteDestination'>;
}

/** The `x-data="destinationForm($el)"` component: team/option loading, validation and persistence. */
export interface DestinationFormComponent {
  draft: DestinationDraft;
  teams: Team[];
  teamsState: LoadState;
  teamsError: string | null;
  /** Never `null`: empty lists until a team's options have loaded. */
  options: TeamOptions;
  optionsState: LoadState;
  optionsError: string | null;
  saving: boolean;
  removing: boolean;
  confirmingRemove: boolean;
  /** Whether a destination is persisted on the server (enables "Remove settings"). */
  hasSavedDestination: boolean;
  /** Non-field error shown above the form. */
  formError: string | null;
  /** Validation messages by camelCase field name. */
  errors: Record<string, string[]>;
  /** Guards against out-of-order option responses when the team changes quickly. */
  optionsRequest: number;
  readonly priorities: readonly PriorityOption[];

  readonly teamPlaceholder: string;
  readonly canSave: boolean;
  /** Items for the custom selects; the leading `''` item is the "none" choice. */
  readonly teamItems: readonly SelectItem[];
  readonly priorityItems: readonly SelectItem[];
  readonly projectItems: readonly SelectItem[];
  readonly stateItems: readonly SelectItem[];
  readonly assigneeItems: readonly SelectItem[];

  init(): Promise<void>;
  loadTeams(): Promise<void>;
  loadOptions(): Promise<void>;
  onTeamChange(): Promise<void>;
  selectTeam(value: SelectValue): Promise<void>;
  selectField(field: SelectField, value: SelectValue): void;
  selectPriority(value: SelectValue): void;
  hasLabel(id: string): boolean;
  toggleLabel(id: string): void;
  labelDotStyle(label: Label): Record<string, string>;
  hasError(field: string): boolean;
  firstError(field: string): string;
  clearError(field: string): void;
  save(): Promise<void>;
  askRemove(): void;
  cancelRemove(): void;
  remove(): Promise<void>;
}

export function destinationForm({ host, destination, client }: DestinationFormDeps): DestinationFormComponent {
  const component: DestinationFormComponent & ThisType<DestinationFormComponent & AlpineMagics> = {
    draft: draftFromDestination(destination),
    teams: [],
    teamsState: 'idle',
    teamsError: null,
    options: emptyOptions(),
    optionsState: 'idle',
    optionsError: null,
    saving: false,
    removing: false,
    confirmingRemove: false,
    hasSavedDestination: destination !== null,
    formError: null,
    errors: {},
    optionsRequest: 0,
    priorities: PRIORITY_OPTIONS,

    get teamPlaceholder(): string {
      switch (this.teamsState) {
        case 'error':
          return 'Teams unavailable';
        case 'ready':
          return this.teams.length === 0 ? 'No teams found' : 'Select a team';
        default:
          return 'Loading teams…';
      }
    },

    get canSave(): boolean {
      return !this.saving && !this.removing && this.draft.teamId !== '' && this.optionsState !== 'loading';
    },

    get teamItems() {
      return [
        { value: '', label: this.teamPlaceholder },
        ...this.teams.map((team) => ({ value: team.id, label: `${team.name} (${team.key})`, visual: teamVisual(team) })),
      ];
    },

    get priorityItems() {
      return this.priorities.map((priority) => ({ value: priority.value, label: priority.label, visual: priorityVisual(priority.value) }));
    },

    get projectItems() {
      return [
        { value: '', label: 'No project', visual: NO_PROJECT_VISUAL },
        ...this.options.projects.map((project) => ({ value: project.id, label: project.name, visual: projectVisual(project) })),
      ];
    },

    get stateItems() {
      return [
        { value: '', label: 'Team default', visual: TEAM_DEFAULT_VISUAL },
        ...this.options.states.map((state) => ({ value: state.id, label: state.name, visual: stateVisual(state) })),
      ];
    },

    get assigneeItems() {
      return [
        { value: '', label: 'Unassigned', visual: UNASSIGNED_VISUAL },
        ...this.options.members.map((member) => ({ value: member.id, label: member.name, visual: memberVisual(member) })),
      ];
    },

    async init() {
      // Teams and the saved team's options are independent requests.
      await Promise.all([this.loadTeams(), this.draft.teamId === '' ? Promise.resolve() : this.loadOptions()]);
    },

    async loadTeams() {
      this.teamsState = 'loading';
      this.teamsError = null;

      try {
        this.teams = await client.getTeams();
        this.teamsState = 'ready';
      } catch (error) {
        this.teamsState = 'error';
        this.teamsError = errorMessage(error);
        reportReconnect(host, error);
      }
    },

    async loadOptions() {
      const teamId = this.draft.teamId;
      const request = ++this.optionsRequest;

      this.optionsState = 'loading';
      this.optionsError = null;
      this.options = emptyOptions();

      try {
        const options = await client.getTeamOptions(teamId);

        if (request !== this.optionsRequest) {
          return;
        }

        this.options = options;
        this.draft = pruneDraft(this.draft, options);
        this.optionsState = 'ready';
      } catch (error) {
        if (request !== this.optionsRequest) {
          return;
        }

        this.optionsState = 'error';
        this.optionsError = errorMessage(error);
        reportReconnect(host, error);
      }
    },

    async onTeamChange() {
      // Projects, states, members and labels belong to one team.
      this.draft = { ...this.draft, projectId: '', stateId: '', assigneeId: '', labelIds: [] };
      this.clearError('teamId');

      if (this.draft.teamId === '') {
        this.optionsRequest += 1; // invalidate any request still in flight
        this.options = emptyOptions();
        this.optionsState = 'idle';
        this.optionsError = null;
        return;
      }

      await this.loadOptions();
    },

    selectTeam(value) {
      this.draft.teamId = String(value);
      return this.onTeamChange();
    },

    selectField(field, value) {
      this.draft[field] = String(value);
      this.clearError(field);
    },

    selectPriority(value) {
      this.draft.priority = Number(value);
      this.clearError('priority');
    },

    hasLabel(id) {
      return this.draft.labelIds.includes(id);
    },

    toggleLabel(id) {
      this.draft.labelIds = this.hasLabel(id)
        ? this.draft.labelIds.filter((labelId) => labelId !== id)
        : [...this.draft.labelIds, id];
      this.clearError('labelIds');
    },

    labelDotStyle(label) {
      return label.color !== null && isValidHexColor(label.color) ? { '--linear-dot': label.color } : {};
    },

    hasError(field) {
      return this.firstError(field) !== '';
    },

    firstError(field) {
      return this.errors[field]?.[0] ?? '';
    },

    clearError(field) {
      delete this.errors[field];
    },

    async save() {
      if (!this.canSave) {
        return;
      }

      this.saving = true;
      this.formError = null;
      this.errors = {};

      try {
        const saved = await client.saveDestination(draftToPayload(this.draft));

        this.draft = draftFromDestination(saved);
        this.hasSavedDestination = true;
        emit(host, LINEAR_EVENTS.destinationChanged, { destination: saved });
        emit(host, LINEAR_EVENTS.flash, { kind: 'status', message: SAVED_MESSAGE });
      } catch (error) {
        if (isApiError(error) && error.isValidation && Object.keys(error.fieldErrors).length > 0) {
          this.errors = groupFieldErrors(error.fieldErrors);
          this.formError = FIELD_ERRORS_MESSAGE;
        } else {
          this.formError = errorMessage(error);
          reportReconnect(host, error);
        }
      } finally {
        this.saving = false;
      }
    },

    askRemove() {
      this.confirmingRemove = true;
      focusRef(this, host, 'confirmRemove');
    },

    cancelRemove() {
      this.confirmingRemove = false;
      focusRef(this, host, 'remove');
    },

    async remove() {
      if (this.removing) {
        return;
      }

      this.removing = true;
      this.formError = null;

      try {
        await client.deleteDestination();

        this.draft = emptyDraft();
        this.optionsRequest += 1;
        this.options = emptyOptions();
        this.optionsState = 'idle';
        this.optionsError = null;
        this.errors = {};
        this.hasSavedDestination = false;
        this.confirmingRemove = false;
        emit(host, LINEAR_EVENTS.destinationChanged, { destination: null });
        emit(host, LINEAR_EVENTS.flash, { kind: 'status', message: REMOVED_MESSAGE });
        focusRef(this, host, 'team');
      } catch (error) {
        this.formError = errorMessage(error);
        reportReconnect(host, error);
      } finally {
        this.removing = false;
      }
    },
  };

  return component;
}
