<?php

declare(strict_types=1);

namespace Dniccum\Linear\Testing;

use Dniccum\Linear\Transport\Response;
use Dniccum\Linear\Transport\Transport;
use LogicException;

/**
 * A transport that refuses to send anything: the fakes answer from memory, so
 * a request reaching it means a test is about to talk to the real Linear.
 */
final class NullTransport implements Transport
{
    public function postJson(string $url, array $headers, array $payload): Response
    {
        throw new LogicException("Unexpected request to {$url}: the Linear fake does not send requests.");
    }

    public function postForm(string $url, array $headers, array $fields): Response
    {
        throw new LogicException("Unexpected request to {$url}: the Linear fake does not send requests.");
    }
}
