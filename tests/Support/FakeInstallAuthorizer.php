<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Tests\Support;

use Nowo\SlackKitBundle\Install\SlackInstallAttempt;
use Nowo\SlackKitBundle\Install\SlackInstallAuthorizerInterface;

/**
 * Host authoriser of the tests: `admin` may install for `organization:org-1` only, unless `$allow` is turned off.
 */
final class FakeInstallAuthorizer implements SlackInstallAuthorizerInterface
{
    public bool $allow = true;

    /** @var list<SlackInstallAttempt> */
    public array $asked = [];

    public function mayInstall(SlackInstallAttempt $attempt): bool
    {
        $this->asked[] = $attempt;

        return $this->allow && $attempt->accountId === 'admin' && $attempt->scope->type === 'organization' && $attempt->scope->id === 'org-1';
    }
}
