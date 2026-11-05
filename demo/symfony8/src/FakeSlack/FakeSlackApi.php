<?php

declare(strict_types=1);

namespace App\FakeSlack;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * In-app fake Slack Web API (owner rule: the demo never talks to a real workspace). Wired as the HttpClient
 * `mock_response_factory`, it answers the methods the bundle calls with hand-written responses and records each call
 * (method and JSON/form body, never the Authorization header) in var/fake-slack.json for the home page.
 */
final readonly class FakeSlackApi
{
    public const TEAM    = 'T00000000';
    public const USER    = 'U00000001';
    public const DM      = 'D00000001';
    public const CHANNEL = 'C00000001';

    private const MAX_CALLS = 30;

    public function __construct(private string $logFile)
    {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        $api  = (string) preg_replace('#^https://slack\.com/api/#', '', $url);
        $body = \is_string($options['body'] ?? null) ? $options['body'] : '';
        $this->record($api, $body);

        $answer = match ($api) {
            'auth.test'           => ['team' => 'Demo workspace', 'team_id' => self::TEAM, 'user' => 'demobot', 'user_id' => 'U0000000B', 'bot_id' => 'B00000000'],
            'conversations.open'  => ['channel' => ['id' => self::DM]],
            'chat.postMessage'    => ['channel' => $this->channelOf($body), 'ts' => sprintf('%d.%06d', time(), \count($this->calls()))],
            'users.lookupByEmail' => ['user' => ['id' => self::USER]],
            'chat.update', 'chat.delete', 'chat.postEphemeral' => [],
            default => null,
        };

        return new MockResponse(
            (string) json_encode($answer === null ? ['ok' => false, 'error' => 'unknown_method'] : ['ok' => true] + $answer),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
        );
    }

    /**
     * Recorded calls, newest first.
     *
     * @return list<array{method: string, body: array<string, mixed>}>
     */
    public function calls(): array
    {
        $raw   = is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '[]';
        $calls = json_decode($raw, true);

        return \is_array($calls) ? array_values(array_filter($calls, \is_array(...))) : [];
    }

    /**
     * Forgets every recorded call.
     */
    public function clear(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    private function record(string $method, string $body): void
    {
        $decoded = json_decode($body, true);
        if (!\is_array($decoded)) {
            parse_str($body, $decoded);
            unset($decoded['token']);
        }
        $calls = $this->calls();
        array_unshift($calls, ['method' => $method, 'body' => $decoded]);
        if (!is_dir(\dirname($this->logFile))) {
            mkdir(\dirname($this->logFile), 0o775, true);
        }
        file_put_contents($this->logFile, (string) json_encode(\array_slice($calls, 0, self::MAX_CALLS), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), \LOCK_EX);
    }

    private function channelOf(string $body): string
    {
        $decoded = json_decode($body, true);

        return \is_array($decoded) && \is_string($decoded['channel'] ?? null) ? $decoded['channel'] : self::CHANNEL;
    }
}
