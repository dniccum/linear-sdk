<?php

declare(strict_types=1);

namespace Dniccum\Linear\Laravel;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * A request sent through {@see IlluminateHttpClient} could not be completed.
 */
final class HttpClientException extends RuntimeException implements ClientExceptionInterface {}
