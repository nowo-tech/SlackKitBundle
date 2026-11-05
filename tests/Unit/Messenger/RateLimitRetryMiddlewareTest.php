<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Unit\Messenger;

use Nowo\SlackKitBundle\Api\SlackApiException;
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
use Throwable;

final class RateLimitRetryMiddlewareTest extends TestCase
{
    public function testConvertsRateLimitsToADelayedRetry(): void
    {
        try {
            $this->handleFailing(new SlackRateLimitedException(42));
            self::fail('no exception');
        } catch (RecoverableMessageHandlingException $e) {
            self::assertSame(42_000, $e->getRetryDelay());
        }
    }

    public function testOtherFailuresPassThrough(): void
    {
        $this->expectException(HandlerFailedException::class);
        $this->handleFailing(new SlackApiException('channel_not_found'));
    }

    public function testSuccessPassesThrough(): void
    {
        $envelope = new Envelope(new stdClass());
        self::assertSame($envelope, (new RateLimitRetryMiddleware())->handle($envelope, new StackMiddleware()));
    }

    private function handleFailing(Throwable $failure): void
    {
        $envelope = new Envelope(new stdClass());
        $failing  = new class($failure) implements MiddlewareInterface {
            public function __construct(private readonly Throwable $failure)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                throw new HandlerFailedException($envelope, [$this->failure]);
            }
        };
        (new RateLimitRetryMiddleware())->handle($envelope, new StackMiddleware($failing));
    }
}
