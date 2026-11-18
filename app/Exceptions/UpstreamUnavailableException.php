<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The Nationalize.io lookup failed. The message is for our logs only and must never contain
 * the upstream URL, the API key or any submitted name; callers see only publicMessage().
 */
class UpstreamUnavailableException extends RuntimeException
{
    private function __construct(
        string $internalMessage,
        private readonly int $httpStatus,
        private readonly string $publicMessage,
        private readonly ?string $retryAfter = null,
    ) {
        parent::__construct($internalMessage);
    }

    /** 503: timeout, unreachable, quota/rate limits, credential problems, upstream 5xx. */
    public static function unavailable(string $internalMessage, ?string $retryAfter = null): self
    {
        return new self($internalMessage, 503, 'Name lookup service is temporarily unavailable.', $retryAfter);
    }

    /** 502: upstream answered, but not with something we can use. */
    public static function invalidResponse(string $internalMessage): self
    {
        return new self($internalMessage, 502, 'Name lookup service returned an invalid response.');
    }

    public function report(): bool
    {
        Log::warning('Nationalize.io lookup failed: '.$this->getMessage(), ['status' => $this->httpStatus]);

        return true; // handled; skip the default reporter
    }

    public function render(): JsonResponse
    {
        return response()->json(
            ['message' => $this->publicMessage],
            $this->httpStatus,
            $this->retryAfter !== null ? ['Retry-After' => $this->retryAfter] : [],
        );
    }
}
