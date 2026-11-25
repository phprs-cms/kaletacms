<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * 3.54 (A2): a newsletter was not started because its number of recipients is no longer the one a person or Claude
 * confirmed – a subscriber confirmed or left between the count shown and the start. Nothing was sent and nothing was
 * queued: the newsletter is still a draft (or scheduled). The administration and MCP word the answer themselves from the
 * two numbers ("was N, now M – confirm again"); the message is the English fallback.
 */
final class RecipientCountChanged extends \DomainException
{
    public function __construct(public readonly int $expected, public readonly int $actual)
    {
        parent::__construct(sprintf('The number of recipients changed: was %d, now %d – confirm again.', $expected, $actual));
    }
}
