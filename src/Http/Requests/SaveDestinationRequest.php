<?php

declare(strict_types=1);

namespace Dniccum\Linear\Http\Requests;

use Dniccum\Linear\Data\Destination;
use Dniccum\Linear\Enums\LinearSendMode;
use Dniccum\Linear\Support\Json;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The body of `PUT destination`: a destination in the camelCase shape of the
 * HTTP contract, without `teamName` (the server resolves it).
 *
 * These are shape rules. Whether each ID actually exists in the connected
 * workspace is checked afterwards against Linear itself.
 */
class SaveDestinationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sendMode' => ['required', Rule::enum(LinearSendMode::class)],
            'teamId' => ['required', 'string', 'max:255'],
            'projectId' => ['nullable', 'string', 'max:255'],
            'stateId' => ['nullable', 'string', 'max:255'],
            'labelIds' => ['nullable', 'array', 'max:50'],
            'labelIds.*' => ['string', 'max:255'],
            // Linear priorities: 0 none, 1 urgent, 2 high, 3 medium, 4 low.
            'priority' => ['nullable', 'integer', 'between:0,4'],
            'assigneeId' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function sendMode(): LinearSendMode
    {
        return LinearSendMode::from(Json::string($this->validated('sendMode')));
    }

    /**
     * The validated destination. Call only after validation has passed.
     */
    public function destination(): Destination
    {
        $data = $this->validated();

        return Destination::fromArray([
            'team_id' => $data['teamId'] ?? null,
            'project_id' => $data['projectId'] ?? null,
            'state_id' => $data['stateId'] ?? null,
            'label_ids' => $data['labelIds'] ?? [],
            'priority' => $data['priority'] ?? null,
            'assignee_id' => $data['assigneeId'] ?? null,
        ]);
    }
}
