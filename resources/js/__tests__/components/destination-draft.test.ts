import { describe, expect, it } from 'vitest';
import {
  draftFromDestination,
  draftToPayload,
  emptyDraft,
  emptyOptions,
  groupFieldErrors,
  normalizeFieldKey,
  PRIORITY_OPTIONS,
  pruneDraft,
  toPriority,
} from '../../components/destination-draft';
import { makeDestination, makeOptions } from '../fixtures';

describe('PRIORITY_OPTIONS', () => {
  it('maps Linear priorities to their labels', () => {
    expect(PRIORITY_OPTIONS.map(({ value, label }) => [value, label])).toEqual([
      [0, 'No priority'],
      [1, 'Urgent'],
      [2, 'High'],
      [3, 'Medium'],
      [4, 'Low'],
    ]);
  });
});

describe('drafts', () => {
  it('emptyDraft starts automatic, with no team and no priority', () => {
    expect(emptyDraft()).toEqual({
      sendMode: 'automatic',
      teamId: '',
      projectId: '',
      stateId: '',
      assigneeId: '',
      labelIds: [],
      priority: 0,
    });
  });

  it('emptyOptions has four empty lists', () => {
    expect(emptyOptions()).toEqual({ states: [], projects: [], members: [], labels: [] });
  });

  it('draftFromDestination(null) is the empty draft', () => {
    expect(draftFromDestination(null)).toEqual(emptyDraft());
  });

  it('draftFromDestination copies values and turns nulls into empty strings', () => {
    const destination = makeDestination({ projectId: null, stateId: null, assigneeId: null, labelIds: ['a', 'b'] });

    const draft = draftFromDestination(destination);

    expect(draft).toEqual({
      sendMode: 'automatic',
      teamId: 'team-1',
      projectId: '',
      stateId: '',
      assigneeId: '',
      labelIds: ['a', 'b'],
      priority: 2,
    });
    expect(draft.labelIds).not.toBe(destination.labelIds);
  });

  it('draftFromDestination keeps populated optional ids', () => {
    expect(draftFromDestination(makeDestination())).toMatchObject({
      projectId: 'project-1',
      stateId: 'state-1',
      assigneeId: 'member-1',
    });
  });

  it('draftToPayload turns empty strings back into null and drops teamName', () => {
    const payload = draftToPayload({ ...emptyDraft(), teamId: 'team-1', labelIds: ['a'], priority: 4 });

    expect(payload).toEqual({
      sendMode: 'automatic',
      teamId: 'team-1',
      projectId: null,
      stateId: null,
      labelIds: ['a'],
      priority: 4,
      assigneeId: null,
    });
    expect(payload).not.toHaveProperty('teamName');
  });

  it('draftToPayload keeps selected ids', () => {
    const payload = draftToPayload(draftFromDestination(makeDestination()));

    expect(payload).toMatchObject({ projectId: 'project-1', stateId: 'state-1', assigneeId: 'member-1' });
  });
});

describe('toPriority', () => {
  it.each([0, 1, 2, 3, 4])('keeps %i', (value) => {
    expect(toPriority(value)).toBe(value);
  });

  it.each([-1, 5, 2.5, Number.NaN])('turns %s into 0', (value) => {
    expect(toPriority(value)).toBe(0);
  });
});

describe('pruneDraft', () => {
  it('keeps selections that still exist', () => {
    const draft = draftFromDestination(makeDestination());

    expect(pruneDraft(draft, makeOptions())).toEqual(draft);
  });

  it('drops selections missing from the options', () => {
    const draft = {
      ...draftFromDestination(makeDestination()),
      projectId: 'gone',
      stateId: 'gone',
      assigneeId: 'gone',
      labelIds: ['label-1', 'gone'],
    };

    expect(pruneDraft(draft, makeOptions())).toMatchObject({
      projectId: '',
      stateId: '',
      assigneeId: '',
      labelIds: ['label-1'],
    });
  });
});

describe('field keys', () => {
  it.each([
    ['teamId', 'teamId'],
    ['team_id', 'teamId'],
    ['labelIds.0', 'labelIds'],
    ['label_ids.12', 'labelIds'],
    ['sendMode', 'sendMode'],
  ])('normalizeFieldKey(%s) -> %s', (input, expected) => {
    expect(normalizeFieldKey(input)).toBe(expected);
  });

  it('groupFieldErrors merges indexed keys and normalises names', () => {
    expect(
      groupFieldErrors({
        team_id: ['Required'],
        'labelIds.0': ['Bad one'],
        'labelIds.1': ['Bad two'],
      }),
    ).toEqual({ teamId: ['Required'], labelIds: ['Bad one', 'Bad two'] });
  });

  it('groupFieldErrors of nothing is empty', () => {
    expect(groupFieldErrors({})).toEqual({});
  });
});
