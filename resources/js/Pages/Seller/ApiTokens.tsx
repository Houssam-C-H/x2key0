import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';

interface Token {
    id: number;
    name: string;
    abilities: string[];
    lastUsedAt: string | null;
    createdAt: string | null;
}

interface Props {
    tokens: Token[];
    abilities: string[];
    twoFactor: boolean;
    baseUrl: string;
    max: number;
}

/** Seller Center › API tokens (ADR-038). */
export default function ApiTokens({ tokens, abilities, twoFactor, baseUrl, max }: Props) {
    const t = useT();
    const { locale } = useShared();
    const { props } = usePage<{ flash: { newToken?: string | null } }>();
    const newToken = props.flash.newToken ?? null;
    const [copied, setCopied] = useState(false);
    const form = useForm<{ name: string; abilities: string[] }>({ name: '', abilities: ['offers:read'] });

    const toggle = (a: string) => form.setData('abilities', form.data.abilities.includes(a) ? form.data.abilities.filter((x) => x !== a) : [...form.data.abilities, a]);

    return (
        <DashboardLayout title={t('menu.seller.api')} bare>
            <Panel title={t('menu.seller.api')} description={t('api.intro')}>
                <p className="text-sm text-muted">{t('api.docs')}</p>
                <p className="mt-2 text-sm">
                    {t('api.base_url')}: <code className="rounded bg-canvas px-2 py-1 font-mono text-xs">{baseUrl}</code>
                </p>
            </Panel>

            {newToken && (
                <div className="rounded-xl border border-success/30 bg-success/5 p-4">
                    <p className="font-semibold text-success">{t('api.your_token')}</p>
                    <p className="mb-2 text-xs text-muted">{t('api.created')}</p>
                    <div className="flex flex-wrap items-center gap-2">
                        <code className="break-all rounded bg-white px-3 py-2 font-mono text-sm ring-1 ring-line">{newToken}</code>
                        <button
                            type="button"
                            className="btn-outline-pill px-3 py-1.5 text-sm"
                            onClick={() => void navigator.clipboard?.writeText(newToken).then(() => setCopied(true))}
                        >
                            {copied ? '✓' : t('api.copy')}
                        </button>
                    </div>
                </div>
            )}

            <Panel title={t('api.new')}>
                <form
                    className="space-y-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/seller/api/tokens', { preserveScroll: true, onSuccess: () => form.reset('name') });
                    }}
                >
                    <label className="flex max-w-md flex-col gap-1 text-sm">
                        <span className="font-medium">{t('api.name')}</span>
                        <input className="field" value={form.data.name} maxLength={60} required onChange={(e) => form.setData('name', e.target.value)} />
                        {form.errors.name && <span className="text-danger">{form.errors.name}</span>}
                    </label>
                    <fieldset className="grid gap-2 sm:grid-cols-2">
                        {abilities.map((a) => (
                            <label key={a} className={'flex items-center gap-2 text-sm ' + (a === 'storage:write' && !twoFactor ? 'text-muted' : '')}>
                                <input type="checkbox" className="accent-primary" checked={form.data.abilities.includes(a)} disabled={a === 'storage:write' && !twoFactor} onChange={() => toggle(a)} />
                                {t('api.ability.' + a)}
                            </label>
                        ))}
                    </fieldset>
                    {form.errors.abilities && <p className="text-sm text-danger">{form.errors.abilities}</p>}
                    <button type="submit" className="btn-primary" disabled={form.processing || tokens.length >= max}>
                        {t('api.create')}
                    </button>
                </form>
            </Panel>

            <Panel title={`${t('menu.seller.api')} (${tokens.length}/${max})`}>
                {tokens.length === 0 ? (
                    <p className="text-sm text-muted">{t('api.none')}</p>
                ) : (
                    <ul className="divide-y divide-line">
                        {tokens.map((tk) => (
                            <li key={tk.id} className="flex flex-wrap items-center gap-3 py-3">
                                <div className="min-w-0 flex-1">
                                    <p className="font-semibold">{tk.name}</p>
                                    <p className="text-xs text-muted">
                                        {tk.abilities.map((a) => t('api.ability.' + a)).join(' · ')} — {t('api.last_used')}: {tk.lastUsedAt ? new Date(tk.lastUsedAt).toLocaleString(locale) : t('api.never')}
                                    </p>
                                </div>
                                <button type="button" className="text-sm font-semibold text-danger hover:underline" onClick={() => router.delete(`/seller/api/tokens/${tk.id}`, { preserveScroll: true })}>
                                    {t('api.revoke')}
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>
        </DashboardLayout>
    );
}
