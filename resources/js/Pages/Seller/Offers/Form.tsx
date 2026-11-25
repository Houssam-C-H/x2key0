import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { NoticeBox } from '@/Components/Catalog/NoticeBox';
import { Field } from '@/Components/Field';
import { bpsToPercent } from '@/Components/Seller/LevelBadge';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';
import { formatUsd } from '@/lib/money';
import { deliveryTime, toCents } from '@/lib/offer';
import type { SellerService } from '@/types/seller';

interface AttributeField {
    key: string;
    type: 'select' | 'multiselect' | 'number' | 'boolean' | 'text';
    label: string;
    required: boolean;
    unit: string | null;
    options: { value: string; label: string }[];
}

interface Category {
    id: number;
    title: string;
    typeCode: string;
    notice: string | null;
    attributes: AttributeField[];
    methods: { value: string; auto: boolean; storageTypes: string[] }[];
    compulsoryInsuranceDays: number;
    products: { id: number; name: string }[];
    services: { id: number; name: string; description: string | null }[];
}

type AttrValue = string | number | boolean | string[];

interface FormData {
    game_category_id: number | '';
    product_id: number | '';
    service_type_id: number | '';
    title: string;
    description: Record<string, string>;
    delivery_method: string;
    fulfillment: 'auto' | 'manual';
    storage_group_id: number | '';
    attribute_values: Record<string, AttrValue>;
    unit_price: string;
    min_qty: number;
    max_qty: number | '';
    unit_label: string;
    integer_multiple: boolean;
    quantity_tiers: { min_qty: number | ''; unit_price: string }[];
    delivery_time_minutes: number;
    manual_stock_qty: number | '';
    insurance_days: number;
    acquisition_cost: string;
    listing_days: number;
    publish_mode: 'immediate' | 'pending';
    terms: boolean;
}

interface Props {
    offer: (Omit<FormData, 'terms' | 'description'> & { id: number; publicId: string; status: string; statusReason: string | null; description: Record<string, string | null> }) | null;
    /** Approved game×categories, with their category type and game name (category → game dropdowns). */
    services: (SellerService & { typeCode: string; game: string })[];
    /** Games of each category the seller is not approved for yet (apply from this form). */
    applicable: { id: number; typeCode: string; game: string; pending: boolean }[];
    category: Category | null;
    storageGroups: { id: number; name: string; type: string; available: number }[];
    options: { deliveryTimes: number[]; listingDays: number[]; insuranceDays: number[]; maxTiers: number };
    commissionBps: number;
    autoApprove: boolean;
    readOnly: boolean;
}

/** Create New Offers / Edit offer (spec 1.8): one long form in the order of the reference screen. */
export default function OfferForm({ offer, services, applicable, category, storageGroups, options, commissionBps, autoApprove, readOnly }: Props) {
    const t = useT();
    const { locale, locales, categoryTypes } = useShared();
    const editing = offer !== null;
    const [typeCode, setTypeCode] = useState(category?.typeCode ?? '');
    const gamesInType = services.filter((s) => s.typeCode === typeCode).sort((a, b) => a.game.localeCompare(b.game));
    const approvedIn = (code: string) => services.filter((s) => s.typeCode === code).length;
    const applyOptions = applicable.filter((a) => a.typeCode === typeCode);
    const [applyOpen, setApplyOpen] = useState(false);
    const [applyId, setApplyId] = useState('');
    const [applying, setApplying] = useState(false);

    /** Apply for a game×category without leaving the form (spec 6.9); an instant pass selects it right away. */
    const apply = () => {
        if (applyId === '') return;
        const id = Number(applyId);
        setApplying(true);
        router.post(
            '/seller/services',
            { game_category_id: id },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    setApplyId('');
                    setApplyOpen(false);
                    const approved = (page.props.services as { id: number }[] | undefined) ?? [];
                    if (approved.some((s) => s.id === id)) chooseService(String(id));
                },
                onFinish: () => setApplying(false),
            },
        );
    };

    const form = useForm<FormData>({
        game_category_id: offer?.game_category_id ?? category?.id ?? '',
        product_id: offer?.product_id ?? '',
        service_type_id: offer?.service_type_id ?? (category?.services.length === 1 ? (category.services[0]?.id ?? '') : ''),
        title: offer?.title ?? '',
        description: Object.fromEntries(locales.map((l) => [l.code, offer?.description[l.code] ?? ''])),
        delivery_method: offer?.delivery_method ?? category?.methods[0]?.value ?? '',
        fulfillment: offer?.fulfillment ?? 'manual',
        storage_group_id: offer?.storage_group_id ?? '',
        attribute_values: offer?.attribute_values ?? {},
        unit_price: offer?.unit_price ?? '',
        min_qty: offer?.min_qty ?? 1,
        max_qty: offer?.max_qty ?? '',
        unit_label: offer?.unit_label ?? '',
        integer_multiple: offer?.integer_multiple ?? false,
        quantity_tiers: offer?.quantity_tiers ?? [],
        delivery_time_minutes: offer?.delivery_time_minutes ?? 60,
        manual_stock_qty: offer?.manual_stock_qty ?? '',
        insurance_days: offer?.insurance_days ?? category?.compulsoryInsuranceDays ?? 0,
        acquisition_cost: offer?.acquisition_cost ?? '',
        listing_days: offer?.listing_days ?? options.listingDays[0] ?? 7,
        publish_mode: offer?.publish_mode ?? 'immediate',
        terms: false,
    });
    const { data, setData, errors } = form;
    const err = (key: string) => (errors as Record<string, string | undefined>)[key];

    const method = category?.methods.find((m) => m.value === data.delivery_method) ?? null;
    const groups = storageGroups.filter((g) => method?.storageTypes.includes(g.type));
    const priceCents = toCents(data.unit_price);
    const fee = priceCents === null ? null : Math.round((priceCents * commissionBps) / 10000);
    const acquisition = toCents(data.acquisition_cost);

    const serviceHint = category?.services.find((s) => s.id === data.service_type_id)?.description ?? null;

    const chooseService = (id: string) => {
        if (editing) return;
        router.get('/seller/offers/create', id === '' ? {} : { category: id }, { preserveScroll: true });
    };

    const setAttr = (key: string, value: AttrValue) => setData('attribute_values', { ...data.attribute_values, [key]: value });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (editing) {
            form.put(`/seller/offers/${offer.id}`, { preserveScroll: true });
        } else {
            form.post('/seller/offers', { preserveScroll: true });
        }
    };

    return (
        <DashboardLayout title={editing ? t('offer.form.edit_title') : t('menu.seller.offers_create')} bare>
            <p className="rounded-md bg-danger/5 p-3 text-sm text-danger">{t('offer.form.legal')}</p>
            {readOnly && <p className="rounded-md bg-danger/5 p-3 text-sm text-danger">{t('seller.error.suspended')}</p>}
            {offer?.statusReason && <p className="rounded-md bg-danger/5 p-3 text-sm text-danger">{t('offer.form.reason', { reason: offer.statusReason })}</p>}
            {err('offer') && <p className="rounded-md bg-danger/5 p-3 text-sm text-danger">{err('offer')}</p>}

            <form className="space-y-6" onSubmit={submit}>
                <Panel title={t('offer.form.selling')} description={t('offer.form.selling_help')}>
                    {services.length === 0 && applicable.length === 0 ? (
                        <p className="text-sm">
                            {t('offer.form.no_services')}{' '}
                            <Link href="/seller/services" className="font-semibold text-primary hover:underline">
                                {t('menu.seller.store.services')}
                            </Link>
                        </p>
                    ) : (
                        <div className="grid gap-4 md:grid-cols-2">
                            <label className="flex flex-col gap-1">
                                <span className="font-medium">{t('offer.form.category')}</span>
                                <select
                                    className="field"
                                    value={typeCode}
                                    disabled={editing}
                                    onChange={(e) => {
                                        setTypeCode(e.target.value);
                                        if (category && category.typeCode !== e.target.value) chooseService('');
                                    }}
                                >
                                    <option value="">{t('offer.form.choose_category')}</option>
                                    {categoryTypes.map((c) => (
                                        <option key={c.code} value={c.code}>
                                            {c.label} ({approvedIn(c.code)})
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="flex flex-col gap-1">
                                <span className="font-medium">{t('offer.form.game')}</span>
                                <select className="field" value={String(data.game_category_id)} disabled={editing || typeCode === '' || gamesInType.length === 0} onChange={(e) => chooseService(e.target.value)}>
                                    <option value="">{typeCode === '' ? t('offer.form.choose_category_first') : t('offer.form.choose_game')}</option>
                                    {gamesInType.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.game}
                                        </option>
                                    ))}
                                </select>
                                {typeCode !== '' && gamesInType.length === 0 && (
                                    <span className="text-xs text-muted">{t('offer.form.no_games_in_category', { category: categoryTypes.find((c) => c.code === typeCode)?.label ?? '' })}</span>
                                )}
                                {err('game_category_id') && <span className="text-sm text-danger">{err('game_category_id')}</span>}
                                {!editing && typeCode !== '' && applyOptions.length > 0 && !applyOpen && (
                                    <button type="button" onClick={() => setApplyOpen(true)} className="self-start text-sm font-semibold text-primary hover:underline">
                                        + {t('offer.form.apply_another')}
                                    </button>
                                )}
                            </label>
                            {!editing && applyOpen && (
                                <div className="rounded-lg border border-line bg-canvas p-4 md:col-span-2">
                                    <p className="font-medium">{t('offer.form.apply_title', { category: categoryTypes.find((c) => c.code === typeCode)?.label ?? '' })}</p>
                                    <p className="mb-3 text-xs text-muted">{autoApprove ? t('offer.form.apply_instant') : t('offer.form.apply_review')}</p>
                                    <div className="flex flex-wrap items-start gap-2">
                                        <select className="field min-w-56 flex-1" value={applyId} onChange={(e) => setApplyId(e.target.value)} aria-label={t('offer.form.game')}>
                                            <option value="">{t('offer.form.choose_game')}</option>
                                            {applyOptions.map((a) => (
                                                <option key={a.id} value={a.id} disabled={a.pending}>
                                                    {a.game}
                                                    {a.pending ? ' — ' + t('offer.form.apply_pending') : ''}
                                                </option>
                                            ))}
                                        </select>
                                        <button type="button" className="btn-primary" disabled={applyId === '' || applying} onClick={apply}>
                                            {t('offer.form.apply_submit')}
                                        </button>
                                        <button type="button" className="btn-ghost" onClick={() => setApplyOpen(false)}>
                                            {t('common.cancel')}
                                        </button>
                                    </div>
                                    <p className="mt-2 text-xs text-muted">
                                        <Link href="/seller/services" className="text-primary hover:underline">
                                            {t('offer.form.apply_manage')}
                                        </Link>
                                    </p>
                                </div>
                            )}
                            {category && category.products.length > 0 && (
                                <label className="flex flex-col gap-1">
                                    <span className="font-medium">{t('offer.form.product')}</span>
                                    <select className="field" value={String(data.product_id)} disabled={editing} onChange={(e) => setData('product_id', e.target.value === '' ? '' : Number(e.target.value))}>
                                        <option value="">{t('offer.form.no_product')}</option>
                                        {category.products.map((p) => (
                                            <option key={p.id} value={p.id}>
                                                {p.name}
                                            </option>
                                        ))}
                                    </select>
                                    {err('product_id') && <span className="text-sm text-danger">{err('product_id')}</span>}
                                </label>
                            )}
                            {category && category.services.length > 0 && (
                                <label className="flex flex-col gap-1">
                                    <span className="font-medium">{t('offer.form.service_type')}</span>
                                    <select className="field" value={String(data.service_type_id)} disabled={readOnly} onChange={(e) => setData('service_type_id', e.target.value === '' ? '' : Number(e.target.value))}>
                                        <option value="">{t('offer.form.choose_service_type')}</option>
                                        {category.services.map((s) => (
                                            <option key={s.id} value={s.id}>
                                                {s.name}
                                            </option>
                                        ))}
                                    </select>
                                    {serviceHint && <span className="text-xs text-muted">{serviceHint}</span>}
                                    {err('service_type_id') && <span className="text-sm text-danger">{err('service_type_id')}</span>}
                                </label>
                            )}
                        </div>
                    )}
                    {category?.notice && (
                        <div className="mt-4">
                            <NoticeBox text={category.notice} />
                        </div>
                    )}
                </Panel>

                {category && (
                    <>
                        {data.product_id === '' && category.attributes.length > 0 && (
                            <Panel title={t('offer.form.details')} description={t('offer.form.details_help')}>
                                <div className="grid gap-4 md:grid-cols-2">
                                    {category.attributes.map((a) => (
                                        <AttributeInput key={a.key} field={a} value={data.attribute_values[a.key]} onChange={(v) => setAttr(a.key, v)} />
                                    ))}
                                </div>
                                {err('attribute_values') && <p className="mt-2 text-sm text-danger">{err('attribute_values')}</p>}
                            </Panel>
                        )}

                        <Panel title={t('offer.form.description')} description={t('offer.form.description_help')}>
                            <Field
                                label={t('offer.form.title')}
                                name="title"
                                value={data.title}
                                maxLength={160}
                                onChange={(e) => setData('title', e.target.value)}
                                error={err('title')}
                                hint={data.product_id !== '' ? t('offer.form.title_hint') : undefined}
                            />
                            <div className="mt-4 grid gap-4 md:grid-cols-3">
                                {locales.map((l) => (
                                    <label key={l.code} className="flex flex-col gap-1">
                                        <span className="font-medium">{l.name}</span>
                                        <textarea
                                            className={`field ${err(`description.${l.code}`) ? 'border-danger' : ''}`}
                                            rows={5}
                                            dir={l.dir}
                                            maxLength={5000}
                                            value={data.description[l.code] ?? ''}
                                            onChange={(e) => setData('description', { ...data.description, [l.code]: e.target.value })}
                                        />
                                        {err(`description.${l.code}`) && <span className="text-sm text-danger">{err(`description.${l.code}`)}</span>}
                                    </label>
                                ))}
                            </div>
                        </Panel>

                        <Panel title={t('offer.form.price')} description={t('offer.form.price_help')}>
                            <div className="grid gap-4 md:grid-cols-4">
                                <Field label={t('offer.form.unit_price')} name="unit_price" inputMode="decimal" value={data.unit_price} onChange={(e) => setData('unit_price', e.target.value)} error={err('unit_price')} hint="USD" />
                                <Field label={t('offer.form.min_qty')} name="min_qty" type="number" min={1} value={data.min_qty} onChange={(e) => setData('min_qty', Number(e.target.value))} error={err('min_qty')} />
                                <Field label={t('offer.form.max_qty')} name="max_qty" type="number" min={1} value={data.max_qty} onChange={(e) => setData('max_qty', e.target.value === '' ? '' : Number(e.target.value))} error={err('max_qty')} />
                                <Field label={t('offer.form.unit_label')} name="unit_label" value={data.unit_label} maxLength={40} placeholder="1000 Coins" onChange={(e) => setData('unit_label', e.target.value)} error={err('unit_label')} />
                            </div>
                            <label className="mt-3 flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={data.integer_multiple} onChange={(e) => setData('integer_multiple', e.target.checked)} />
                                {t('offer.form.integer_multiple')}
                            </label>

                            <div className="mt-4 space-y-2">
                                <p className="font-medium">{t('offer.form.tiers')}</p>
                                {data.quantity_tiers.map((tier, i) => (
                                    <div key={i} className="flex flex-wrap items-end gap-2">
                                        <Field
                                            label={t('offer.form.tier_qty')}
                                            name={`tier-qty-${i}`}
                                            type="number"
                                            min={2}
                                            value={tier.min_qty}
                                            onChange={(e) => setData('quantity_tiers', data.quantity_tiers.map((x, j) => (j === i ? { ...x, min_qty: e.target.value === '' ? '' : Number(e.target.value) } : x)))}
                                        />
                                        <Field
                                            label={t('offer.form.tier_price')}
                                            name={`tier-price-${i}`}
                                            inputMode="decimal"
                                            value={tier.unit_price}
                                            onChange={(e) => setData('quantity_tiers', data.quantity_tiers.map((x, j) => (j === i ? { ...x, unit_price: e.target.value } : x)))}
                                        />
                                        <button type="button" className="btn-ghost mb-1" onClick={() => setData('quantity_tiers', data.quantity_tiers.filter((_, j) => j !== i))}>
                                            {t('common.remove')}
                                        </button>
                                    </div>
                                ))}
                                {data.quantity_tiers.length < options.maxTiers && (
                                    <button type="button" className="btn-outline-pill px-3 py-1 text-sm" onClick={() => setData('quantity_tiers', [...data.quantity_tiers, { min_qty: '', unit_price: '' }])}>
                                        + {t('offer.form.add_tier')}
                                    </button>
                                )}
                                {err('quantity_tiers') && <p className="text-sm text-danger">{err('quantity_tiers')}</p>}
                            </div>

                            <div className="mt-4 grid gap-4 md:grid-cols-2">
                                <Field
                                    label={t('offer.form.acquisition_cost')}
                                    name="acquisition_cost"
                                    inputMode="decimal"
                                    value={data.acquisition_cost}
                                    onChange={(e) => setData('acquisition_cost', e.target.value)}
                                    error={err('acquisition_cost')}
                                    hint={t('offer.form.acquisition_hint')}
                                />
                                <dl className="grid grid-cols-2 gap-1 rounded-md bg-canvas p-3 text-sm">
                                    <dt className="text-muted">{t('offer.form.commission', { rate: bpsToPercent(commissionBps) })}</dt>
                                    <dd className="text-end">{fee === null ? '—' : `− ${formatUsd(fee, locale)}`}</dd>
                                    <dt className="font-semibold">{t('offer.form.receivable')}</dt>
                                    <dd className="text-end font-semibold">{priceCents === null || fee === null ? '—' : formatUsd(priceCents - fee, locale)}</dd>
                                    {acquisition !== null && priceCents !== null && fee !== null && (
                                        <>
                                            <dt className="text-muted">{t('offer.form.profit')}</dt>
                                            <dd className="text-end">{formatUsd(priceCents - fee - acquisition, locale)}</dd>
                                        </>
                                    )}
                                </dl>
                            </div>
                        </Panel>

                        <Panel title={t('offer.form.delivery')} description={t('offer.form.delivery_help')}>
                            <div className="grid gap-4 md:grid-cols-3">
                                <label className="flex flex-col gap-1">
                                    <span className="font-medium">{t('offer.delivery_method')}</span>
                                    <select
                                        className="field"
                                        value={data.delivery_method}
                                        disabled={editing}
                                        onChange={(e) => {
                                            const next = category.methods.find((m) => m.value === e.target.value);
                                            form.setData({ ...data, delivery_method: e.target.value, fulfillment: next?.auto ? data.fulfillment : 'manual', storage_group_id: '' });
                                        }}
                                    >
                                        {category.methods.map((m) => (
                                            <option key={m.value} value={m.value}>
                                                {t(`offer.delivery.${m.value}`)}
                                            </option>
                                        ))}
                                    </select>
                                    {err('delivery_method') && <span className="text-sm text-danger">{err('delivery_method')}</span>}
                                </label>
                                <label className="flex flex-col gap-1">
                                    <span className="font-medium">{t('offer.delivery_time')}</span>
                                    <select className="field" value={data.delivery_time_minutes} onChange={(e) => setData('delivery_time_minutes', Number(e.target.value))}>
                                        {options.deliveryTimes.map((m) => (
                                            <option key={m} value={m}>
                                                {deliveryTime(m, t)}
                                            </option>
                                        ))}
                                    </select>
                                    <span className="text-xs text-muted">{t('offer.form.eta_warning')}</span>
                                </label>
                                <fieldset className="flex flex-col gap-1">
                                    <legend className="font-medium">{t('offer.form.fulfillment')}</legend>
                                    <label className="flex items-center gap-2 text-sm">
                                        <input type="radio" checked={data.fulfillment === 'manual'} onChange={() => form.setData({ ...data, fulfillment: 'manual', storage_group_id: '' })} />
                                        {t('offer.form.manual')}
                                    </label>
                                    <label className={`flex items-center gap-2 text-sm ${method?.auto ? '' : 'opacity-50'}`}>
                                        <input type="radio" disabled={!method?.auto} checked={data.fulfillment === 'auto'} onChange={() => setData('fulfillment', 'auto')} />
                                        {t('offer.form.auto')}
                                    </label>
                                </fieldset>
                            </div>
                            <div className="mt-4 grid gap-4 md:grid-cols-3">
                                {data.fulfillment === 'auto' ? (
                                    <label className="flex flex-col gap-1">
                                        <span className="font-medium">{t('offer.form.storage_group')}</span>
                                        <select className="field" value={String(data.storage_group_id)} onChange={(e) => setData('storage_group_id', e.target.value === '' ? '' : Number(e.target.value))}>
                                            <option value="">{t('offer.form.choose_group')}</option>
                                            {groups.map((g) => (
                                                <option key={g.id} value={g.id}>
                                                    {g.name} ({t('storage.available_count', { count: g.available })})
                                                </option>
                                            ))}
                                        </select>
                                        {groups.length === 0 && (
                                            <Link href="/seller/storage" className="text-xs text-primary hover:underline">
                                                {t('offer.form.create_group')}
                                            </Link>
                                        )}
                                        {err('storage_group_id') && <span className="text-sm text-danger">{err('storage_group_id')}</span>}
                                    </label>
                                ) : (
                                    <Field
                                        label={t('offer.form.stock')}
                                        name="manual_stock_qty"
                                        type="number"
                                        min={0}
                                        value={data.manual_stock_qty}
                                        onChange={(e) => setData('manual_stock_qty', e.target.value === '' ? '' : Number(e.target.value))}
                                        error={err('manual_stock_qty')}
                                    />
                                )}
                                {err('fulfillment') && <p className="text-sm text-danger">{err('fulfillment')}</p>}
                            </div>
                        </Panel>

                        <Panel title={t('offer.form.listing')} description={t(category.compulsoryInsuranceDays > 0 ? 'offer.form.insurance_compulsory' : 'offer.form.insurance_help', { days: category.compulsoryInsuranceDays })}>
                            <div className="grid gap-4 md:grid-cols-3">
                                <label className="flex flex-col gap-1">
                                    <span className="font-medium">{t('offer.insurance')}</span>
                                    <select className="field" value={data.insurance_days} onChange={(e) => setData('insurance_days', Number(e.target.value))}>
                                        {options.insuranceDays
                                            .filter((d) => d >= category.compulsoryInsuranceDays)
                                            .map((d) => (
                                                <option key={d} value={d}>
                                                    {d === 0 ? t('offer.form.no_insurance') : t('offer.insurance_days', { count: d })}
                                                </option>
                                            ))}
                                    </select>
                                    {err('insurance_days') && <span className="text-sm text-danger">{err('insurance_days')}</span>}
                                </label>
                                <label className="flex flex-col gap-1">
                                    <span className="font-medium">{t('offer.form.listing_days')}</span>
                                    <select className="field" value={data.listing_days} onChange={(e) => setData('listing_days', Number(e.target.value))}>
                                        {options.listingDays.map((d) => (
                                            <option key={d} value={d}>
                                                {t('offer.days', { count: d })}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                {!editing && (
                                    <fieldset className="flex flex-col gap-1">
                                        <legend className="font-medium">{t('offer.form.publish')}</legend>
                                        <label className="flex items-center gap-2 text-sm">
                                            <input type="radio" checked={data.publish_mode === 'immediate'} onChange={() => setData('publish_mode', 'immediate')} />
                                            {t('offer.form.publish_now')}
                                        </label>
                                        <label className="flex items-center gap-2 text-sm">
                                            <input type="radio" checked={data.publish_mode === 'pending'} onChange={() => setData('publish_mode', 'pending')} />
                                            {t('offer.form.publish_later')}
                                        </label>
                                    </fieldset>
                                )}
                            </div>
                            <p className="mt-3 text-xs text-muted">{t(autoApprove ? 'offer.form.auto_approve' : 'offer.form.review_note')}</p>
                        </Panel>

                        <div className="card flex flex-wrap items-center gap-4 p-5">
                            {!editing && (
                                <label className="flex items-center gap-2 text-sm">
                                    <input type="checkbox" checked={data.terms} onChange={(e) => setData('terms', e.target.checked)} />
                                    {t('offer.form.terms')}
                                </label>
                            )}
                            {err('terms') && <span className="text-sm text-danger">{err('terms')}</span>}
                            <div className="ms-auto flex gap-2">
                                <Link href="/seller/offers" className="btn-ghost">
                                    {t('common.cancel')}
                                </Link>
                                <button type="submit" className="btn-primary" disabled={form.processing || readOnly || (!editing && !data.terms)}>
                                    {t(editing ? 'common.save' : 'offer.form.submit')}
                                </button>
                            </div>
                        </div>
                    </>
                )}
            </form>
        </DashboardLayout>
    );
}

function AttributeInput({ field, value, onChange }: { field: AttributeField; value: AttrValue | undefined; onChange: (v: AttrValue) => void }) {
    const t = useT();
    const label = `${field.label}${field.required ? ' *' : ''}${field.unit ? ` (${field.unit})` : ''}`;

    if (field.type === 'select') {
        return (
            <label className="flex flex-col gap-1">
                <span className="font-medium">{label}</span>
                <select className="field" value={typeof value === 'string' ? value : ''} onChange={(e) => onChange(e.target.value)}>
                    <option value="">—</option>
                    {field.options.map((o) => (
                        <option key={o.value} value={o.value}>
                            {o.label}
                        </option>
                    ))}
                </select>
            </label>
        );
    }

    if (field.type === 'multiselect') {
        const list = Array.isArray(value) ? value : [];

        return (
            <fieldset className="flex flex-col gap-1">
                <legend className="font-medium">{label}</legend>
                <div className="flex flex-wrap gap-3">
                    {field.options.map((o) => (
                        <label key={o.value} className="flex items-center gap-1.5 text-sm">
                            <input type="checkbox" checked={list.includes(o.value)} onChange={(e) => onChange(e.target.checked ? [...list, o.value] : list.filter((v) => v !== o.value))} />
                            {o.label}
                        </label>
                    ))}
                </div>
            </fieldset>
        );
    }

    if (field.type === 'boolean') {
        return (
            <label className="flex flex-col gap-1">
                <span className="font-medium">{label}</span>
                <select className="field" value={value === true ? 'yes' : value === false ? 'no' : ''} onChange={(e) => onChange(e.target.value === '' ? '' : e.target.value === 'yes')}>
                    <option value="">—</option>
                    <option value="yes">{t('catalog.yes')}</option>
                    <option value="no">{t('catalog.no')}</option>
                </select>
            </label>
        );
    }

    return (
        <label className="flex flex-col gap-1">
            <span className="font-medium">{label}</span>
            <input
                className="field"
                type={field.type === 'number' ? 'number' : 'text'}
                value={typeof value === 'string' || typeof value === 'number' ? value : ''}
                onChange={(e) => onChange(field.type === 'number' && e.target.value !== '' ? Number(e.target.value) : e.target.value)}
            />
        </label>
    );
}
