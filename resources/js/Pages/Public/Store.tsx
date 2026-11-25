import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { CouponCard, type CouponCardData } from '@/Components/Coupons/CouponCard';
import { ReviewCard, type ReviewRow } from '@/Components/Reviews/ReviewParts';
import { Breadcrumbs } from '@/Components/Catalog/Breadcrumbs';
import { SeoHead } from '@/Components/Catalog/SeoHead';
import { OfferCard } from '@/Components/Offer/OfferCard';
import { SellerBadgeChips } from '@/Components/Offer/SellerLine';
import { bpsToPercent, LevelBadge } from '@/Components/Seller/LevelBadge';
import { PublicLayout } from '@/Layouts/PublicLayout';
import { useShared, useT } from '@/lib/i18n';
import type { Crumb, Seo } from '@/types/catalog';
import type { OfferCardData } from '@/types/offer';
import type { SellerLevel, SellerService } from '@/types/seller';

interface Props {
    store: {
        name: string;
        slug: string;
        logoUrl: string | null;
        bannerUrl: string | null;
        bio: string | null;
        notice: string | null;
        country: string | null;
        level: SellerLevel | null;
        trust?: { badges: string[]; years: number; sales: number };
        memberSince: string | null;
        online: boolean;
        onVacation: boolean;
        vacationUntil: string | null;
        languages: { code: string; name: string; level: string }[];
        stats: { ordersCompleted: number; completionRateBps: number | null; positiveRateBps: number | null; positiveRate90Bps: number | null; ratingCount: number; disputeRateBps: number | null };
    };
    services: SellerService[];
    coupons: CouponCardData[];
    reviews: ReviewRow[];
    featured: OfferCardData[];
    offers: OfferCardData[];
    isOwner: boolean;
    breadcrumbs: Crumb[];
    seo: Seo;
}

/** Public store page (spec 1.8 My Store). Offers, reviews and coupons join with M5 / M14 / M16. */
export default function Store({ store, services, coupons, reviews, featured, offers, isOwner, breadcrumbs, seo }: Props) {
    const t = useT();
    const { locale, auth } = useShared();
    const rate = (bps: number | null) => (bps === null ? '—' : bpsToPercent(bps));

    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <section className="relative bg-surface-dark text-white">
                {store.bannerUrl && <img src={store.bannerUrl} alt="" className="absolute inset-0 h-full w-full object-cover opacity-40" />}
                <div className="relative mx-auto max-w-7xl space-y-4 px-4 py-10">
                    <div className="[&_a]:text-white/70 [&_span]:text-white/90">
                        <Breadcrumbs items={breadcrumbs} />
                    </div>
                    <div className="flex flex-wrap items-center gap-4">
                        {store.logoUrl ? (
                            <img src={store.logoUrl} alt="" className="h-20 w-20 rounded-full border-2 border-white/40 object-cover" />
                        ) : (
                            <span aria-hidden="true" className="flex h-20 w-20 items-center justify-center rounded-full bg-gradient-to-br from-primary to-cat-topup font-display text-3xl font-extrabold uppercase">
                                {store.name.charAt(0)}
                            </span>
                        )}
                        <div className="space-y-1">
                            <h1 className="text-3xl font-extrabold">{store.name}</h1>
                            <div className="flex flex-wrap items-center gap-2 text-sm text-white/75">
                                {store.level && <LevelBadge level={store.level} light />}
                                {store.trust && <SellerBadgeChips badges={store.trust.badges} years={store.trust.years} />}
                                {store.memberSince && <span>{t('seller.public.member_since', { date: new Date(store.memberSince).toLocaleDateString(locale) })}</span>}
                                {store.country && <span>· {store.country}</span>}
                                <span className="inline-flex items-center gap-1">
                                    <span aria-hidden className={`inline-block h-2 w-2 rounded-full ${store.online ? 'bg-success' : 'bg-white/40'}`} />
                                    {store.online ? t('chat.online') : t('chat.offline')}
                                </span>
                            </div>
                        </div>
                        {!isOwner && (
                            <div className="ms-auto">
                                {auth.user ? (
                                    <button type="button" className="btn-outline-pill border-white text-white" onClick={() => router.post(`/chat/store/${store.slug}`)}>
                                        {t('chat.contact_seller')}
                                    </button>
                                ) : (
                                    <Link href={`/${locale}/login`} className="btn-outline-pill border-white text-white">
                                        {t('chat.contact_seller')}
                                    </Link>
                                )}
                            </div>
                        )}
                        {isOwner && (
                            <div className="ms-auto flex gap-2">
                                <Link href="/seller/info" className="btn-outline-pill border-white text-white">
                                    {t('seller.public.edit')}
                                </Link>
                                <Link href="/seller/store" className="btn-outline-pill border-white text-white">
                                    {t('menu.seller.store.mine')}
                                </Link>
                            </div>
                        )}
                    </div>
                </div>
            </section>

            <div className="mx-auto grid max-w-7xl gap-6 px-4 py-8 lg:grid-cols-[1fr_320px]">
                <div className="space-y-6">
                    {store.onVacation && (
                        <p className="rounded-md border border-warning/40 bg-warning/10 p-4 text-sm">
                            {store.vacationUntil ? t('seller.public.vacation_until', { date: new Date(store.vacationUntil).toLocaleDateString(locale) }) : t('seller.public.vacation')}
                        </p>
                    )}
                    {store.notice && (
                        <section className="card p-5">
                            <h2 className="mb-2 font-bold">{t('seller.store.notice')}</h2>
                            <p className="whitespace-pre-line text-sm">{store.notice}</p>
                        </section>
                    )}
                    {(coupons.length > 0 || !isOwner) && (
                        <section className="card space-y-3 p-5">
                            <h2 className="font-bold">{t('coupon.store.title')}</h2>
                            {coupons.length > 0 && (
                                <ul className="grid gap-3 sm:grid-cols-2">
                                    {coupons.map((c) => (
                                        <li key={c.id}>
                                            <CouponCard coupon={c} canClaim={!isOwner} />
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {!isOwner && <StoreCodeForm store={store.slug} />}
                        </section>
                    )}
                    <section className="card space-y-3 p-5">
                        <h2 className="font-bold">
                            {t('review.store.title')} <span className="text-sm font-normal text-muted">({store.stats.ratingCount})</span>
                        </h2>
                        <p className="text-sm text-muted">
                            {t('review.store.rates', { rate90: rate(store.stats.positiveRate90Bps), rate: rate(store.stats.positiveRateBps) })}
                        </p>
                        {reviews.length === 0 ? (
                            <p className="text-sm text-muted">{t('review.store.none')}</p>
                        ) : (
                            reviews.map((r) => <ReviewCard key={r.id} review={r} />)
                        )}
                    </section>
                    <section className="card p-5">
                        <h2 className="mb-2 font-bold">{t('seller.public.about')}</h2>
                        <p className="whitespace-pre-line text-sm text-muted">{store.bio ?? t('seller.public.no_bio')}</p>
                    </section>
                    <section className="card p-5">
                        <h2 className="mb-3 font-bold">{t('seller.public.services')}</h2>
                        {services.length === 0 ? (
                            <p className="text-sm text-muted">{t('seller.public.no_services')}</p>
                        ) : (
                            <ul className="grid gap-2 sm:grid-cols-2">
                                {services.map((s) => (
                                    <li key={s.id}>
                                        <Link href={s.href} className="block rounded-md border border-line px-3 py-2 text-sm font-semibold hover:border-primary hover:text-primary">
                                            {s.title}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                    {featured.length > 0 && (
                        <section className="space-y-3" aria-labelledby="featured-title">
                            <h2 id="featured-title" className="font-bold">
                                {t('seller.public.featured')}
                            </h2>
                            <ul className="grid grid-cols-2 gap-4 md:grid-cols-4">
                                {featured.map((o) => (
                                    <li key={o.id}>
                                        <OfferCard offer={o} />
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                    <section className="space-y-3" aria-labelledby="offers-title">
                        <h2 id="offers-title" className="font-bold">
                            {t('seller.public.offers')}
                        </h2>
                        {offers.length === 0 && featured.length === 0 ? (
                            <p className="card p-5 text-sm text-muted">{t('seller.public.no_offers')}</p>
                        ) : (
                            <ul className="grid grid-cols-2 gap-4 md:grid-cols-3">
                                {offers.map((o) => (
                                    <li key={o.id}>
                                        <OfferCard offer={o} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>

                <aside className="space-y-6">
                    <section className="card p-5">
                        <h2 className="mb-3 font-bold">{t('seller.public.stats')}</h2>
                        <dl className="space-y-2 text-sm">
                            <Row label={t('seller.public.orders_completed')} value={String(store.stats.ordersCompleted)} />
                            <Row label={t('seller.public.completion')} value={rate(store.stats.completionRateBps)} />
                            <Row label={t('seller.public.dispute_rate')} value={rate(store.stats.disputeRateBps)} />
                            <Row label={t('seller.public.positive')} value={rate(store.stats.positiveRateBps)} />
                            <Row label={t('seller.public.reviews')} value={String(store.stats.ratingCount)} />
                        </dl>
                    </section>
                    {store.languages.length > 0 && (
                        <section className="card p-5">
                            <h2 className="mb-3 font-bold">{t('seller.store.languages')}</h2>
                            <ul className="space-y-1 text-sm">
                                {store.languages.map((l) => (
                                    <li key={l.code} className="flex justify-between">
                                        <span>{l.name}</span>
                                        <span className="text-muted">{t(`seller.language_level.${l.level}`)}</span>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                </aside>
            </div>
        </PublicLayout>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex justify-between gap-2">
            <dt className="text-muted">{label}</dt>
            <dd className="font-semibold">{value}</dd>
        </div>
    );
}

/** Enter a store code (public or private "Recommend" code from a marketing e-mail). */
function StoreCodeForm({ store }: { store: string }) {
    const t = useT();
    const { auth, locale, errors } = useShared();
    const [code, setCode] = useState('');

    if (!auth.user) {
        return (
            <Link href={'/' + locale + '/login'} className="text-sm text-primary">
                {t('coupon.store.login')}
            </Link>
        );
    }

    return (
        <form
            className="flex flex-wrap items-start gap-2"
            onSubmit={(e) => {
                e.preventDefault();
                router.post('/coupons/code', { store, code }, { preserveScroll: true, onSuccess: () => setCode('') });
            }}
        >
            <input className="field max-w-xs uppercase" value={code} onChange={(e) => setCode(e.target.value)} placeholder={t('coupon.store.code')} aria-label={t('coupon.store.code')} maxLength={32} />
            <button type="submit" className="btn-outline-pill" disabled={code.trim() === ''}>
                {t('coupon.claim')}
            </button>
            {errors.coupon && <p className="w-full text-sm text-danger">{errors.coupon}</p>}
        </form>
    );
}
