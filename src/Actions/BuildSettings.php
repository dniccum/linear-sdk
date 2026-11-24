<?php

declare(strict_types=1);

namespace Dniccum\Linear\Actions;

use Dniccum\Linear\Data\Settings\BrandData;
use Dniccum\Linear\Data\Settings\ConnectionData;
use Dniccum\Linear\Data\Settings\DestinationData;
use Dniccum\Linear\Data\Settings\FailureData;
use Dniccum\Linear\Data\Settings\FlashData;
use Dniccum\Linear\Data\Settings\SettingsData;
use Dniccum\Linear\Data\Settings\UrlsData;
use Dniccum\Linear\Enums\LinearAuthMode;
use Dniccum\Linear\Enums\LinearSyncStatus;
use Dniccum\Linear\Linear;
use Dniccum\Linear\Models\LinearCommentDelivery;
use Dniccum\Linear\Models\LinearIssueLink;
use Dniccum\Linear\Services\LinearOAuth;
use Dniccum\Linear\Support\Json;
use Dniccum\Linear\Support\ModelHooks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ViewErrorBag;

/**
 * Everything the configuration page shows for an owner.
 */
class BuildSettings
{
    /**
     * How many failed issues and comments are listed.
     */
    public const int FAILURE_LIMIT = 10;

    public function __construct(
        private readonly LinearOAuth $oauth,
    ) {}

    public function execute(Model $owner): SettingsData
    {
        $connection = ModelHooks::connection($owner);
        $destination = ModelHooks::destination($owner);
        $authMode = app(Linear::class)->authMode();

        return new SettingsData(
            configured: $authMode === LinearAuthMode::ApiKey || $this->oauth->isConfigured(),
            authMode: $authMode,
            brand: BrandData::fromConfig(),
            csrf: Json::string(csrf_token()),
            urls: UrlsData::resolve(),
            back: app(Linear::class)->backFor($owner),
            connection: $connection === null ? null : ConnectionData::fromModel($connection),
            destination: $destination === null ? null : DestinationData::fromModel($destination),
            failures: $this->failures($owner),
            flash: new FlashData(
                status: $this->flashed(FlashData::STATUS_KEY),
                error: $this->flashedError(),
            ),
        );
    }

    /**
     * The latest issues and comments that could not be sent, newest first, so
     * the user can retry them.
     *
     * @return list<FailureData>
     */
    private function failures(Model $owner): array
    {
        $issues = LinearIssueLink::query()
            ->whereMorphedTo('owner', $owner)
            ->where('status', LinearSyncStatus::Failed)
            ->latest('updated_at')
            ->limit(self::FAILURE_LIMIT)
            ->get()
            ->map(fn (LinearIssueLink $link): FailureData => FailureData::fromIssueLink($link));

        $comments = LinearCommentDelivery::query()
            ->with('issueLink')
            ->where('status', LinearSyncStatus::Failed)
            ->whereHas('issueLink', fn (Builder $query): Builder => $query->whereMorphedTo('owner', $owner))
            ->latest('updated_at')
            ->limit(self::FAILURE_LIMIT)
            ->get()
            ->map(fn (LinearCommentDelivery $delivery): FailureData => FailureData::fromCommentDelivery($delivery));

        return array_values($issues->concat($comments)
            ->sortByDesc(fn (FailureData $failure): string => $failure->occurredAt ?? '')
            ->take(self::FAILURE_LIMIT)
            ->all());
    }

    private function flashed(string $key): ?string
    {
        $request = request();

        return $request->hasSession() ? Json::nullableString($request->session()->get($key)) : null;
    }

    /**
     * The flashed error, or else the first validation error of the request
     * that sent the user back here (a plain form post with a missing field).
     */
    private function flashedError(): ?string
    {
        $request = request();
        $errors = $request->hasSession() ? $request->session()->get('errors') : null;

        return $this->flashed(FlashData::ERROR_KEY) ?? ($errors instanceof ViewErrorBag ? Json::nullableString($errors->first()) : null);
    }
}
