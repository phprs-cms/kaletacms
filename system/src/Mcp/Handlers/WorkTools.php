<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Core\Handover;

/**
 * MCP tools of the team's work (3.24): my_work – the lists of the admin screen My work for the connected user – and
 * assign – the responsible person of a page or a collection item, or who handles an enquiry. Responsibility only: it
 * never limits access; the person must be able to open the section. Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait WorkTools
{
    /** my_work */
    private function toolMyWork(string $name, array $a): mixed
    {
        $userId = $this->app->auth()->id();
        $lists = \Kaleta\Core\MyWork::lists($this->app, (new \Kaleta\Admin\Kernel($this->app))->modules(), $userId);
        $origin = $this->app->request->origin();
        $row = function (array $r) use ($origin): array {
            $out = ['id' => $r['id'], 'title' => $r['title']];
            foreach (['collection', 'about', 'status', 'priority', 'reasons', 'review_by', 'date'] as $key) {
                if (isset($r[$key]) && $r[$key] !== '' && $r[$key] !== []) {
                    $out[$key] = $key === 'date' ? substr((string) $r[$key], 0, 16) : $r[$key];
                }
            }
            if (($r['comments'] ?? 0) > 0) {
                $out['unresolved_comments'] = $r['comments'];
            }

            return $out + ['admin_url' => $origin . $this->app->url($r['url'])];
        };
        $keys = ['assigned' => 'enquiries_assigned', 'unassigned' => 'enquiries_unassigned', 'pages' => 'pages', 'items' => 'items', 'requests' => 'requests'];
        $out = ['user' => Handover::person($this->app->db(), $userId)];
        foreach ($lists as $list => $rows) {
            $out[$keys[$list]] = array_map($row, $rows);
        }
        $total = array_sum(array_map(count(...), $lists));

        return $out + ['total' => $total,
            'reasons' => 'overdue = the review date has passed, review = review due within ' . \Kaleta\Core\MyWork::REVIEW_DAYS . ' days, draft = unpublished changes, comments = unresolved comments on the draft, hidden = a hidden item changed lately',
            'next' => $total === 0 ? 'Nothing waits for this user.' : 'Tell the user what waits and where (admin_url). Enquiries contain personal data – open one with list_enquiries only when the user asks.'];
    }

    /** assign */
    private function toolAssign(string $name, array $a): mixed
    {
        $kind = (string) ($a['target'] ?? '');
        $section = Handover::KINDS[$kind][0] ?? throw new \InvalidArgumentException('target must be page, item or enquiry.');
        if (!isset((new \Kaleta\Admin\Kernel($this->app))->modules()[$section])) {
            throw new \DomainException('Only a user who may open ' . ['pages' => 'Pages', 'collections' => 'Collections', 'enquiries' => 'Enquiries'][$section] . ' can change who is responsible there.');
        }
        if (!array_key_exists('user', $a)) {
            throw new \InvalidArgumentException('user is required: a user id, the e-mail of an active account, "me" or "none".');
        }
        $id = (int) ($a['id'] ?? 0);
        $userId = Handover::resolve($this->app->db(), $a['user'], $this->app->auth()->id());
        $result = Handover::assign($this->app, $kind, $id, $userId);

        return ['target' => $kind, 'id' => $id, ($kind === 'enquiry' ? 'assignee' : 'owner') => Handover::person($this->app->db(), $userId), 'changed' => $result['changed'],
            'previous' => Handover::person($this->app->db(), $result['previous'])];
    }
}
