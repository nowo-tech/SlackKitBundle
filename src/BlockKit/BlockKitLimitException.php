<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\BlockKit;

use InvalidArgumentException;

/**
 * A Block Kit structure Slack would refuse (too many blocks or elements, a value or action id too long, a bad URL).
 */
final class BlockKitLimitException extends InvalidArgumentException
{
}
