<?php

declare(strict_types=1);

namespace App\Shared\Navigation;

use Illuminate\Http\Request;

/**
 * Turns DashboardMenu into translated, href-resolved props and marks the active leaf.
 *
 * Active leaf = same path, and its query matches the request on the keys that the
 * leaves of that path filter by (e.g. `/tickets?status=closed` → "Closed").
 */
final class DashboardMenuPresenter
{
    /**
     * @return array{groups: list<array<string, mixed>>, active: array{key: string, label: string, milestone: string, group: string|null}|null}
     */
    public function present(Request $request): array
    {
        $path = '/'.ltrim($request->path(), '/');
        $activeKey = $this->activeKey($path, $request);
        $active = null;

        $groups = [];

        foreach (DashboardMenu::groups() as $group) {
            $children = $this->nodes($group['children'], $activeKey, $group['label'], $active);

            $groups[] = [
                'key' => $group['key'],
                'label' => $this->trans($group['label']),
                'icon' => $group['icon'],
                'audience' => $group['audience'],
                'open' => $this->containsKey($group['children'], $activeKey),
                'children' => $children,
            ];
        }

        if ($active === null && $activeKey !== null) {
            foreach (DashboardMenu::extraPages() as $leaf) {
                if ($leaf['key'] === $activeKey) {
                    $active = ['key' => $leaf['key'], 'label' => $this->trans($leaf['label']), 'milestone' => $leaf['milestone'], 'group' => null];
                }
            }
        }

        return ['groups' => $groups, 'active' => $active];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  array{key: string, label: string, milestone: string, group: string|null}|null  $active
     * @return list<array<string, mixed>>
     */
    private function nodes(array $nodes, ?string $activeKey, string $groupLabel, ?array &$active): array
    {
        $out = [];

        foreach ($nodes as $node) {
            /** @var string $key */
            $key = $node['key'];
            /** @var string $label */
            $label = $node['label'];

            if (isset($node['children'])) {
                /** @var list<array<string, mixed>> $children */
                $children = $node['children'];
                $out[] = [
                    'key' => $key,
                    'label' => $this->trans($label),
                    'open' => $this->containsKey($children, $activeKey),
                    'children' => $this->nodes($children, $activeKey, $groupLabel, $active),
                ];

                continue;
            }

            /** @var string $path */
            $path = $node['path'];
            /** @var array<string, string> $query */
            $query = $node['query'] ?? [];
            /** @var string $milestone */
            $milestone = $node['milestone'];

            $isActive = $key === $activeKey;

            if ($isActive) {
                $active = ['key' => $key, 'label' => $this->trans($label), 'milestone' => $milestone, 'group' => $this->trans($groupLabel)];
            }

            $out[] = [
                'key' => $key,
                'label' => $this->trans($label),
                'href' => $path.($query === [] ? '' : '?'.http_build_query($query)),
                'active' => $isActive,
            ];
        }

        return $out;
    }

    private function trans(string $key): string
    {
        $value = __($key);

        return is_string($value) ? $value : $key;
    }

    private function activeKey(string $path, Request $request): ?string
    {
        $candidates = array_values(array_filter(
            DashboardMenu::leaves(),
            static fn (array $leaf): bool => $leaf['path'] === $path,
        ));

        if ($candidates === []) {
            return null;
        }

        $filterKeys = [];
        foreach ($candidates as $leaf) {
            $filterKeys = [...$filterKeys, ...array_keys($leaf['query'] ?? [])];
        }

        $requestFilters = array_filter(
            $request->only(array_unique($filterKeys)),
            static fn (mixed $v): bool => is_string($v) && $v !== '',
        );

        foreach ($candidates as $leaf) {
            if (($leaf['query'] ?? []) == $requestFilters) {
                return $leaf['key'];
            }
        }

        // Unknown filter value: fall back to the unfiltered tab.
        foreach ($candidates as $leaf) {
            if (! isset($leaf['query'])) {
                return $leaf['key'];
            }
        }

        return $candidates[0]['key'];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function containsKey(array $nodes, ?string $key): bool
    {
        if ($key === null) {
            return false;
        }

        foreach ($nodes as $node) {
            if (($node['key'] ?? null) === $key) {
                return true;
            }

            /** @var list<array<string, mixed>> $children */
            $children = $node['children'] ?? [];

            if ($children !== [] && $this->containsKey($children, $key)) {
                return true;
            }
        }

        return false;
    }
}
