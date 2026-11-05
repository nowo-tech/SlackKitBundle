<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Api;

/**
 * A message Slack accepted: its conversation and timestamp (the pair `chat.update` / `chat.delete` need).
 */
final readonly class PostedMessage
{
    /**
     * @param string $channel conversation id
     * @param string $ts message timestamp
     */
    public function __construct(
        public string $channel,
        public string $ts,
    ) {
    }
}
