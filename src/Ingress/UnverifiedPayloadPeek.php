<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Ingress;

use JsonException;
use Nowo\SlackKitBundle\Interaction\ButtonValueCodec;

use function count;
use function explode;
use function is_array;
use function is_string;
use function json_decode;
use function parse_str;
use function preg_match;
use function str_starts_with;

use const JSON_THROW_ON_ERROR;

/**
 * A bounded, **unverified** view of an interaction body for {@see SigningSecretResolverInterface}: it only exposes
 * string fields of the first action's data that match a pattern the host declares (contract §5.2 step 5). Nothing read
 * here is trusted; it only chooses which secret to try.
 */
final readonly class UnverifiedPayloadPeek
{
    /** @var array<string, mixed> */
    private array $data;

    /**
     * @param string $rawBody the raw interaction body (already size-capped)
     */
    public function __construct(string $rawBody)
    {
        $this->data = $this->actionData($rawBody);
    }

    /**
     * A string of the first action's data (`nsk1` payload `d`, or the legacy JSON value), only if it matches.
     */
    public function actionDataString(string $key, string $pattern): ?string
    {
        $value = $this->data[$key] ?? null;

        return is_string($value) && preg_match($pattern, $value) === 1 ? $value : null;
    }

    /**
     * The **unverified** workspace id of a body: `team.id` of an interaction payload (`$form`), `team_id` of an Events
     * API envelope. It only chooses which installation's own-app secret to try (spec 237 FR-004).
     */
    public static function teamId(string $rawBody, bool $form): ?string
    {
        try {
            if ($form) {
                parse_str($rawBody, $fields);
                $payload = $fields['payload'] ?? null;
                $decoded = is_string($payload) ? json_decode($payload, true, 32, JSON_THROW_ON_ERROR) : null;
                $team    = is_array($decoded) && is_array($decoded['team'] ?? null) ? ($decoded['team']['id'] ?? null) : null;
            } else {
                $decoded = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
                $team    = is_array($decoded) ? ($decoded['team_id'] ?? null) : null;
            }
        } catch (JsonException) {
            return null;
        }

        return is_string($team) ? $team : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function actionData(string $rawBody): array
    {
        parse_str($rawBody, $fields);
        $payload = $fields['payload'] ?? null;
        if (!is_string($payload)) {
            return [];
        }
        try {
            $decoded = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
            $actions = is_array($decoded) ? ($decoded['actions'] ?? null) : null;
            $value   = is_array($actions) && is_array($actions[0] ?? null) ? ($actions[0]['value'] ?? null) : null;
            if (!is_string($value)) {
                return [];
            }
            if (str_starts_with($value, ButtonValueCodec::VERSION . '.')) {
                $parts = explode('.', $value);
                $json  = count($parts) === 3 ? ButtonValueCodec::base64UrlDecode($parts[1]) : null;
                $inner = $json !== null ? json_decode($json, true, 8, JSON_THROW_ON_ERROR) : null;
                $data  = is_array($inner) ? ($inner['d'] ?? null) : null;
            } else {
                $data = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
            }
        } catch (JsonException) {
            return [];
        }

        /** @var array<string, mixed> $result */
        $result = is_array($data) ? $data : [];

        return $result;
    }
}
