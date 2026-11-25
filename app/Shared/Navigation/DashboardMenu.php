<?php

declare(strict_types=1);

namespace App\Shared\Navigation;

/**
 * Single source of truth for the dashboard sidebar (spec 1.7, X2KEY routes from Part 20).
 *
 * Each leaf has a unique `key` (also its route name, prefixed `dash.`), a URL path,
 * an optional query (for filter tabs that share one page), and the milestone that
 * implements the real screen. Routes for every leaf are registered from this tree,
 * so the sidebar and the router can never drift apart.
 *
 * Audience: `all` = every signed-in user; `seller` = users whose store opens the Seller Center. Others see
 * the Seller Center group with a single "Become a seller" entry, and no Storage group; the routes of
 * seller leaves redirect them to onboarding (`seller` middleware).
 *
 * @phpstan-type Leaf array{key: string, label: string, path: string, query?: array<string, string>, milestone: string}
 * @phpstan-type Node array{key: string, label: string, path?: string, query?: array<string, string>, milestone?: string, children?: list<array<string, mixed>>}
 * @phpstan-type Group array{key: string, label: string, icon: string, audience: string, children: list<Node>}
 */
final class DashboardMenu
{
    /**
     * @return list<Group>
     */
    public static function groups(): array
    {
        return [
            self::group('buy', 'menu.buy', 'cart', 'all', [
                self::leaf('buy.orders', 'menu.buy.orders', '/buy/orders', 'M6'),
                self::leaf('buy.comments', 'menu.buy.comments', '/buy/comments', 'M16'),
                self::leaf('buy.credits', 'menu.buy.credits', '/buy/credits', 'M9'),
                self::leaf('buy.points', 'menu.buy.points', '/buy/points', 'M9'),
                self::leaf('buy.funding', 'menu.buy.funding', '/buy/funding', 'M9'),
                self::leaf('buy.favorites', 'menu.buy.favorites', '/buy/favorites', 'M5'),
            ]),
            self::group('seller', 'menu.seller', 'store', 'seller', [
                self::leaf('seller.orders', 'menu.seller.orders', '/seller/orders', 'M8'),
                self::leaf('seller.analytics', 'menu.seller.analytics', '/seller/analytics', 'M20'),
                self::leaf('seller.offers.create', 'menu.seller.offers_create', '/seller/offers/create', 'M5'),
                self::leaf('seller.offers', 'menu.seller.offers', '/seller/offers', 'M5'),
                self::branch('seller.store', 'menu.seller.store', [
                    self::leaf('seller.store.mine', 'menu.seller.store.mine', '/seller/store', 'M4'),
                    self::leaf('seller.store.services', 'menu.seller.store.services', '/seller/services', 'M4'),
                    self::leaf('seller.store.coupons', 'menu.seller.store.coupons', '/seller/coupons', 'M14'),
                    self::leaf('seller.store.coupons_private', 'menu.seller.store.coupons_private', '/seller/coupons/private', 'M14'),
                    self::leaf('seller.store.comments', 'menu.seller.store.comments', '/seller/comments', 'M16'),
                ]),
                self::branch('seller.marketing', 'menu.seller.marketing', [
                    self::leaf('seller.marketing.emails', 'menu.seller.marketing.emails', '/seller/marketing/emails', 'M14'),
                    self::leaf('seller.marketing.records', 'menu.seller.marketing.records', '/seller/marketing/records', 'M14'),
                    self::leaf('seller.marketing.campaigns', 'menu.seller.marketing.campaigns', '/seller/campaigns', 'M14'),
                ]),
                self::branch('seller.withdrawals', 'menu.seller.withdrawals', [
                    self::leaf('seller.withdrawals.records', 'menu.seller.withdrawals.records', '/seller/withdrawals', 'M13'),
                    self::leaf('seller.withdrawals.methods', 'menu.seller.withdrawals.methods', '/seller/withdrawals/methods', 'M13'),
                ]),
                self::leaf('seller.info', 'menu.seller.info', '/seller/info', 'M4'),
                self::leaf('seller.violations', 'menu.seller.violations', '/seller/violations', 'M11'),
                self::leaf('seller.contacts', 'menu.seller.contacts', '/seller/contacts', 'M4'),
            ]),
            self::group('disputes', 'menu.disputes', 'scale', 'all', [
                self::leaf('disputes.index', 'menu.disputes.index', '/disputes', 'M11'),
                self::leaf('disputes.stats', 'menu.disputes.stats', '/disputes/stats', 'M11'),
            ]),
            self::group('tickets', 'menu.tickets', 'lifebuoy', 'all', [
                self::leaf('tickets.all', 'menu.tickets.all', '/tickets', 'M12'),
                self::leaf('tickets.pending', 'menu.tickets.pending', '/tickets', 'M12', ['status' => 'pending']),
                self::leaf('tickets.awaiting', 'menu.tickets.awaiting', '/tickets', 'M12', ['status' => 'awaiting_reply']),
                self::leaf('tickets.closed', 'menu.tickets.closed', '/tickets', 'M12', ['status' => 'closed']),
            ]),
            self::group('announcements', 'menu.announcements', 'bell', 'all', [
                self::leaf('announcements.all', 'menu.announcements.all', '/announcements', 'M9'),
                self::leaf('announcements.unread', 'menu.announcements.unread', '/announcements', 'M9', ['filter' => 'unread']),
                self::leaf('announcements.read', 'menu.announcements.read', '/announcements', 'M9', ['filter' => 'read']),
                self::leaf('announcements.starred', 'menu.announcements.starred', '/announcements', 'M9', ['filter' => 'starred']),
            ]),
            self::group('invoices', 'menu.invoices', 'receipt', 'all', [
                self::leaf('invoices.service', 'menu.invoices.service', '/invoices/service', 'M13'),
                self::leaf('invoices.vouchers', 'menu.invoices.vouchers', '/invoices/vouchers', 'M13'),
                self::leaf('invoices.info', 'menu.invoices.info', '/invoices/info', 'M13'),
            ]),
            self::group('coupons', 'menu.coupons', 'ticket', 'all', [
                self::leaf('coupons.shopping', 'menu.coupons.shopping', '/coupons/shopping', 'M14'),
                self::leaf('coupons.platform', 'menu.coupons.platform', '/coupons/platform', 'M14'),
            ]),
            self::group('affiliate', 'menu.affiliate', 'share', 'all', [
                self::leaf('affiliate.manage', 'menu.affiliate.manage', '/affiliate/manage', 'M15'),
                self::leaf('affiliate.referrals', 'menu.affiliate.referrals', '/affiliate/referrals', 'M15'),
                self::leaf('affiliate.videos', 'menu.affiliate.videos', '/affiliate/videos', 'M15'),
                self::leaf('affiliate.bills', 'menu.affiliate.bills', '/affiliate/bills', 'M15'),
                self::leaf('affiliate.assets', 'menu.affiliate.assets', '/affiliate/assets', 'M15'),
            ]),
            self::group('storage', 'menu.storage', 'vault', 'seller', [
                self::leaf('storage.groups', 'menu.storage.groups', '/seller/storage', 'M5'),
                self::leaf('storage.items', 'menu.storage.items', '/seller/storage/items', 'M5'),
                self::leaf('storage.recycle', 'menu.storage.recycle', '/seller/storage/recycle', 'M5'),
                self::leaf('storage.logs', 'menu.storage.logs', '/seller/storage/logs', 'M8'),
            ]),
            self::group('settings', 'menu.settings', 'gear', 'all', [
                self::leaf('settings.account', 'menu.settings.account', '/settings/account', 'M2'),
                self::leaf('settings.security', 'menu.settings.security', '/settings/security', 'M2'),
                self::leaf('settings.certification', 'menu.settings.certification', '/settings/certification', 'M2'),
                self::leaf('settings.delete', 'menu.settings.delete', '/settings/delete-account', 'M18'),
            ]),
        ];
    }

    /**
     * Pages reachable from the dashboard but not shown in the sidebar.
     *
     * @return list<Leaf>
     */
    public static function extraPages(): array
    {
        return [
            self::leaf('buy.points.redeem', 'menu.buy.points_redeem', '/buy/points/redeem', 'M9'),
        ];
    }

    /**
     * Every leaf of the tree, flattened.
     *
     * @return list<Leaf>
     */
    public static function leaves(): array
    {
        $leaves = [];

        foreach (self::groups() as $group) {
            $leaves = [...$leaves, ...self::collectLeaves($group['children'])];
        }

        return [...$leaves, ...self::extraPages()];
    }

    /**
     * @param  array<array-key, mixed>  $nodes
     * @return list<Leaf>
     */
    private static function collectLeaves(array $nodes): array
    {
        $leaves = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (isset($node['children']) && is_array($node['children'])) {
                $leaves = [...$leaves, ...self::collectLeaves($node['children'])];
            } elseif (self::isLeaf($node)) {
                $leaves[] = $node;
            }
        }

        return $leaves;
    }

    /**
     * @param  array<array-key, mixed>  $node
     *
     * @phpstan-assert-if-true Leaf $node
     */
    private static function isLeaf(array $node): bool
    {
        return is_string($node['key'] ?? null)
            && is_string($node['label'] ?? null)
            && is_string($node['path'] ?? null)
            && is_string($node['milestone'] ?? null);
    }

    /**
     * Paths of every leaf in a `seller` audience group (guarded by the `seller` middleware).
     *
     * @return list<string>
     */
    public static function sellerPaths(): array
    {
        $paths = [];

        foreach (self::groups() as $group) {
            if ($group['audience'] === 'seller') {
                $paths = [...$paths, ...array_column(self::collectLeaves($group['children']), 'path')];
            }
        }

        return array_values(array_unique($paths));
    }

    /** Shown instead of the Seller Center group to users without an active store. */
    public const string ONBOARDING_PATH = '/seller/onboarding';

    /**
     * Unique URL paths (several filter tabs share one path).
     *
     * @return array<string, Leaf> path => first leaf using it
     */
    public static function pages(): array
    {
        $pages = [];

        foreach (self::leaves() as $leaf) {
            $pages[$leaf['path']] ??= $leaf;
        }

        return $pages;
    }

    /**
     * @param  list<array<string, mixed>>  $children
     * @return Group
     */
    private static function group(string $key, string $label, string $icon, string $audience, array $children): array
    {
        /** @var Group */
        return compact('key', 'label', 'icon', 'audience', 'children');
    }

    /**
     * @param  list<array<string, mixed>>  $children
     * @return array{key: string, label: string, children: list<array<string, mixed>>}
     */
    private static function branch(string $key, string $label, array $children): array
    {
        return compact('key', 'label', 'children');
    }

    /**
     * @param  array<string, string>  $query
     * @return Leaf
     */
    private static function leaf(string $key, string $label, string $path, string $milestone, array $query = []): array
    {
        $leaf = compact('key', 'label', 'path', 'milestone');

        if ($query !== []) {
            $leaf['query'] = $query;
        }

        return $leaf;
    }
}
