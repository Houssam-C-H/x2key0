import { Link } from '@inertiajs/react';
import { Breadcrumbs } from '@/Components/Catalog/Breadcrumbs';
import { NoticeBox } from '@/Components/Catalog/NoticeBox';
import { SeoHead } from '@/Components/Catalog/SeoHead';
import { BuyBox } from '@/Components/Offer/BuyBox';
import { HistoryStrip } from '@/Components/Offer/HistoryStrip';
import { PublicLayout } from '@/Layouts/PublicLayout';
import { useT } from '@/lib/i18n';
import { deliveryTime } from '@/lib/offer';
import type { Crumb, GameCategoryInfo, Seo } from '@/types/catalog';
import type { HistoryItem, OfferRow } from '@/types/offer';

interface Props {
    offer: OfferRow & {
        description: string;
        attributes: { label: string; value: string }[];
        recoveryDays: number | null;
        gameCategory: GameCategoryInfo;
        gameCategoryHref: string;
        product: { name: string; href: string } | null;
    };
    preview: string | null;
    history: HistoryItem[];
    breadcrumbs: Crumb[];
    seo: Seo;
}

/** Single offer page (spec 1.5 `/offer/{id}`): buy box, attributes, seller description. */
export default function Offer({ offer, preview, history, breadcrumbs, seo }: Props) {
    const t = useT();
    const facts = [
        { label: t('offer.delivery_method'), value: t(`offer.delivery.${offer.deliveryMethod}`) },
        { label: t('offer.delivery_time'), value: offer.auto ? t('offer.instant') : deliveryTime(offer.deliveryMinutes, t) },
        { label: t('offer.insurance'), value: offer.insuranceDays > 0 ? t('offer.insurance_days', { count: offer.insuranceDays }) : '—' },
        ...(offer.recoveryDays ? [{ label: t('offer.recovery'), value: t('offer.insurance_days', { count: offer.recoveryDays }) }] : []),
        ...offer.attributes,
    ];

    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <div className="mx-auto max-w-7xl space-y-6 px-4 py-6">
                <Breadcrumbs items={breadcrumbs} />
                {preview && <p className="rounded-md bg-warning/15 p-3 text-sm font-semibold text-warning">{t('offer.preview_banner', { status: t(`offer.status.${preview}`) })}</p>}

                <div className="grid gap-6 lg:grid-cols-[1fr_380px]">
                    <div className="space-y-6">
                        <section className="card space-y-4 p-5">
                            <h1 className="text-2xl font-extrabold">{offer.title}</h1>
                            <div className="flex flex-wrap gap-2 text-xs">
                                <Link href={offer.gameCategoryHref} className="rounded-full bg-primary-soft px-2.5 py-1 font-semibold text-primary">
                                    {offer.gameCategory.title}
                                </Link>
                                {offer.product && (
                                    <Link href={offer.product.href} className="rounded-full bg-canvas px-2.5 py-1 font-semibold hover:text-primary">
                                        {offer.product.name}
                                    </Link>
                                )}
                            </div>
                            <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-3">
                                {facts.map((f) => (
                                    <div key={f.label}>
                                        <dt className="text-xs text-muted">{f.label}</dt>
                                        <dd className="font-medium">{f.value}</dd>
                                    </div>
                                ))}
                            </dl>
                        </section>

                        {offer.gameCategory.notice && <NoticeBox text={offer.gameCategory.notice} />}

                        <section className="card p-5">
                            <h2 className="mb-3 text-lg font-bold">{t('offer.seller_description')}</h2>
                            <p className="whitespace-pre-line text-ink/85">{offer.description || '—'}</p>
                        </section>
                    </div>

                    <aside className="card h-fit p-5 lg:sticky lg:top-20" aria-label={t('catalog.product.buy_box')}>
                        <BuyBox offer={offer} />
                    </aside>
                </div>

                <HistoryStrip items={history} />
            </div>
        </PublicLayout>
    );
}
