<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Linking;

use RuntimeException;

/**
 * Thrown by {@see SlackIdentityLinksInterface::saveLink()} when another account holds the (workspace, member) pair:
 * a store refuses, it never replaces (audit R2-M1). Map your unique-key violation on (workspace, member) to it.
 */
final class SlackIdentityConflictException extends RuntimeException
{
}
