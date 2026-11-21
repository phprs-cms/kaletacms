<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * 3.50 (N140): a batch of Core\WebImport ran OUTSIDE_TIME seconds past its end while a page was still downloading images or
 * files. Not an error and not a RuntimeException (those mean "this page failed"): import() keeps the page's position and
 * clears its "in progress" marker, so the next batch starts the page again and reuses what was downloaded already.
 *
 * @internal thrown and caught inside Core\WebImport only
 */
final class ImportPaused extends \Exception
{
}
