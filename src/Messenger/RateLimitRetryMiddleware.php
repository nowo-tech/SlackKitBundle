<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Messenger;

use Nowo\SlackKitBundle\Api\RecoverableSlackException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * Opt-in Messenger middleware (`messenger.retry_on_rate_limit`): a handler that fails with a Slack rate limit is
 * retried by the worker's retry strategy after `Retry-After` (a recoverable exception with that delay) instead of
 * being treated as an ordinary failure. Add it to a bus: `middleware: [nowo_slack_kit.messenger.rate_limit_retry]`.
 * After `rate_limit.max_attempts` deliveries (counted with Messenger's `RedeliveryStamp`) the original failure is
 * rethrown, so the transport's own retry limit and failure transport apply (audit FS-I7).
 */
final readonly class RateLimitRetryMiddleware implements MiddlewareInterface
{
    /**
     * @param int $maxAttempts deliveries before giving up (`rate_limit.max_attempts`, at least 1)
     */
    public function __construct(
        private int $maxAttempts = 3,
    ) {
    }

    /**
     * Passes the envelope on and converts Slack rate limits.
     *
     * @throws RecoverableMessageHandlingException with the Slack delay
     */
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (HandlerFailedException $e) {
            $attempt = 1 + ($envelope->last(RedeliveryStamp::class)?->getRetryCount() ?? 0);
            if ($attempt >= $this->maxAttempts) {
                throw $e;
            }
            foreach ($e->getWrappedExceptions(RecoverableSlackException::class, true) as $limited) {
                if ($limited instanceof RecoverableSlackException) {
                    throw new RecoverableMessageHandlingException('Slack rate limit: retry later.', 0, $e, $limited->retryAfterSeconds() * 1000);
                }
            }

            throw $e;
        }
    }
}
