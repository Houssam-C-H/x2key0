import { Link, useForm } from '@inertiajs/react';
import { bpsToPercent, Stars } from '@/Components/Seller/LevelBadge';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';
import type { SellerLevel } from '@/types/seller';

interface Step {
    type: string;
    status: 'none' | 'pending' | 'verified' | 'rejected';
    href: string;
}

interface Props {
    steps: Step[];
    canActivate: boolean;
    banned: boolean;
    levels: SellerLevel[];
}

/** Become a seller (spec 3.2 / 6.3): email, phone and identity verified → activate the store. */
export default function Onboarding({ steps, canActivate, banned, levels }: Props) {
    const t = useT();
    const { errors } = useShared();
    const form = useForm({});

    return (
        <DashboardLayout title={t('menu.seller.onboarding')} bare>
            <Panel title={t('seller.onboarding.title')} description={t('seller.onboarding.intro')}>
                {banned ? (
                    <p className="rounded-md bg-danger/5 p-3 text-sm text-danger">{t('seller.error.banned')}</p>
                ) : (
                    <>
                        <ol className="space-y-3">
                            {steps.map((step, i) => {
                                const done = step.status === 'verified';
                                return (
                                    <li key={step.type} className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-line p-4">
                                        <div className="flex items-center gap-3">
                                            <span
                                                className={`flex h-8 w-8 items-center justify-center rounded-full font-bold ${done ? 'bg-success text-white' : 'bg-primary-soft text-primary'}`}
                                                aria-hidden="true"
                                            >
                                                {done ? '✓' : i + 1}
                                            </span>
                                            <div>
                                                <p className="font-semibold">{t(`certification.type.${step.type}`)}</p>
                                                <p className="text-xs text-muted">{t(`certification.status.${step.status}`)}</p>
                                            </div>
                                        </div>
                                        {!done && step.status !== 'pending' && (
                                            <Link href={step.href} className="btn-outline-pill">
                                                {t('seller.onboarding.verify_now')}
                                            </Link>
                                        )}
                                    </li>
                                );
                            })}
                        </ol>

                        {errors.activate && <p className="mt-4 rounded-md bg-danger/5 p-3 text-sm text-danger">{errors.activate}</p>}

                        <div className="mt-6 flex flex-wrap items-center gap-4">
                            <button type="button" className="btn-primary" disabled={!canActivate || form.processing} onClick={() => form.post('/seller/activate')}>
                                {t('seller.onboarding.activate')}
                            </button>
                            {!canActivate && <p className="text-sm text-muted">{t('seller.onboarding.locked')}</p>}
                        </div>
                    </>
                )}
            </Panel>

            <Panel title={t('seller.levels.title')} description={t('seller.levels.intro')}>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="text-start text-xs uppercase text-muted">
                            <tr>
                                <th className="py-2 text-start">{t('seller.levels.level')}</th>
                                <th className="py-2 text-start">{t('seller.levels.commission')}</th>
                                <th className="py-2 text-start">{t('seller.levels.hold')}</th>
                                <th className="py-2 text-start">{t('seller.levels.reserve')}</th>
                                <th className="py-2 text-start">{t('seller.levels.max_offers')}</th>
                                <th className="py-2 text-start">{t('seller.levels.auto_approve')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {levels.map((level) => (
                                <tr key={level.code} className="border-t border-line">
                                    <td className="py-2 font-semibold">
                                        <Stars count={level.stars} /> {level.name}
                                    </td>
                                    <td className="py-2">{bpsToPercent(level.commissionBps)}</td>
                                    <td className="py-2">{t('seller.levels.days', { count: level.holdDays })}</td>
                                    <td className="py-2">{bpsToPercent(level.reserveBps)}</td>
                                    <td className="py-2">{level.maxActiveOffers}</td>
                                    <td className="py-2">{level.autoApprove ? t('common.yes') : t('common.no')}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Panel>
        </DashboardLayout>
    );
}
