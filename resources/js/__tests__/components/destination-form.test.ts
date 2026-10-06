import { describe, expect, it, vi } from 'vitest';
import { ApiError } from '../../api/errors';
import {
  destinationForm,
  FIELD_ERRORS_MESSAGE,
  REMOVED_MESSAGE,
  SAVED_MESSAGE,
  type DestinationFormDeps,
} from '../../components/destination-form';
import { LINEAR_EVENTS } from '../../components/events';
import type { Destination, TeamOptions } from '../../types';
import { captureEvents, deferred, makeDestination, makeHost, makeOptions, withMagics } from '../fixtures';

const TEAMS = [
  { id: 'team-1', name: 'Support', key: 'SUP' },
  { id: 'team-2', name: 'Engineering', key: 'ENG' },
];

type Client = DestinationFormDeps['client'];

function makeClient(overrides: Partial<Client> = {}): { [K in keyof Client]: ReturnType<typeof vi.fn<Client[K]>> } {
  return {
    getTeams: vi.fn<Client['getTeams']>().mockResolvedValue(TEAMS),
    getTeamOptions: vi.fn<Client['getTeamOptions']>().mockResolvedValue(makeOptions()),
    saveDestination: vi.fn<Client['saveDestination']>().mockImplementation(async (payload) => ({
      ...payload,
      teamName: 'Support',
    })),
    deleteDestination: vi.fn<Client['deleteDestination']>().mockResolvedValue(undefined),
    ...overrides,
  } as { [K in keyof Client]: ReturnType<typeof vi.fn<Client[K]>> };
}

function setup(destination: Destination | null = makeDestination(), clientOverrides: Partial<Client> = {}) {
  const host = makeHost(['team', 'remove', 'confirmRemove']);
  const events = captureEvents(host, Object.values(LINEAR_EVENTS));
  const client = makeClient(clientOverrides);
  const form = withMagics(destinationForm({ host, destination, client }));
  return { host, events, client, form };
}

function focusedRef(): string | null {
  return document.activeElement?.getAttribute('data-linear-ref') ?? null;
}

describe('initial state', () => {
  it('builds the draft from the saved destination', () => {
    const { form } = setup();

    expect(form.draft).toMatchObject({ teamId: 'team-1', projectId: 'project-1', priority: 2 });
    expect(form.hasSavedDestination).toBe(true);
    expect(form.teamsState).toBe('idle');
    expect(form.optionsState).toBe('idle');
    expect(form.options).toEqual({ states: [], projects: [], members: [], labels: [] });
    expect(form.priorities.map((priority) => priority.label)).toEqual([
      'No priority',
      'Urgent',
      'High',
      'Medium',
      'Low',
    ]);
  });

  it('starts empty when nothing is saved', () => {
    const { form } = setup(null);

    expect(form.draft.teamId).toBe('');
    expect(form.draft.sendMode).toBe('automatic');
    expect(form.hasSavedDestination).toBe(false);
  });
});

describe('derived state', () => {
  it('teamPlaceholder follows the teams lifecycle', () => {
    const { form } = setup();

    expect(form.teamPlaceholder).toBe('Loading teams…');
    form.teamsState = 'loading';
    expect(form.teamPlaceholder).toBe('Loading teams…');
    form.teamsState = 'error';
    expect(form.teamPlaceholder).toBe('Teams unavailable');
    form.teamsState = 'ready';
    expect(form.teamPlaceholder).toBe('No teams found');
    form.teams = TEAMS;
    expect(form.teamPlaceholder).toBe('Select a team');
  });

  it('canSave needs a team and no work in flight', () => {
    const { form } = setup();
    expect(form.canSave).toBe(true);

    form.saving = true;
    expect(form.canSave).toBe(false);
    form.saving = false;

    form.removing = true;
    expect(form.canSave).toBe(false);
    form.removing = false;

    form.optionsState = 'loading';
    expect(form.canSave).toBe(false);
    form.optionsState = 'error';
    expect(form.canSave).toBe(true);

    form.draft.teamId = '';
    expect(form.canSave).toBe(false);
  });
});

describe('init', () => {
  it('loads teams and the saved team options in parallel', async () => {
    const teams = deferred<typeof TEAMS>();
    const options = deferred<TeamOptions>();
    const { form, client } = setup(makeDestination(), {
      getTeams: vi.fn(() => teams.promise),
      getTeamOptions: vi.fn(() => options.promise),
    });

    const initialising = form.init();

    expect(client.getTeams).toHaveBeenCalledTimes(1);
    expect(client.getTeamOptions).toHaveBeenCalledWith('team-1');
    expect(form.teamsState).toBe('loading');
    expect(form.optionsState).toBe('loading');

    teams.resolve(TEAMS);
    options.resolve(makeOptions());
    await initialising;

    expect(form.teamsState).toBe('ready');
    expect(form.optionsState).toBe('ready');
  });

  it('only loads teams when no team is saved', async () => {
    const { form, client } = setup(null);

    await form.init();

    expect(client.getTeams).toHaveBeenCalledTimes(1);
    expect(client.getTeamOptions).not.toHaveBeenCalled();
    expect(form.optionsState).toBe('idle');
  });
});

describe('loadTeams', () => {
  it('stores the teams and becomes ready', async () => {
    const { form } = setup(null);

    await form.loadTeams();

    expect(form.teams).toEqual(TEAMS);
    expect(form.teamsState).toBe('ready');
    expect(form.teamsError).toBeNull();
  });

  it('shows the error and allows another attempt', async () => {
    const getTeams = vi
      .fn<Client['getTeams']>()
      .mockRejectedValueOnce(new ApiError('Linear is unavailable', { status: 503 }))
      .mockResolvedValueOnce(TEAMS);
    const { form } = setup(null, { getTeams });

    await form.loadTeams();
    expect(form.teamsState).toBe('error');
    expect(form.teamsError).toBe('Linear is unavailable');

    await form.loadTeams();
    expect(form.teamsState).toBe('ready');
    expect(form.teamsError).toBeNull();
  });

  it('asks the root to show the reconnect state on a 409', async () => {
    const getTeams = vi
      .fn<Client['getTeams']>()
      .mockRejectedValue(new ApiError('Reconnect Linear.', { status: 409, reconnect: true }));
    const { form, events } = setup(null, { getTeams });

    await form.loadTeams();

    expect(form.teamsState).toBe('error');
    expect(events).toEqual([{ name: 'linear-reconnect-required', detail: { message: 'Reconnect Linear.' } }]);
  });
});

describe('loadOptions', () => {
  it('shows a loading state, then the options', async () => {
    const pending = deferred<TeamOptions>();
    const { form, client } = setup(makeDestination(), { getTeamOptions: vi.fn(() => pending.promise) });

    const loading = form.loadOptions();

    expect(form.optionsState).toBe('loading');
    expect(form.optionsError).toBeNull();
    expect(client.getTeamOptions).toHaveBeenCalledWith('team-1');

    pending.resolve(makeOptions());
    await loading;

    expect(form.optionsState).toBe('ready');
    expect(form.options).toEqual(makeOptions());
  });

  it('prunes saved selections that no longer exist', async () => {
    const { form } = setup(makeDestination({ projectId: 'archived', labelIds: ['label-1', 'removed'] }));

    await form.loadOptions();

    expect(form.draft.projectId).toBe('');
    expect(form.draft.labelIds).toEqual(['label-1']);
    expect(form.draft.stateId).toBe('state-1');
  });

  it('records an error and allows retrying', async () => {
    const getTeamOptions = vi
      .fn<Client['getTeamOptions']>()
      .mockRejectedValueOnce(new ApiError('Options unavailable', { status: 503 }))
      .mockResolvedValueOnce(makeOptions());
    const { form, events } = setup(makeDestination(), { getTeamOptions });

    await form.loadOptions();
    expect(form.optionsState).toBe('error');
    expect(form.optionsError).toBe('Options unavailable');
    expect(form.options).toEqual({ states: [], projects: [], members: [], labels: [] });
    expect(events).toEqual([]);

    await form.loadOptions();
    expect(form.optionsState).toBe('ready');
    expect(form.optionsError).toBeNull();
  });

  it('asks to reconnect on a 409', async () => {
    const getTeamOptions = vi
      .fn<Client['getTeamOptions']>()
      .mockRejectedValue(new ApiError('Reconnect Linear.', { status: 409, reconnect: true }));
    const { form, events } = setup(makeDestination(), { getTeamOptions });

    await form.loadOptions();

    expect(form.optionsState).toBe('error');
    expect(events.map((event) => event.name)).toEqual(['linear-reconnect-required']);
  });

  it('ignores a stale successful response after the team changed again', async () => {
    const first = deferred<TeamOptions>();
    const second = deferred<TeamOptions>();
    const getTeamOptions = vi
      .fn<Client['getTeamOptions']>()
      .mockReturnValueOnce(first.promise)
      .mockReturnValueOnce(second.promise);
    const { form } = setup(makeDestination(), { getTeamOptions });

    const firstLoad = form.loadOptions();
    form.draft.teamId = 'team-2';
    const secondLoad = form.loadOptions();

    second.resolve(makeOptions({ projects: [{ id: 'p-eng', name: 'Platform' }] }));
    await secondLoad;
    first.resolve(makeOptions({ projects: [{ id: 'p-sup', name: 'Website' }] }));
    await firstLoad;

    expect(form.options.projects).toEqual([{ id: 'p-eng', name: 'Platform' }]);
    expect(form.optionsState).toBe('ready');
  });

  it('ignores a stale failed response after the team changed again', async () => {
    const first = deferred<TeamOptions>();
    const second = deferred<TeamOptions>();
    const getTeamOptions = vi
      .fn<Client['getTeamOptions']>()
      .mockReturnValueOnce(first.promise)
      .mockReturnValueOnce(second.promise);
    const { form, events } = setup(makeDestination(), { getTeamOptions });

    const firstLoad = form.loadOptions();
    form.draft.teamId = 'team-2';
    const secondLoad = form.loadOptions();

    second.resolve(makeOptions());
    await secondLoad;
    first.reject(new ApiError('Reconnect Linear.', { status: 409, reconnect: true }));
    await firstLoad;

    expect(form.optionsState).toBe('ready');
    expect(form.optionsError).toBeNull();
    expect(events).toEqual([]);
  });
});

describe('onTeamChange', () => {
  it('resets team-specific fields and loads the new team', async () => {
    const { form, client } = setup();
    form.errors = { teamId: ['Required'], priority: ['Bad'] };
    form.draft.teamId = 'team-2';

    await form.onTeamChange();

    expect(client.getTeamOptions).toHaveBeenCalledWith('team-2');
    expect(form.draft).toMatchObject({
      teamId: 'team-2',
      projectId: '',
      stateId: '',
      assigneeId: '',
      labelIds: [],
      priority: 2,
    });
    expect(form.errors).toEqual({ priority: ['Bad'] });
    expect(form.optionsState).toBe('ready');
  });

  it('clears the options when the team is deselected and cancels in-flight loads', async () => {
    const pending = deferred<TeamOptions>();
    const { form } = setup(makeDestination(), { getTeamOptions: vi.fn(() => pending.promise) });
    const loading = form.loadOptions();

    form.draft.teamId = '';
    await form.onTeamChange();
    pending.resolve(makeOptions());
    await loading;

    expect(form.optionsState).toBe('idle');
    expect(form.optionsError).toBeNull();
    expect(form.options).toEqual({ states: [], projects: [], members: [], labels: [] });
  });
});

describe('labels', () => {
  it('toggles label ids and clears label errors', () => {
    const { form } = setup(makeDestination({ labelIds: [] }));
    form.errors = { labelIds: ['Bad'] };

    expect(form.hasLabel('label-1')).toBe(false);

    form.toggleLabel('label-1');
    expect(form.hasLabel('label-1')).toBe(true);
    expect(form.draft.labelIds).toEqual(['label-1']);
    expect(form.errors).toEqual({});

    form.toggleLabel('label-2');
    expect(form.draft.labelIds).toEqual(['label-1', 'label-2']);

    form.toggleLabel('label-1');
    expect(form.draft.labelIds).toEqual(['label-2']);
  });

  it('only exposes valid hex colours as the dot style', () => {
    const { form } = setup();

    expect(form.labelDotStyle({ id: '1', name: 'Bug', color: '#eb5757' })).toEqual({ '--linear-dot': '#eb5757' });
    expect(form.labelDotStyle({ id: '1', name: 'Bug', color: 'red; background: url(x)' })).toEqual({});
    expect(form.labelDotStyle({ id: '1', name: 'Bug', color: null })).toEqual({});
  });
});

describe('field errors', () => {
  it('reads, tests and clears errors by field', () => {
    const { form } = setup();
    form.errors = { teamId: ['Required', 'Other'], priority: ['Bad'] };

    expect(form.hasError('teamId')).toBe(true);
    expect(form.firstError('teamId')).toBe('Required');
    expect(form.hasError('stateId')).toBe(false);
    expect(form.firstError('stateId')).toBe('');

    form.clearError('teamId');
    expect(form.errors).toEqual({ priority: ['Bad'] });
    form.clearError('teamId');
    expect(form.errors).toEqual({ priority: ['Bad'] });
  });
});

describe('save', () => {
  it('sends the draft as a payload, stores the result and notifies the root', async () => {
    const { form, client, events } = setup(makeDestination({ projectId: null }));
    form.draft.priority = 3;
    form.draft.sendMode = 'manual';
    form.formError = 'old';
    form.errors = { teamId: ['old'] };
    form.hasSavedDestination = false;

    await form.save();

    expect(client.saveDestination).toHaveBeenCalledWith({
      sendMode: 'manual',
      teamId: 'team-1',
      projectId: null,
      stateId: 'state-1',
      labelIds: ['label-1'],
      priority: 3,
      assigneeId: 'member-1',
    });
    expect(form.saving).toBe(false);
    expect(form.formError).toBeNull();
    expect(form.errors).toEqual({});
    expect(form.hasSavedDestination).toBe(true);
    expect(events.map((event) => event.name)).toEqual(['linear-destination-changed', 'linear-flash']);
    expect(events[0]?.detail).toMatchObject({ destination: { teamId: 'team-1', teamName: 'Support', priority: 3 } });
    expect(events[1]?.detail).toEqual({ kind: 'status', message: SAVED_MESSAGE });
  });

  it('is disabled while saving or without a team', async () => {
    const { form, client } = setup(null);

    await form.save();
    expect(client.saveDestination).not.toHaveBeenCalled();

    form.draft.teamId = 'team-1';
    form.saving = true;
    await form.save();
    expect(client.saveDestination).not.toHaveBeenCalled();
  });

  it('flags the saving state while the request is in flight', async () => {
    const pending = deferred<Destination>();
    const { form } = setup(makeDestination(), { saveDestination: vi.fn(() => pending.promise) });

    const saving = form.save();
    expect(form.saving).toBe(true);
    expect(form.canSave).toBe(false);

    pending.resolve(makeDestination());
    await saving;
    expect(form.saving).toBe(false);
  });

  it('maps 422 field errors onto the form', async () => {
    const saveDestination = vi.fn<Client['saveDestination']>().mockRejectedValue(
      new ApiError('The given data was invalid.', {
        status: 422,
        fieldErrors: { team_id: ['Pick a team'], 'labelIds.0': ['Bad label'], 'labelIds.1': ['Worse label'] },
      }),
    );
    const { form, events } = setup(makeDestination(), { saveDestination });

    await form.save();

    expect(form.formError).toBe(FIELD_ERRORS_MESSAGE);
    expect(form.errors).toEqual({ teamId: ['Pick a team'], labelIds: ['Bad label', 'Worse label'] });
    expect(form.saving).toBe(false);
    expect(events).toEqual([]);
  });

  it('shows the message when a 422 carries no field errors', async () => {
    const saveDestination = vi
      .fn<Client['saveDestination']>()
      .mockRejectedValue(new ApiError('Something is off.', { status: 422 }));
    const { form } = setup(makeDestination(), { saveDestination });

    await form.save();

    expect(form.formError).toBe('Something is off.');
    expect(form.errors).toEqual({});
  });

  it('shows other failures (outage, network, unknown) as a form error', async () => {
    const saveDestination = vi
      .fn<Client['saveDestination']>()
      .mockRejectedValueOnce(new ApiError('Linear is down.', { status: 503 }))
      .mockRejectedValueOnce('weird');
    const { form, events } = setup(makeDestination(), { saveDestination });

    await form.save();
    expect(form.formError).toBe('Linear is down.');

    await form.save();
    expect(form.formError).toBe('Something went wrong. Please try again.');
    expect(events).toEqual([]);
  });

  it('asks to reconnect on a 409', async () => {
    const saveDestination = vi
      .fn<Client['saveDestination']>()
      .mockRejectedValue(new ApiError('Reconnect Linear.', { status: 409, reconnect: true }));
    const { form, events } = setup(makeDestination(), { saveDestination });

    await form.save();

    expect(form.formError).toBe('Reconnect Linear.');
    expect(events).toEqual([{ name: 'linear-reconnect-required', detail: { message: 'Reconnect Linear.' } }]);
  });
});

describe('remove', () => {
  it('confirms, then focuses the right control', () => {
    const { form } = setup();

    form.askRemove();
    expect(form.confirmingRemove).toBe(true);
    expect(focusedRef()).toBe('confirmRemove');

    form.cancelRemove();
    expect(form.confirmingRemove).toBe(false);
    expect(focusedRef()).toBe('remove');
  });

  it('deletes the destination, resets the form and notifies the root', async () => {
    const { form, client, events } = setup();
    await form.loadOptions();
    form.confirmingRemove = true;
    form.errors = { teamId: ['x'] };

    await form.remove();

    expect(client.deleteDestination).toHaveBeenCalledTimes(1);
    expect(form.draft).toEqual({
      sendMode: 'automatic',
      teamId: '',
      projectId: '',
      stateId: '',
      assigneeId: '',
      labelIds: [],
      priority: 0,
    });
    expect(form.options).toEqual({ states: [], projects: [], members: [], labels: [] });
    expect(form.optionsState).toBe('idle');
    expect(form.errors).toEqual({});
    expect(form.hasSavedDestination).toBe(false);
    expect(form.confirmingRemove).toBe(false);
    expect(form.removing).toBe(false);
    expect(events.map((event) => event.name)).toEqual(['linear-destination-changed', 'linear-flash']);
    expect(events[0]?.detail).toEqual({ destination: null });
    expect(events[1]?.detail).toEqual({ kind: 'status', message: REMOVED_MESSAGE });
    expect(focusedRef()).toBe('team');
  });

  it('ignores a second remove while one is running', async () => {
    const pending = deferred<void>();
    const { form, client } = setup(makeDestination(), { deleteDestination: vi.fn(() => pending.promise) });

    const removing = form.remove();
    expect(form.removing).toBe(true);
    await form.remove();
    expect(client.deleteDestination).toHaveBeenCalledTimes(1);

    pending.resolve();
    await removing;
  });

  it('keeps the destination and shows the error when deleting fails', async () => {
    const deleteDestination = vi
      .fn<Client['deleteDestination']>()
      .mockRejectedValue(new ApiError('Linear is down.', { status: 503 }));
    const { form, events } = setup(makeDestination(), { deleteDestination });

    await form.remove();

    expect(form.formError).toBe('Linear is down.');
    expect(form.hasSavedDestination).toBe(true);
    expect(form.draft.teamId).toBe('team-1');
    expect(form.removing).toBe(false);
    expect(events).toEqual([]);
  });

  it('asks to reconnect on a 409', async () => {
    const deleteDestination = vi
      .fn<Client['deleteDestination']>()
      .mockRejectedValue(new ApiError('Reconnect Linear.', { status: 409, reconnect: true }));
    const { form, events } = setup(makeDestination(), { deleteDestination });

    await form.remove();

    expect(events.map((event) => event.name)).toEqual(['linear-reconnect-required']);
  });
});
