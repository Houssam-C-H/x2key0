import { Link } from '@inertiajs/react';
import { categoryIcon, Icon } from '@/Components/Icon';
import { useShared } from '@/lib/i18n';
import { formatCount } from '@/lib/money';
import type { CategoryTab } from '@/types/catalog';

/** Category tabs of a game with counts (spec 1.4.1: "Accounts 4049 · Game DLC 1299 · …"). */
export function CategoryTabs({ tabs }: { tabs: CategoryTab[] }) {
    const { locale } = useShared();

    return (
        <nav aria-label="Categories" className="-mx-4 overflow-x-auto px-4">
            <ul className="flex gap-2">
                {tabs.map((tab) => (
                    <li key={tab.code}>
                        <Link
                            href={tab.href}
                            aria-current={tab.active ? 'page' : undefined}
                            className={`flex items-center gap-2 rounded-full border px-4 py-2 text-sm font-semibold whitespace-nowrap transition-colors ${
                                tab.active ? 'border-primary bg-primary text-white' : 'border-line bg-white hover:border-primary hover:text-primary'
                            }`}
                        >
                            <Icon name={categoryIcon[tab.code] ?? 'box'} size={16} />
                            {tab.label}
                            <span className={`text-xs ${tab.active ? 'text-white/80' : 'text-muted'}`}>{formatCount(tab.count, locale)}</span>
                        </Link>
                    </li>
                ))}
            </ul>
        </nav>
    );
}
