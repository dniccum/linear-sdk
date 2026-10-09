<?php

declare(strict_types=1);

namespace Dniccum\Linear\Testing;

use Dniccum\Linear\Data\Tokens;
use Dniccum\Linear\LinearConfig;
use Dniccum\Linear\Services\LinearOAuth;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * An in-memory {@see LinearOAuth}: the token endpoint answers with canned
 * tokens and nothing leaves the process. Installed by `Linear::fake()`.
 */
final class FakeLinearOAuth extends LinearOAuth
{
    private Tokens $tokens;

    /**
     * @var list<string>
     */
    private array $revoked = [];

    /**
     * @var list<string>
     */
    private array $codes = [];

    /**
     * The HTTP client defaults to one that refuses every request: the fake
     * answers from memory, so a request means a test is about to talk to the
     * real Linear.
     */
    public function __construct(?LinearConfig $config = null, ?HttpFactory $http = null)
    {
        parent::__construct($http ?? (new HttpFactory)->preventStrayRequests(), $config ?? new LinearConfig);

        $this->tokens = new Tokens('fake-access-token', 'fake-refresh-token', 86400, $this->scopes());
    }

    /**
     * The tokens every code exchange and refresh answers with.
     */
    public function withTokens(Tokens $tokens): self
    {
        $this->tokens = $tokens;

        return $this;
    }

    public function exchangeCode(string $code, string $codeVerifier): Tokens
    {
        $this->codes[] = $code;

        return $this->tokens;
    }

    public function refresh(string $refreshToken): Tokens
    {
        return $this->tokens;
    }

    public function revoke(string $token): bool
    {
        $this->revoked[] = $token;

        return true;
    }

    /**
     * @return list<string>
     */
    public function exchangedCodes(): array
    {
        return $this->codes;
    }

    /**
     * @return list<string>
     */
    public function revokedTokens(): array
    {
        return $this->revoked;
    }
}
