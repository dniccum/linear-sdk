<?php

declare(strict_types=1);

namespace Dniccum\Linear\Actions;

use Dniccum\Linear\Enums\LinearSyncStatus;
use Dniccum\Linear\Models\LinearCommentDelivery;
use Dniccum\Linear\Models\LinearIssueLink;
use Dniccum\Linear\Services\LinearOAuth;
use Dniccum\Linear\Support\ModelHooks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Disconnect Linear: revoke the OAuth token at Linear, delete the stored
 * credentials, and fail queued work that would otherwise wait on a connection
 * that is gone (it can be retried after reconnecting).
 */
class DisconnectLinear
{
    public function __construct(
        private readonly LinearOAuth $oauth,
    ) {}

    /**
     * @return bool Whether there was a connection to disconnect.
     */
    public function execute(Model $owner): bool
    {
        $connection = ModelHooks::connection($owner);

        if ($connection === null) {
            return false;
        }

        if (! $connection->usesApiKey()) {
            $this->oauth->revoke($connection->access_token);
        }

        $connection->delete();
        $owner->unsetRelation('linearConnection');

        $message = 'Linear was disconnected before this could be sent. Reconnect Linear and retry.';

        LinearIssueLink::query()
            ->whereMorphedTo('owner', $owner)
            ->where('status', LinearSyncStatus::Pending)
            ->update(['status' => LinearSyncStatus::Failed, 'last_error' => $message]);

        LinearCommentDelivery::query()
            ->where('status', LinearSyncStatus::Pending)
            ->whereHas('issueLink', fn (Builder $query): Builder => $query->whereMorphedTo('owner', $owner))
            ->update(['status' => LinearSyncStatus::Failed, 'last_error' => $message]);

        return true;
    }
}
