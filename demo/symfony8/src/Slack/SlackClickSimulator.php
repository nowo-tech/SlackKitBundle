<?php

declare(strict_types=1);

namespace App\Slack;

use App\FakeSlack\FakeSlackApi;
use Nowo\SlackKitBundle\Ingress\SignatureVerifier;
use Nowo\SlackKitBundle\Security\SlackSecret;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Plays Slack's part of a click: builds the `block_actions` payload of a recorded button, signs it with the demo
 * signing secret (v0 HMAC, current timestamp) and sends it to the bundle's real ingress as a sub-request. The
 * ingress then runs every check of a production click (signature, window, replay, team, nsk1 MAC, actor).
 */
final readonly class SlackClickSimulator
{
    /** Demo-only fake signing secret (seeded into the slack settings section by app:demo:seed). */
    public const SIGNING_SECRET = 'testsigningsecretnotreal00000000';

    public function __construct(private HttpKernelInterface $kernel)
    {
    }

    /**
     * @return array{status: int, body: string}
     */
    public function click(string $actionId, string $value, string $clicker, string $channel): array
    {
        $payload = [
            'type'       => 'block_actions',
            'team'       => ['id' => FakeSlackApi::TEAM],
            'user'       => ['id' => $clicker],
            'container'  => ['type' => 'message', 'channel_id' => $channel, 'message_ts' => time() . '.000100'],
            'channel'    => ['id' => $channel],
            'actions'    => [['type' => 'button', 'action_id' => $actionId, 'value' => $value, 'action_ts' => microtime(true) . '']],
        ];
        $body = http_build_query(['payload' => json_encode($payload, \JSON_THROW_ON_ERROR)]);
        $ts   = (string) time();

        $request = Request::create('/slack/interactions', 'POST', [], [], [], [
            'CONTENT_TYPE'                   => 'application/x-www-form-urlencoded',
            'HTTP_X_SLACK_REQUEST_TIMESTAMP' => $ts,
            'HTTP_X_SLACK_SIGNATURE'         => SignatureVerifier::sign(SlackSecret::signingSecret(self::SIGNING_SECRET), $ts, $body),
        ], $body);
        $response = $this->kernel->handle($request, HttpKernelInterface::SUB_REQUEST, false);

        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getContent()];
    }
}
