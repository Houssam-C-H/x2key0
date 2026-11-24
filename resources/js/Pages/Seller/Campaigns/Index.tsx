import { router } from '@inertiajs/react';
import { useState } from 'react';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';

interface CampaignRow {
    id: number;
    name: string;
    description: string | null;
    type: string;
    startsAt: string;
    endsAt: string;
    running: boolean;
    maxOffers: number;
    subsidyBps: number;
    participation: { status: 'applied' | 'approved' | 'rejected' | 'withdrawn'; offers: string[]; rejectReason: string | null } | null;
}

interface Props {
    campaigns: CampaignRow[];
    offers: { id: string; title: string }[];
}

const STATUS_STYLE: Record<string, string> = {
    applied: 'bg-warning-soft text-ink',
    approved: 'bg-success/10 text-success',
    rejected: 'bg-danger/10 text-danger',
    withdrawn: 'bg-canvas text-muted',
};

/** Marketing Campaign (spec 14.2): apply to platform campaigns with offers; approved offers get the campaign badge. */
export default function SellerCampaigns({ campaigns, offers }: Props) {
    const t = useT();
    const { locale, errors } = useShared();
    const [open, setOpen] = useState<number | null>(null);
    const [picked, setPicked] = useState<string[]>([]);

    const start = (c: CampaignRow) => {
        setOpen(c.id);
        setPicked(c.participation && c.participation.status !== 'withdrawn' ? c.participation.offers : []);
    };
    const toggle = (id: string, max: number) => setPicked((p) => (p.includes(id) ? p.filter((x) => x !== id) : p.length >= max ? p : [...p, id]));

    return (
        <DashboardLayout title={t('menu.seller.marketing.campaigns')} bare>
            {campaigns.length === 0 && (
                <Panel title={t('menu.seller.marketing.campaigns')}>
                    <p className="text-sm text-muted">{t('campaign.empty')}</p>
                </Panel>
            )}
            {campaigns.map((c) => (
                <Panel key={c.id} title={c.name} description={c.description ?? undefined}>
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        <span className="rounded-full bg-primary-soft px-2 py-0.5 text-xs font-semibold text-primary">{t('campaign.type.' + c.type)}</span>
                        <span className="text-muted">
                            {new Date(c.startsAt).toLocaleDateString(locale)} – {new Date(c.endsAt).toLocaleDateString(locale)}
                        </span>
                        {c.running && <span className="text-xs font-semibold text-success">{t('campaign.running')}</span>}
                        {c.subsidyBps > 0 && <span className="text-xs text-muted">{t('campaign.subsidy', { rate: (c.subsidyBps / 100).toFixed(2) })}</span>}
                        <span className="text-xs text-muted">{t('campaign.max_offers', { count: c.maxOffers })}</span>
                        {c.participation && (
                            <span className={'ms-auto rounded-full px-2 py-0.5 text-xs font-semibold ' + STATUS_STYLE[c.participation.status]}>{t('campaign.status.' + c.participation.status)}</span>
                        )}
                    </div>
                    {c.participation?.rejectReason && <p className="mt-2 text-sm text-danger">{c.participation.rejectReason}</p>}

                    {open === c.id ? (
                        <div className="mt-3 space-y-3">
                            <p className="text-sm text-muted">{t('campaign.pick', { picked: picked.length, max: c.maxOffers })}</p>
                            <ul className="max-h-64 space-y-1 overflow-y-auto rounded-md border border-line p-2">
                                {offers.length === 0 && <li className="text-sm text-muted">{t('campaign.no_offers')}</li>}
                                {offers.map((o) => (
                                    <li key={o.id}>
                                        <label className="flex items-center gap-2 text-sm">
                                            <input type="checkbox" checked={picked.includes(o.id)} onChange={() => toggle(o.id, c.maxOffers)} />
                                            {o.title}
                                        </label>
                                    </li>
                                ))}
                            </ul>
                            {errors.offers && <p className="text-sm text-danger">{errors.offers}</p>}
                            <div className="flex gap-2">
                                <button
                                    type="button"
                                    className="btn-primary"
                                    disabled={picked.length === 0}
                                    onClick={() => router.post('/seller/campaigns/' + c.id + '/apply', { offers: picked }, { preserveScroll: true, onSuccess: () => setOpen(null) })}
                                >
                                    {t('campaign.submit')}
                                </button>
                                <button type="button" className="btn-outline-pill" onClick={() => setOpen(null)}>
                                    {t('common.cancel')}
                                </button>
                            </div>
                        </div>
                    ) : (
                        <div className="mt-3 flex gap-2">
                            {c.participation?.status !== 'approved' && (
                                <button type="button" className="btn-primary px-3 py-1 text-sm" onClick={() => start(c)}>
                                    {c.participation && c.participation.status !== 'withdrawn' ? t('campaign.edit') : t('campaign.apply')}
                                </button>
                            )}
                            {c.participation && ['applied', 'approved'].includes(c.participation.status) && (
                                <button type="button" className="btn-outline-pill px-3 py-1 text-sm" onClick={() => router.post('/seller/campaigns/' + c.id + '/withdraw', {}, { preserveScroll: true })}>
                                    {t('campaign.withdraw')}
                                </button>
                            )}
                        </div>
                    )}
                </Panel>
            ))}
        </DashboardLayout>
    );
}
