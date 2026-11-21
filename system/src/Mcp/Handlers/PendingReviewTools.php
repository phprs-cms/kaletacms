<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Core\PendingReview;
use Kaleta\Mcp\Schema;

/**
 * "Waiting for you" over MCP (3.2, Core\PendingReview): what waits for a person, the same list as on the dashboard.
 * Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait PendingReviewTools
{
    /**
     * The MCP definitions of the tools in this trait (3.49 P0): stored name => [description, input schema]. Tools::definitions()
     * lists them in the order of Tools::ORDER; the English names and texts are in Translator, the annotations in Catalog.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function pendingReviewToolsDefinitions(): array
    {
        return [
            'list_pending_review' => ['What waits for a person (read-only, 3.2) – the same list as “Waiting for you” on the dashboard: pages with unpublished draft builds, site parts with drafts, the draft look, news drafts to publish, hidden collection items changed in the last 30 days, proposed exceptions to the opening hours, requests Claude finished in the last 14 days, and unresolved comments on drafts. '
                . 'Each kind with its count, the admin link and up to 5 examples; only what this user may open. Use it to tell the user what is ready for review – and never to publish it yourself.',
                Schema::object([])],
        ];
    }

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
