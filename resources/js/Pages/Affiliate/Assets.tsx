import { router } from '@inertiajs/react';
import { CopyField } from '@/Components/Affiliate/CopyField';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useT } from '@/lib/i18n';

interface Asset {
    id: number;
    title: string;
    type: 'banner' | 'logo' | 'video' | 'copy';
    theme: string;
    size: string | null;
    url: string | null;
    copy: string | null;
}

/** Creative Assets (spec Part 15): banners, logos, videos and copy by theme and size, in the viewer's language. */
export default function AffiliateAssets({ theme, themes, assets }: { theme: string | null; themes: string[]; assets: Asset[] }) {
    const t = useT();

    return (
        <DashboardLayout title={t('menu.affiliate.assets')} bare>
            <Panel title={t('menu.affiliate.assets')} description={t('affiliate.assets.help')}>
                <div className="mb-4 flex flex-wrap gap-2">
                    {[null, ...themes].map((th) => (
                        <button
                            key={th ?? 'all'}
                            type="button"
                            aria-pressed={theme === th}
                            onClick={() => router.get('/affiliate/assets', th === null ? {} : { theme: th }, { preserveScroll: true, preserveState: true })}
                            className={'rounded-full border px-3 py-1 text-sm font-semibold ' + (theme === th ? 'border-primary bg-primary-soft text-primary' : 'border-line')}
                        >
                            {th === null ? t('announcements.filter.all') : th}
                        </button>
                    ))}
                </div>
                {assets.length === 0 ? (
                    <p className="text-sm text-muted">{t('affiliate.assets.empty')}</p>
                ) : (
                    <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {assets.map((a) => (
                            <li key={a.id} className="space-y-2 rounded-lg border border-line p-3">
                                <p className="text-sm font-semibold">
                                    {a.title} <span className="text-xs font-normal text-muted">{[a.type, a.size].filter(Boolean).join(' · ')}</span>
                                </p>
                                {a.url && (a.type === 'banner' || a.type === 'logo') && <img src={a.url} alt={a.title} loading="lazy" className="max-h-40 w-full rounded object-contain bg-canvas" />}
                                {a.copy && <CopyField value={a.copy} label={'asset-' + a.id} />}
                                {a.url && (
                                    <a href={a.url} target="_blank" rel="noopener noreferrer" className="btn-outline-pill inline-block px-3 py-1 text-sm">
                                        {t('affiliate.assets.download')}
                                    </a>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>
        </DashboardLayout>
    );
}
