<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Support;

use Nowo\SlackKitBundle\Api\SlackApiException;
use Nowo\SlackKitBundle\Api\SlackUserDirectoryInterface;
use Nowo\SlackKitBundle\Api\SlackUserProfile;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Id\SlackUserId;

/**
 * Fake `users.info`: one profile per member id; `$fail` makes every call throw that code.
 */
final class FakeDirectory implements SlackUserDirectoryInterface
{
    /** @var array<string, SlackUserProfile> */
    public array $profiles = [];

    /** @var list<string> installation key of every call */
    public array $calls = [];

    public ?string $fail = null;

    public function userProfile(SlackInstallationKey $installation, SlackUserId $user): SlackUserProfile
    {
        $this->calls[] = $installation->key();
        if ($this->fail !== null) {
            throw new SlackApiException($this->fail);
        }

        return $this->profiles[$user->value] ?? new SlackUserProfile($user, 'Member ' . $user->value);
    }
}
