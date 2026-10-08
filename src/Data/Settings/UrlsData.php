<?php

declare(strict_types=1);

namespace Dniccum\Linear\Data\Settings;

use Dniccum\Linear\Data\Data;
use Dniccum\Linear\Linear;
use Illuminate\Support\Facades\Route;

/**
 * The endpoints the configuration page talks to. A URL is an empty string when
 * the route group it belongs to is disabled (`login` is empty when no login
 * redirect is configured). `teamOptions` contains the
 * literal `{team}` placeholder and `retry` the literal `{link}` placeholder.
 */
final readonly class UrlsData extends Data
{
    public function __construct(
        public string $connect,
        public string $apiKey,
        public string $disconnect,
        public string $teams,
        public string $teamOptions,
        public string $destination,
        public string $retry,
        public string $login = '',
    ) {}

    public static function resolve(): self
    {
        return new self(
            connect: self::url('linear.connect'),
            apiKey: self::url('linear.api-key.store'),
            disconnect: self::url('linear.disconnect'),
            teams: self::url('linear.api.teams'),
            teamOptions: self::url('linear.api.team-options', ['team' => '__team__'], ['__team__' => '{team}']),
            destination: self::url('linear.api.destination.update'),
            retry: self::url('linear.api.issues.retry', ['link' => '__link__'], ['__link__' => '{link}']),
            login: app(Linear::class)->loginUrl() ?? '',
        );
    }

    /**
     * @return array{connect: string, apiKey: string, disconnect: string, teams: string, teamOptions: string, destination: string, retry: string, login: string}
     */
    public function toArray(): array
    {
        return [
            'connect' => $this->connect,
            'apiKey' => $this->apiKey,
            'disconnect' => $this->disconnect,
            'teams' => $this->teams,
            'teamOptions' => $this->teamOptions,
            'destination' => $this->destination,
            'retry' => $this->retry,
            'login' => $this->login,
        ];
    }

    /**
     * @param  array<string, string>  $parameters
     * @param  array<string, string>  $placeholders  Swapped in after URL encoding, so braces survive.
     */
    private static function url(string $name, array $parameters = [], array $placeholders = []): string
    {
        return Route::has($name) ? strtr(route($name, $parameters), $placeholders) : '';
    }
}
