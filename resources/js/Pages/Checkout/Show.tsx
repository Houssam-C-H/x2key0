import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { SellerLine } from '@/Components/Offer/SellerLine';
import { PublicLayout } from '@/Layouts/PublicLayout';
import { useShared, useT } from '@/lib/i18n';
import { formatUsd } from '@/lib/money';
import { deliveryTime } from '@/lib/offer';
import type { OfferRow } from '@/types/offer';

interface Quote {
    currency: string;
    quantity: number;
    unitPrice: number;
    subtotal: number;
    discountPlatform: number;
    discountSeller: number;
    buyerFee: number;
    addonFee: number;
    methodFee: number;
    tax: number;
    total: number;
    credits: number;
    pspDue: number;
}

interface BuyerInputField {
    key: string;
    type: 'text' | 'number' | 'select';
    label: string;
    required: boolean;
    help: string;
    options: string[];
}

interface Props {
    offer: OfferRow;
    gameCategory: string;
    quantity: number;
    quote: Quote;
    methods: { code: string; name: string; feeBps: number; feeFixed: number }[];
    method: string | null;
    credits: { available: number; use: boolean };
    buyerInputs: BuyerInputField[];
    idempotencyKey: string;
    coupons: { eligible: EligibleCoupon[]; selected: string[] };
}

interface EligibleCoupon {
    id: string;
    issuer: 'platform' | 'seller';
    label: string;
    name: string;
    discount: number;
}

/**
 * Checkout (spec 1.6 / 9): quantity, buyer inputs, payment method, credits, price lines, Pay.
 * Changing quantity, method or credits re-quotes on the server; the idempotency key stays the same.
 */
export default function CheckoutShow({ offer, gameCategory, quantity, quote, methods, method, credits, buyerInputs, idempotencyKey, coupons }: Props) {
    const t = useT();
    const { locale, errors, fx } = useShared();
    const [inputs, setInputs] = useState<Record<string, string>>(Object.fromEntries(buyerInputs.map((f) => [f.key, ''])));
    const [submitting, setSubmitting] = useState(false);
    const money = (cents: number) => formatUsd(cents, locale) ?? '';
    const step = offer.integerMultiple ? offer.minQty : 1;
    const max = Math.max(offer.minQty, Math.min(offer.maxQty ?? offer.stock, offer.stock));

    const requote = (patch: { qty?: number; method?: string | null; credits?: boolean; coupons?: string[] }) => {
        const picked = patch.coupons ?? coupons.selected;
        router.get(
            '/checkout',
            {
                offer: offer.id,
                qty: patch.qty ?? quantity,
                method: (patch.method === undefined ? method : patch.method) ?? undefined,
                credits: (patch.credits ?? credits.use) ? 1 : undefined,
                coupons: picked.length === 0 ? 'none' : picked.join(','),
            },
            { preserveState: true, preserveScroll: true, replace: true, only: ['quote', 'quantity', 'methods', 'method', 'credits', 'coupons'] },
        );
    };
    const pickCoupon = (issuer: 'platform' | 'seller', id: string) => {
        const others = coupons.selected.filter((s) => coupons.eligible.find((c) => c.id === s)?.issuer !== issuer);
        requote({ coupons: id === '' ? others : [...others, id] });
    };

    const pay = (e: React.FormEvent) => {
        e.preventDefault();
        setSubmitting(true);
        router.post(
            '/checkout',
            { offer: offer.id, quantity, buyer_inputs: inputs, method, use_credits: credits.use, coupons: coupons.selected, idempotency_key: idempotencyKey },
            { preserveScroll: true, onFinish: () => setSubmitting(false) },
        );
    };

    const lines: [string, number, boolean?][] = [
        [t('checkout.line.amount'), quote.subtotal],
        ...(quote.discountSeller > 0 ? [[t('checkout.line.discount_seller'), -quote.discountSeller] as [string, number]] : []),
        ...(quote.discountPlatform > 0 ? [[t('checkout.line.discount_platform'), -quote.discountPlatform] as [string, number]] : []),
        [t('checkout.line.service_charge'), quote.buyerFee + quote.methodFee],
        ...(quote.addonFee > 0 ? [[t('checkout.line.addon_fee'), quote.addonFee] as [string, number]] : []),
        ...(quote.tax > 0 ? [[t('checkout.line.tax'), quote.tax] as [string, number]] : []),
        [t('checkout.line.total'), quote.total, true],
        ...(quote.credits > 0 ? [[t('checkout.line.credits'), -quote.credits] as [string, number], [t('checkout.line.to_pay'), quote.pspDue, true] as [string, number, boolean]] : []),
    ];

    return (
        <PublicLayout>
            <Head title={t('checkout.title')} />
            <form onSubmit={pay} className="mx-auto grid max-w-6xl gap-6 px-4 py-8 lg:grid-cols-[1fr_380px]">
                <div className="space-y-6">
                    <h1 className="text-2xl font-extrabold">{t('checkout.title')}</h1>
                    {errors.checkout && <p className="rounded-md bg-danger/5 p-3 text-sm text-danger">{errors.checkout}</p>}

                    <section className="card space-y-3 p-5">
                        <h2 className="font-bold">{offer.title}</h2>
                        <p className="text-sm text-muted">{gameCategory}</p>
                        <div className="text-sm">
                            <SellerLine seller={offer.seller} />
                        </div>
                        <dl className="grid grid-cols-2 gap-2 text-sm sm:grid-cols-3">
                            <div>
                                <dt className="text-xs text-muted">{t('offer.delivery_method')}</dt>
                                <dd className="font-medium">{t(`offer.delivery.${offer.deliveryMethod}`)}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-muted">{t('offer.delivery_time')}</dt>
                                <dd className="font-medium">{offer.auto ? t('offer.instant') : deliveryTime(offer.deliveryMinutes, t)}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-muted">{t('offer.insurance')}</dt>
                                <dd className="font-medium">{offer.insuranceDays > 0 ? t('offer.insurance_days', { count: offer.insuranceDays }) : '—'}</dd>
                            </div>
                        </dl>
                        <div className="flex flex-wrap items-center gap-3 pt-2">
                            <span className="text-sm font-medium">{t('offer.quantity')}</span>
                            <div className="flex items-center rounded-md border border-line">
                                <button type="button" className="px-3 py-1.5" disabled={quantity - step < offer.minQty} onClick={() => requote({ qty: quantity - step })} aria-label="−">
                                    −
                                </button>
                                <span className="min-w-12 border-x border-line px-3 py-1.5 text-center" aria-live="polite">
                                    {quantity}
                                </span>
                                <button type="button" className="px-3 py-1.5" disabled={quantity + step > max} onClick={() => requote({ qty: quantity + step })} aria-label="+">
                                    +
                                </button>
                            </div>
                            <span className="text-sm text-muted">
                                {offer.unitLabel ?? '1'} = {money(quote.unitPrice)}
                            </span>
                        </div>
                        {errors.quantity && <p className="text-sm text-danger">{errors.quantity}</p>}
                    </section>

                    {buyerInputs.length > 0 && (
                        <section className="card space-y-4 p-5">
                            <div>
                                <h2 className="font-bold">{t('checkout.buyer_inputs')}</h2>
                                <p className="text-sm text-muted">{t('checkout.buyer_inputs_help')}</p>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                {buyerInputs.map((f) => {
                                    const error = errors[`buyer_inputs.${f.key}`];

                                    return (
                                        <label key={f.key} className="flex flex-col gap-1">
                                            <span className="font-medium">
                                                {f.label}
                                                {f.required ? ' *' : ''}
                                            </span>
                                            {f.type === 'select' ? (
                                                <select className={`field ${error ? 'border-danger' : ''}`} value={inputs[f.key] ?? ''} onChange={(e) => setInputs({ ...inputs, [f.key]: e.target.value })}>
                                                    <option value="">—</option>
                                                    {f.options.map((o) => (
                                                        <option key={o} value={o}>
                                                            {o}
                                                        </option>
                                                    ))}
                                                </select>
                                            ) : (
                                                <input
                                                    className={`field ${error ? 'border-danger' : ''}`}
                                                    inputMode={f.type === 'number' ? 'numeric' : undefined}
                                                    autoComplete="off"
                                                    value={inputs[f.key] ?? ''}
                                                    onChange={(e) => setInputs({ ...inputs, [f.key]: e.target.value })}
                                                />
                                            )}
                                            {error ? <span className="text-sm text-danger">{error}</span> : f.help && <span className="text-xs text-muted">{f.help}</span>}
                                        </label>
                                    );
                                })}
                            </div>
                        </section>
                    )}

                    {coupons.eligible.length > 0 && (
                        <section className="card space-y-3 p-5">
                            <h2 className="font-bold">{t('coupon.checkout.title')}</h2>
                            {(['seller', 'platform'] as const).map((issuer) => {
                                const options = coupons.eligible.filter((c) => c.issuer === issuer);
                                if (options.length === 0) {
                                    return null;
                                }
                                const current = coupons.selected.find((s) => options.some((o) => o.id === s)) ?? '';
                                return (
                                    <label key={issuer} className="grid gap-1 text-sm">
                                        <span className="font-medium">{t('coupon.checkout.' + issuer)}</span>
                                        <select className="field" value={current} onChange={(e) => pickCoupon(issuer, e.target.value)}>
                                            <option value="">{t('coupon.checkout.none')}</option>
                                            {options.map((o) => (
                                                <option key={o.id} value={o.id}>
                                                    {o.label} · {o.name} (−{money(o.discount)})
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                );
                            })}
                            {errors.coupons && <p className="text-sm text-danger">{errors.coupons}</p>}
                        </section>
                    )}

                    <section className="card space-y-3 p-5">
                        <h2 className="font-bold">{t('checkout.payment_method')}</h2>
                        {credits.available > 0 && (
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={credits.use} onChange={(e) => requote({ credits: e.target.checked })} />
                                {t('checkout.use_credits', { amount: money(credits.available) })}
                            </label>
                        )}
                        {quote.pspDue === 0 ? (
                            <p className="text-sm text-success">{t('checkout.covered_by_credits')}</p>
                        ) : methods.length === 0 ? (
                            <p className="text-sm text-danger">{t('checkout.no_methods')}</p>
                        ) : (
                            <ul className="space-y-2">
                                {methods.map((m) => (
                                    <li key={m.code}>
                                        <label className={`flex items-center gap-3 rounded-md border px-3 py-2 ${method === m.code ? 'border-primary bg-primary-soft' : 'border-line'}`}>
                                            <input type="radio" name="method" checked={method === m.code} onChange={() => requote({ method: m.code })} />
                                            <span className="flex-1 font-medium">{m.name}</span>
                                            {(m.feeBps > 0 || m.feeFixed > 0) && <span className="text-xs text-muted">{t('checkout.method_fee', { rate: (m.feeBps / 100).toFixed(2), fixed: money(m.feeFixed) })}</span>}
                                        </label>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {errors.method && <p className="text-sm text-danger">{errors.method}</p>}
                    </section>
                </div>

                <aside className="card h-fit space-y-4 p-5 lg:sticky lg:top-20">
                    <h2 className="font-bold">{t('checkout.summary')}</h2>
                    <dl className="space-y-2 text-sm">
                        {lines.map(([label, amount, strong]) => (
                            <div key={label} className={`flex justify-between ${strong ? 'border-t border-line pt-2 text-base font-bold' : ''}`}>
                                <dt>{label}</dt>
                                <dd>{amount < 0 ? `− ${money(-amount)}` : money(amount)}</dd>
                            </div>
                        ))}
                    </dl>
                    <button type="submit" className="btn-primary h-12 w-full" disabled={submitting || (quote.pspDue > 0 && method === null)}>
                        {t('checkout.pay', { amount: money(quote.pspDue > 0 ? quote.pspDue : quote.total) })}
                    </button>
                    {fx && (
                        <p className="text-xs text-muted">
                            {t('fx.checkout', { amount: new Intl.NumberFormat(locale, { style: 'currency', currency: fx.currency }).format(((quote.pspDue > 0 ? quote.pspDue : quote.total) / 100) * fx.rate) })}{' '}
                            <a href="https://www.exchangerate-api.com" target="_blank" rel="noopener noreferrer" className="underline">
                                {t('fx.source')}
                            </a>
                        </p>
                    )}
                    <p className="text-xs text-muted">{t('checkout.terms')}</p>
                </aside>
            </form>
        </PublicLayout>
    );
}
