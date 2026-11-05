<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Ingress;

use Nowo\SlackKitBundle\Security\SlackSecret;
use Psr\Clock\ClockInterface;

use function abs;
use function hash_equals;
use function hash_hmac;
use function preg_match;

/**
 * Slack request signatures, version 0: `v0=` + HMAC-SHA256(signing secret, `v0:<timestamp>:<raw body>`), compared in
 * constant time, inside a time window of at most 300 seconds (contract §5.2 steps 3–6).
 */
final readonly class SignatureVerifier
{
    /** Largest accepted window. */
    public const MAX_SKEW_SECONDS = 300;

    /**
     * @param ClockInterface $clock clock
     * @param int $maxSkewSeconds `ingress.max_skew_seconds`
     */
    public function __construct(
        private ClockInterface $clock,
        private int $maxSkewSeconds = self::MAX_SKEW_SECONDS,
    ) {
    }

    /**
     * Whether both headers are well-formed.
     */
    public function headersAreWellFormed(string $timestamp, string $signature): bool
    {
        return preg_match('/^\d{1,12}$/', $timestamp) === 1 && preg_match('/^v0=[a-f0-9]{64}$/', $signature) === 1;
    }

    /**
     * Whether the timestamp is inside the window (both directions).
     */
    public function isWithinWindow(string $timestamp): bool
    {
        return abs($this->clock->now()->getTimestamp() - (int) $timestamp) <= $this->maxSkewSeconds;
    }

    /**
     * Whether the signature matches the body for this secret.
     */
    public function matches(SlackSecret $secret, string $timestamp, string $signature, string $rawBody): bool
    {
        return hash_equals('v0=' . hash_hmac('sha256', 'v0:' . $timestamp . ':' . $rawBody, $secret->reveal()), $signature);
    }

    /**
     * Signs a body (fake Slack in tests and the demo).
     */
    public static function sign(SlackSecret $secret, string $timestamp, string $rawBody): string
    {
        return 'v0=' . hash_hmac('sha256', 'v0:' . $timestamp . ':' . $rawBody, $secret->reveal());
    }
}
