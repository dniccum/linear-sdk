import { PRIORITIES } from '../schema';
import type { Destination, DestinationPayload, Priority, SendMode, TeamOptions } from '../types';

/**
 * The editable form state. Optional selections are `''` (not `null`) because
 * that is what `<select>` elements bind to; `draftToPayload` converts back.
 */
export interface DestinationDraft {
  sendMode: SendMode;
  teamId: string;
  projectId: string;
  stateId: string;
  assigneeId: string;
  labelIds: string[];
  /** Bound with `x-model.number`; narrowed to `Priority` by `toPriority`. */
  priority: number;
}

export interface PriorityOption {
  value: Priority;
  label: string;
}

export const PRIORITY_OPTIONS: readonly PriorityOption[] = [
  { value: 0, label: 'No priority' },
  { value: 1, label: 'Urgent' },
  { value: 2, label: 'High' },
  { value: 3, label: 'Medium' },
  { value: 4, label: 'Low' },
];

export function emptyDraft(): DestinationDraft {
  return {
    sendMode: 'automatic',
    teamId: '',
    projectId: '',
    stateId: '',
    assigneeId: '',
    labelIds: [],
    priority: 0,
  };
}

export function emptyOptions(): TeamOptions {
  return { states: [], projects: [], members: [], labels: [] };
}

export function draftFromDestination(destination: Destination | null): DestinationDraft {
  if (destination === null) {
    return emptyDraft();
  }

  return {
    sendMode: destination.sendMode,
    teamId: destination.teamId,
    projectId: destination.projectId ?? '',
    stateId: destination.stateId ?? '',
    assigneeId: destination.assigneeId ?? '',
    labelIds: [...destination.labelIds],
    priority: destination.priority,
  };
}

/** Anything that is not a whole number from 0 to 4 becomes "no priority". */
export function toPriority(value: number): Priority {
  return PRIORITIES.find((priority) => priority === value) ?? 0;
}

export function draftToPayload(draft: DestinationDraft): DestinationPayload {
  return {
    sendMode: draft.sendMode,
    teamId: draft.teamId,
    projectId: draft.projectId === '' ? null : draft.projectId,
    stateId: draft.stateId === '' ? null : draft.stateId,
    labelIds: [...draft.labelIds],
    priority: toPriority(draft.priority),
    assigneeId: draft.assigneeId === '' ? null : draft.assigneeId,
  };
}

/**
 * Drops selections that no longer exist in a freshly loaded set of options
 * (for example a project that was archived in Linear) so they are never sent
 * back to the server.
 */
export function pruneDraft(draft: DestinationDraft, options: TeamOptions): DestinationDraft {
  const has = (items: readonly { id: string }[], id: string): boolean => items.some((item) => item.id === id);

  return {
    ...draft,
    projectId: has(options.projects, draft.projectId) ? draft.projectId : '',
    stateId: has(options.states, draft.stateId) ? draft.stateId : '',
    assigneeId: has(options.members, draft.assigneeId) ? draft.assigneeId : '',
    labelIds: draft.labelIds.filter((id) => has(options.labels, id)),
  };
}

/**
 * Normalises a server validation key to the camelCase field name used by the
 * form: `label_ids` and `labelIds.0` both become `labelIds`.
 */
export function normalizeFieldKey(key: string): string {
  return key.replace(/\..*$/, '').replace(/_([a-z])/g, (_match, letter: string) => letter.toUpperCase());
}

/** Groups validation messages by normalised field key (merging `labelIds.0`, `labelIds.1`, ...). */
export function groupFieldErrors(errors: Readonly<Record<string, string[]>>): Record<string, string[]> {
  const grouped: Record<string, string[]> = {};

  for (const [key, messages] of Object.entries(errors)) {
    const field = normalizeFieldKey(key);
    grouped[field] = [...(grouped[field] ?? []), ...messages];
  }

  return grouped;
}
