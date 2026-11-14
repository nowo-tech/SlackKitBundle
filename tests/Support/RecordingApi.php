<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Support;

use Nowo\SlackKitBundle\Api\AuthTestResult;
use Nowo\SlackKitBundle\Api\PostedMessage;
use Nowo\SlackKitBundle\Api\SlackApiClientInterface;
use Nowo\SlackKitBundle\Api\SlackApiException;
use Nowo\SlackKitBundle\Api\SlackMessage;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Id\SlackChannelId;
use Nowo\SlackKitBundle\Id\SlackUserId;

/**
 * Fake Slack Web API that records calls; `$fail` makes every call throw that error code.
 */
final class RecordingApi implements SlackApiClientInterface
{
    /** @var list<array{0: string, 1: array<mixed>}> */
    public array $calls = [];

    public ?string $fail = null;

    public ?SlackUserId $lookup = null;

    /** @var list<string> installation key of every call, in order */
    public array $keys = [];

    public function authTest(SlackInstallationKey $installation): AuthTestResult
    {
        $this->keys[] = $installation->key();
        $this->record('auth.test', []);

        return new AuthTestResult('Fake team', Fake::TEAM, 'fakebot', 'U0000000B', 'B00000000');
    }

    public function openDirectMessage(SlackInstallationKey $installation, SlackUserId $user): SlackChannelId
    {
        $this->keys[] = $installation->key();
        $this->record('conversations.open', [$user->value]);

        return SlackChannelId::fromString(Fake::DM);
    }

    public function postMessage(SlackInstallationKey $installation, SlackChannelId $channel, SlackMessage $message): PostedMessage
    {
        $this->keys[] = $installation->key();
        $this->record('chat.postMessage', [$channel->value, $message]);

        return new PostedMessage($channel->value, '1.000100');
    }

    public function updateMessage(SlackInstallationKey $installation, PostedMessage $target, SlackMessage $message): void
    {
        $this->keys[] = $installation->key();
        $this->record('chat.update', [$target->channel, $target->ts, $message]);
    }

    public function deleteMessage(SlackInstallationKey $installation, PostedMessage $target): void
    {
        $this->keys[] = $installation->key();
        $this->record('chat.delete', [$target->channel, $target->ts]);
    }

    public function postEphemeral(SlackInstallationKey $installation, SlackChannelId $channel, SlackUserId $user, SlackMessage $message): void
    {
        $this->keys[] = $installation->key();
        $this->record('chat.postEphemeral', [$channel->value, $user->value, $message]);
    }

    public function lookupUserByEmail(SlackInstallationKey $installation, string $email): ?SlackUserId
    {
        $this->keys[] = $installation->key();
        $this->record('users.lookupByEmail', [$email]);

        return $this->lookup;
    }

    /**
     * @return list<string>
     */
    public function methods(): array
    {
        return array_map(static fn (array $c): string => $c[0], $this->calls);
    }

    /**
     * @param array<mixed> $args
     */
    private function record(string $method, array $args): void
    {
        $this->calls[] = [$method, $args];
        if ($this->fail !== null) {
            throw new SlackApiException($this->fail);
        }
    }
}
