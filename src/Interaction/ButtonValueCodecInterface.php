<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Interaction;

use Nowo\SlackKitBundle\Id\SlackTeamId;

/**
 * Signs and verifies `nsk1` button values (1.2, DDD P2).
 *
 * The documented API for host code that builds messages with signed buttons. {@see ButtonValueCodec} is the default
 * implementation (public alias); type-hint this interface so application services can be test-doubled. The bundle's
 * own services (interaction router, account linker) use the alias, so replacing it replaces the format everywhere:
 * keep the `nsk1` contract (contract §6.5) if Slack messages signed before the switch must still verify.
 */
interface ButtonValueCodecInterface
{
    /**
     * Signs data for a button, valid for `$ttlSeconds` (default `interactions.value_ttl`).
     *
     * @param string $actionId the button's `action_id`
     * @param array<string, mixed> $data the handler's data: strings, integers, booleans and nested objects (depth ≤ 3)
     * @param SlackTeamId|null $team the workspace the message goes to, when known
     * @param int|null $ttlSeconds lifetime in seconds (null: `interactions.value_ttl`)
     *
     * @throws InvalidButtonValueException `unencodable` when the data, the action id or the size is not allowed
     */
    public function sign(string $actionId, array $data, ?SlackTeamId $team = null, ?int $ttlSeconds = null): string;

    /**
     * Signs data with an explicit expiry (test vectors, host-chosen lifetimes).
     *
     * @param string $actionId the button's `action_id`
     * @param array<string, mixed> $data the handler's data
     * @param SlackTeamId|null $team the workspace the message goes to, when known
     * @param int $expiresAt Unix expiry time
     *
     * @throws InvalidButtonValueException `unencodable`
     */
    public function signUntil(string $actionId, array $data, ?SlackTeamId $team, int $expiresAt): string;

    /**
     * Verifies a value received in `actions[0].value`.
     *
     * @param string $actionId `actions[0].action_id` of the click
     * @param string $value `actions[0].value` of the click
     * @param string|null $clickTeamId `team.id` of the click
     *
     * @throws InvalidButtonValueException with the reason code
     */
    public function verify(string $actionId, string $value, ?string $clickTeamId): ButtonValue;
}
