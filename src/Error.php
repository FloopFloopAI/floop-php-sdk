<?php

declare(strict_types=1);

namespace FloopFloop;

use DateTimeImmutable;
use Exception;

/**
 * Every FloopFloop SDK call throws this on non-2xx responses and on
 * network / timeout failures. Inspect `$err->code` to branch — unknown
 * server codes pass through verbatim rather than raising a subclass
 * we'd have to keep in sync.
 *
 * Known codes: UNAUTHORIZED, FORBIDDEN, VALIDATION_ERROR, RATE_LIMITED,
 * NOT_FOUND, CONFLICT, SERVICE_UNAVAILABLE, SERVER_ERROR, NETWORK_ERROR,
 * TIMEOUT, BUILD_FAILED, BUILD_CANCELLED, UNKNOWN.
 *
 * Example:
 *
 *     try {
 *         $client->projects()->status('p_1');
 *     } catch (\FloopFloop\Error $e) {
 *         if ($e->code === 'RATE_LIMITED') {
 *             sleep((int) $e->retryAfter ?: 5);
 *             // retry ...
 *         }
 *         throw $e;
 *     }
 *
 * @property-read string $code Application error code (string). Exposed via __get because Exception::$code is `protected int` and we need a public string; getCode() would break the inherited int return type.
 */
final class Error extends Exception
{
    public readonly int $status;
    public readonly ?string $requestId;
    /** Seconds to wait before retrying, parsed from Retry-After (delta-seconds OR HTTP-date). Null when not set. */
    public readonly ?float $retryAfter;

    public function __construct(
        string $code,
        string $message,
        int $status = 0,
        ?string $requestId = null,
        ?float $retryAfter = null,
    ) {
        parent::__construct($message);
        $this->code = $code;
        $this->status = $status;
        $this->requestId = $requestId;
        $this->retryAfter = $retryAfter;
    }

    public function __get(string $name): mixed
    {
        if ($name === 'code') {
            return $this->code;
        }
        throw new \LogicException(sprintf('Undefined property: %s::$%s', self::class, $name));
    }

    public function __isset(string $name): bool
    {
        return $name === 'code';
    }

    public function __toString(): string
    {
        $parts = "floop: [" . $this->code;
        if ($this->status > 0) {
            $parts .= " " . $this->status;
        }
        $parts .= "] " . $this->getMessage();
        if ($this->requestId !== null) {
            $parts .= " (request {$this->requestId})";
        }
        return $parts;
    }

    /**
     * Parse a Retry-After header per RFC 7231 — accepts either
     * delta-seconds or an HTTP-date. Returns null on empty/unparseable.
     */
    public static function parseRetryAfter(?string $header): ?float
    {
        if ($header === null || $header === '') {
            return null;
        }
        if (is_numeric($header)) {
            $secs = (float) $header;
            return $secs >= 0 ? $secs : null;
        }
        // HTTP-date: PHP's strtotime handles RFC 822 / 7231 formats fine.
        $ts = strtotime($header);
        if ($ts === false) {
            return null;
        }
        $delta = $ts - time();
        return $delta > 0 ? (float) $delta : 0.0;
    }
}
