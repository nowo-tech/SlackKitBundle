<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

use function is_string;

/**
 * A fake https://slack.com/api/ for the test kernel (`framework.http_client.mock_response_factory`). It never reaches
 * the network; it records the calls.
 */
final class FakeSlackApi
{
    /** @var list<array{method: string, url: string, body: string}> */
    public array $calls = [];

    /** When set, every call answers `ok:false` with this error. */
    public ?string $failWith = null;

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        $body          = is_string($options['body'] ?? null) ? $options['body'] : '';
        $this->calls[] = ['method' => $method, 'url' => $url, 'body' => $body];
        $api           = (string) preg_replace('#^https://slack\.com/api/#', '', $url);
        if ($this->failWith !== null) {
            return Fake::error($this->failWith);
        }

        return match ($api) {
            'auth.test'           => Fake::ok(['team' => 'Fake team', 'team_id' => Fake::TEAM, 'user' => 'fakebot', 'user_id' => 'U0000000B', 'bot_id' => 'B00000000']),
            'conversations.open'  => Fake::ok(['channel' => ['id' => Fake::DM]]),
            'chat.postMessage'    => Fake::ok(['channel' => Fake::DM, 'ts' => '1793836800.000100']),
            'users.lookupByEmail' => Fake::ok(['user' => ['id' => Fake::USER]]),
            default               => Fake::ok(),
        };
    }

    /**
     * @return list<string>
     */
    public function methods(): array
    {
        return array_map(static fn (array $c): string => (string) preg_replace('#^https://slack\.com/api/#', '', $c['url']), $this->calls);
    }
}
