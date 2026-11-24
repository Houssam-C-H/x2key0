import { router, useForm } from '@inertiajs/react';
import { CopyField } from '@/Components/Affiliate/CopyField';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { formatUsd } from '@/lib/money';
import { useShared, useT } from '@/lib/i18n';

interface Dashboard {
    code: string;
    status: string;
    rank: number;
    link: string;
    rates: { direct: number; level2: number; days: number };
    balances: { pending: number; available: number };
    stats: { clicks: number; signups: number; direct: number; indirect: number; orders: number; earned: number };
    links: { url: string; target: string; clicks: number; signups: number }[];
}

interface Props {
    affiliate: Dashboard | null;
    transferMin: number;
    defaults: { direct: number; level2: number; days: number };
    idempotencyKey: string;
}

const pct = (bps: number) => (bps / 100).toFixed(bps % 100 === 0 ? 0 : 2);

/** Manage Affiliate Program (spec Part 15): join, balances + Transfer to Wallet, invitation link, promotional links, custom code. */
export default function AffiliateManage({ affiliate, transferMin, defaults, idempotencyKey }: Props) {
    const t = useT();
    const { locale, errors } = useShared();
    const money = (cents: number) => formatUsd(cents, locale) ?? '';
    const transfer = useForm({ amount: '', idempotency_key: idempotencyKey });
    const code = useForm({ code: affiliate?.code ?? '' });
    const link = useForm({ target: '' });

    if (affiliate === null) {
        return (
            <DashboardLayout title={t('menu.affiliate.manage')} bare>
                <Panel title={t('affiliate.join.title')} description={t('affiliate.rule', { direct: pct(defaults.direct), level2: pct(defaults.level2), days: defaults.days })}>
                    <ol className="mb-4 list-decimal space-y-1 ps-5 text-sm text-muted">
                        <li>{t('affiliate.landing.step1')}</li>
                        <li>{t('affiliate.landing.step2')}</li>
                        <li>{t('affiliate.landing.step3')}</li>
                        <li>{t('affiliate.landing.step4')}</li>
                    </ol>
                    <button type="button" className="btn-primary" onClick={() => router.post('/affiliate/enrol')}>
                        {t('affiliate.join.cta')}
                    </button>
                </Panel>
            </DashboardLayout>
        );
    }

    const suspended = affiliate.status === 'suspended';

    return (
        <DashboardLayout title={t('menu.affiliate.manage')} bare>
            {suspended && <p className="rounded-md bg-danger/10 p-3 text-sm text-danger">{t('affiliate.suspended')}</p>}

            <section className="grid gap-4 md:grid-cols-3">
                <div className="card p-5">
                    <p className="text-sm text-muted">{t('affiliate.balance.available')}</p>
                    <p className="mt-1 text-2xl font-extrabold">{money(affiliate.balances.available)}</p>
                    <form
                        className="mt-3 flex gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            transfer.post('/affiliate/transfer', { preserveScroll: true, onSuccess: () => transfer.reset('amount') });
                        }}
                    >
                        <input className="field w-28" type="number" min={transferMin / 100} step="0.01" value={transfer.data.amount} onChange={(e) => transfer.setData('amount', e.target.value)} aria-label={t('affiliate.transfer.amount')} placeholder={(transferMin / 100).toFixed(2)} />
                        <button type="submit" className="btn-primary px-3 py-1 text-sm" disabled={suspended || transfer.processing || affiliate.balances.available < transferMin}>
                            {t('affiliate.transfer.cta')}
                        </button>
                    </form>
                    <p className="mt-1 text-xs text-muted">{t('affiliate.transfer.help', { amount: money(transferMin) })}</p>
                    {errors.amount && <p className="mt-1 text-sm text-danger">{errors.amount}</p>}
                </div>
                <div className="card p-5">
                    <p className="text-sm text-muted">{t('affiliate.balance.pending')}</p>
                    <p className="mt-1 text-2xl font-extrabold">{money(affiliate.balances.pending)}</p>
                    <p className="mt-1 text-xs text-muted">{t('affiliate.balance.pending_help')}</p>
                </div>
                <div className="card p-5">
                    <p className="text-sm text-muted">{t('affiliate.stats.earned')}</p>
                    <p className="mt-1 text-2xl font-extrabold">{money(affiliate.stats.earned)}</p>
                    <p className="mt-1 text-xs text-muted">{t('affiliate.rank', { rank: affiliate.rank })}</p>
                </div>
            </section>

            <Panel title={t('affiliate.invite.title')} description={t('affiliate.rule', { direct: pct(affiliate.rates.direct), level2: pct(affiliate.rates.level2), days: affiliate.rates.days })}>
                <CopyField value={affiliate.link} label={t('affiliate.invite.link')} />
                <dl className="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-5">
                    {(['clicks', 'signups', 'direct', 'indirect', 'orders'] as const).map((k) => (
                        <div key={k} className="rounded-md bg-canvas p-3">
                            <dt className="text-xs text-muted">{t('affiliate.stats.' + k)}</dt>
                            <dd className="text-lg font-bold">{affiliate.stats[k]}</dd>
                        </div>
                    ))}
                </dl>
            </Panel>

            <Panel title={t('affiliate.links.title')} description={t('affiliate.links.help')}>
                <form
                    className="flex flex-wrap gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        link.post('/affiliate/links', { preserveScroll: true, onSuccess: () => link.reset() });
                    }}
                >
                    <input className="field min-w-64 flex-1" value={link.data.target} onChange={(e) => link.setData('target', e.target.value)} placeholder={'/' + locale + '/games'} aria-label={t('affiliate.links.target')} />
                    <button type="submit" className="btn-primary" disabled={suspended || link.processing || link.data.target.trim() === ''}>
                        {t('affiliate.links.create')}
                    </button>
                </form>
                {errors.target && <p className="mt-1 text-sm text-danger">{errors.target}</p>}
                {affiliate.links.length > 0 && (
                    <ul className="mt-4 space-y-3">
                        {affiliate.links.map((l) => (
                            <li key={l.url} className="space-y-1">
                                <p className="truncate text-xs text-muted">
                                    {l.target} · {t('affiliate.stats.clicks')}: {l.clicks} · {t('affiliate.stats.signups')}: {l.signups}
                                </p>
                                <CopyField value={l.url} label={l.url} />
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>

            <Panel title={t('affiliate.code.title')} description={t('affiliate.code.help')}>
                <form
                    className="flex flex-wrap gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        code.post('/affiliate/code', { preserveScroll: true });
                    }}
                >
                    <input className="field w-48 font-mono uppercase" maxLength={16} value={code.data.code} onChange={(e) => code.setData('code', e.target.value)} aria-label={t('affiliate.code.title')} />
                    <button type="submit" className="btn-outline-pill" disabled={suspended || code.processing}>
                        {t('common.save')}
                    </button>
                </form>
                {errors.code && <p className="mt-1 text-sm text-danger">{errors.code}</p>}
            </Panel>
        </DashboardLayout>
    );
}
