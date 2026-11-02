<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Core\PendingReview;

/**
 * "Waiting for you" over MCP (3.2, Core\PendingReview): what waits for a person, the same list as on the dashboard.
 * Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait PendingReviewTools
{
    /** list_pending_review */
    private function toolListPendingReview(string $name, array $a): mixed
    {
        // only the kinds this user may open in the administration
        $waiting = PendingReview::all($this->app, (new \Kaleta\Admin\Kernel($this->app))->modules());
        $origin = $this->app->request->origin();

        return [
            // drafted_by (3.24, N-U6): for page drafts, who saved each example's draft last and when – an added field, the shape stays
            'waiting' => array_map(fn (array $w): array => ['kind' => $w['kind'], 'label' => $w['label'], 'count' => $w['count'], 'admin_url' => $origin . $this->app->url($w['url']), 'examples' => $w['examples']]
                + (isset($w['drafted_by']) ? ['drafted_by' => $w['drafted_by']] : []), $waiting),
            'total' => array_sum(array_column($waiting, 'count')),
            'next' => $waiting === [] ? 'Nothing waits for a person.'
                : 'Tell the user what waits and where (admin_url). Publishing, applying and discarding are the user\'s – do it only when they explicitly ask, with a connection that may.',
        ];
    }
}
