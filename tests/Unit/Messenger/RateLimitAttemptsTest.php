<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Messenger;

use Nowo\SlackKitBundle\Api\SlackRateLimitedException;
use Nowo\SlackKitBundle\Messenger\RateLimitRetryMiddleware;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * Audit FS-I7: `rate_limit.max_attempts` bounds the recoverable retries.
 */
final class RateLimitAttemptsTest extends TestCase
{
    public function testGivesUpAfterMaxAttempts(): void
    {
        $middleware = new RateLimitRetryMiddleware(3);
        try {
            $middleware->handle(new Envelope(new stdClass(), [new RedeliveryStamp(1)]), $this->failing());
            self::fail('no exception');
        } catch (RecoverableMessageHandlingException) {
        }

        $this->expectException(HandlerFailedException::class);
        $middleware->handle(new Envelope(new stdClass(), [new RedeliveryStamp(2)]), $this->failing());
    }

    private function failing(): StackMiddleware
    {
        return new StackMiddleware(new class implements MiddlewareInterface {
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                throw new HandlerFailedException($envelope, [new SlackRateLimitedException(5)]);
            }
        });
    }
}
