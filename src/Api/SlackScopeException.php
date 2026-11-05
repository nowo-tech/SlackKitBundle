<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Api;

use function is_string;
use function preg_match;

/**
 * `missing_scope`: the installation lacks the scope the method needs.
 */
final class SlackScopeException extends SlackApiException
{
    /** The scope Slack says is needed (`^[a-z:._,]{1,128}$`), or null. */
    public readonly ?string $neededScope;

    /**
     * @param mixed $needed the `needed` field of the answer
     */
    public function __construct(mixed $needed)
    {
        $this->neededScope = is_string($needed) && preg_match('/^[a-z:._,]{1,128}$/', $needed) === 1 ? $needed : null;
        parent::__construct('missing_scope');
    }
}
