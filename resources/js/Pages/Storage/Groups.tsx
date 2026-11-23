import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Field } from '@/Components/Field';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';

interface Group {
    id: number;
    name: string;
    type: string;
    threshold: number;
    available: number;
    low: boolean;
    counts: Record<string, number>;
    flagged: number;
    offers: number;
}

interface Batch {
    id: number;
    group: string | null;
    source: string;
    total: number;
    accepted: number;
    duplicates: number;
    invalid: number;
    flagged: number;
    createdAt: string;
}

interface Props {
    groups: Group[];
    batches: Batch[];
    types: string[];
    defaultThreshold: number;
    maxItems: number;
    accountFields: string[];
    readOnly: boolean;
}

/**
 * Storage Category (spec 1.7 / 5.5 / 8.1): groups with counts and low-stock thresholds, import (paste,
 * TXT/CSV file, account form) with a duplicate report. Secrets are sent once and never come back here.
 */
export default function StorageGroups({ groups, batches, types, defaultThreshold, maxItems, accountFields, readOnly }: Props) {
    const t = useT();
    const { locale } = useShared();
    const [editing, setEditing] = useState<Group | null>(null);
    const [importing, setImporting] = useState<Group | null>(null);

    const groupForm = useForm({ name: '', type: types[0] ?? 'key', threshold: defaultThreshold });

    const saveGroup = (e: React.FormEvent) => {
        e.preventDefault();
        if (editing) {
            groupForm.put(`/seller/storage/groups/${editing.id}`, { preserveScroll: true, onSuccess: () => setEditing(null) });
        } else {
            groupForm.post('/seller/storage/groups', { preserveScroll: true, onSuccess: () => groupForm.reset() });
        }
    };

    const startEdit = (g: Group) => {
        setEditing(g);
        groupForm.setData({ name: g.name, type: g.type, threshold: g.threshold });
        groupForm.clearErrors();
    };

    return (
        <DashboardLayout title={t('menu.storage.groups')} bare>
            <p className="rounded-md bg-primary-soft p-3 text-sm text-primary">{t('storage.security_note')}</p>
            {readOnly && <p className="rounded-md bg-danger/5 p-3 text-sm text-danger">{t('seller.error.suspended')}</p>}

            <Panel title={editing ? t('storage.group.edit', { name: editing.name }) : t('storage.group.create')} description={t('storage.group.help')}>
                <form className="grid gap-4 sm:grid-cols-[2fr_1fr_1fr_auto] sm:items-end" onSubmit={saveGroup}>
                    <Field label={t('storage.group.name')} name="name" value={groupForm.data.name} maxLength={60} onChange={(e) => groupForm.setData('name', e.target.value)} error={groupForm.errors.name} />
                    <label className="flex flex-col gap-1">
                        <span className="font-medium">{t('storage.group.type')}</span>
                        <select className="field" value={groupForm.data.type} onChange={(e) => groupForm.setData('type', e.target.value)}>
                            {types.map((type) => (
                                <option key={type} value={type}>
                                    {t(`storage.type.${type}`)}
                                </option>
                            ))}
                        </select>
                    </label>
                    <Field
                        label={t('storage.group.threshold')}
                        name="threshold"
                        type="number"
                        min={0}
                        value={groupForm.data.threshold}
                        onChange={(e) => groupForm.setData('threshold', Number(e.target.value))}
                        error={groupForm.errors.threshold}
                    />
                    <div className="flex gap-2">
                        {editing && (
                            <button type="button" className="btn-ghost" onClick={() => setEditing(null)}>
                                {t('common.cancel')}
                            </button>
                        )}
                        <button type="submit" className="btn-primary" disabled={groupForm.processing || readOnly}>
                            {t(editing ? 'common.save' : 'storage.group.add')}
                        </button>
                    </div>
                </form>
            </Panel>

            <Panel title={t('storage.group.list')}>
                {groups.length === 0 ? (
                    <p className="py-6 text-center text-sm text-muted">{t('storage.group.empty')}</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[720px] text-sm">
                            <thead className="text-xs uppercase text-muted">
                                <tr>
                                    <th className="py-2 text-start">{t('storage.group.name')}</th>
                                    <th className="py-2 text-start">{t('storage.group.type')}</th>
                                    <th className="py-2 text-start">{t('storage.status.available')}</th>
                                    <th className="py-2 text-start">{t('storage.group.other')}</th>
                                    <th className="py-2 text-start">{t('storage.group.threshold')}</th>
                                    <th className="py-2 text-start">{t('storage.group.offers')}</th>
                                    <th className="py-2 text-start">{t('offer.col.action')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {groups.map((g) => (
                                    <tr key={g.id} className="border-t border-line align-top">
                                        <td className="py-2.5 font-semibold">{g.name}</td>
                                        <td className="py-2.5">{t(`storage.type.${g.type}`)}</td>
                                        <td className="py-2.5">
                                            <span className={g.low ? 'font-bold text-danger' : 'font-semibold text-success'}>{g.available}</span>
                                            {g.low && <span className="ms-2 rounded-full bg-danger/10 px-2 py-0.5 text-xs font-semibold text-danger">{t('storage.low')}</span>}
                                            {g.flagged > 0 && <p className="text-xs text-warning">{t('storage.flagged_count', { count: g.flagged })}</p>}
                                        </td>
                                        <td className="py-2.5 text-xs text-muted">
                                            {Object.entries(g.counts)
                                                .filter(([status]) => status !== 'available')
                                                .map(([status, n]) => `${t(`storage.status.${status}`)}: ${n}`)
                                                .join(' · ') || '—'}
                                        </td>
                                        <td className="py-2.5">{g.threshold}</td>
                                        <td className="py-2.5">{g.offers}</td>
                                        <td className="space-x-3 py-2.5 whitespace-nowrap">
                                            <button type="button" className="font-semibold text-primary hover:underline" disabled={readOnly} onClick={() => setImporting(g)}>
                                                {t('storage.import.action')}
                                            </button>
                                            <Link href={`/seller/storage/items?group=${g.id}`} className="hover:text-primary">
                                                {t('storage.items.view')}
                                            </Link>
                                            <button type="button" className="text-muted hover:text-ink" disabled={readOnly} onClick={() => startEdit(g)}>
                                                {t('offer.action.edit')}
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>

            {importing && <ImportPanel group={importing} maxItems={maxItems} accountFields={accountFields} onClose={() => setImporting(null)} />}

            <Panel title={t('storage.import.reports')} description={t('storage.import.reports_help')}>
                {batches.length === 0 ? (
                    <p className="py-4 text-center text-sm text-muted">{t('common.no_results')}</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[640px] text-sm">
                            <thead className="text-xs uppercase text-muted">
                                <tr>
                                    <th className="py-2 text-start">{t('storage.import.date')}</th>
                                    <th className="py-2 text-start">{t('storage.group.name')}</th>
                                    <th className="py-2 text-start">{t('storage.import.total')}</th>
                                    <th className="py-2 text-start">{t('storage.import.accepted')}</th>
                                    <th className="py-2 text-start">{t('storage.import.duplicates')}</th>
                                    <th className="py-2 text-start">{t('storage.import.invalid')}</th>
                                    <th className="py-2 text-start">{t('storage.import.flagged')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {batches.map((b) => (
                                    <tr key={b.id} className="border-t border-line">
                                        <td className="py-2 text-muted">{new Date(b.createdAt).toLocaleString(locale)}</td>
                                        <td className="py-2">{b.group ?? '—'}</td>
                                        <td className="py-2">{b.total}</td>
                                        <td className="py-2 font-semibold text-success">{b.accepted}</td>
                                        <td className={`py-2 ${b.duplicates > 0 ? 'font-semibold text-warning' : ''}`}>{b.duplicates}</td>
                                        <td className={`py-2 ${b.invalid > 0 ? 'text-danger' : ''}`}>{b.invalid}</td>
                                        <td className={`py-2 ${b.flagged > 0 ? 'font-semibold text-danger' : ''}`}>{b.flagged}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>
        </DashboardLayout>
    );
}

function ImportPanel({ group, maxItems, accountFields, onClose }: { group: Group; maxItems: number; accountFields: string[]; onClose: () => void }) {
    const t = useT();
    const isAccount = group.type === 'account';
    const [mode, setMode] = useState<'paste' | 'file' | 'form'>('paste');
    const form = useForm<{ mode: string; items: string; file: File | null; account: Record<string, string> }>({
        mode: 'paste',
        items: '',
        file: null,
        account: Object.fromEntries(accountFields.map((f) => [f, ''])),
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, mode }));
        form.post(`/seller/storage/groups/${group.id}/import`, {
            preserveScroll: true,
            forceFormData: mode === 'file',
            onSuccess: () => {
                form.reset();
                onClose();
                router.reload({ only: ['groups', 'batches'] });
            },
        });
    };

    return (
        <Panel title={t('storage.import.title', { name: group.name })} description={t(isAccount ? 'storage.import.help_account' : 'storage.import.help', { max: maxItems })}>
            <form className="space-y-4" onSubmit={submit} autoComplete="off">
                <div className="flex flex-wrap gap-2" role="tablist">
                    {(['paste', 'file', ...(isAccount ? (['form'] as const) : [])] as const).map((m) => (
                        <button key={m} type="button" role="tab" aria-selected={mode === m} onClick={() => setMode(m)} className={`rounded-full border px-3 py-1 text-sm font-semibold ${mode === m ? 'border-primary bg-primary-soft text-primary' : 'border-line'}`}>
                            {t(`storage.import.mode.${m}`)}
                        </button>
                    ))}
                </div>

                {mode === 'paste' && (
                    <label className="flex flex-col gap-1">
                        <span className="font-medium">{t('storage.import.items')}</span>
                        <textarea
                            className={`field font-mono text-sm ${form.errors.items ? 'border-danger' : ''}`}
                            rows={10}
                            spellCheck={false}
                            autoComplete="off"
                            placeholder={isAccount ? 'login|password|email|email_password|recovery_codes|notes' : 'XXXXX-XXXXX-XXXXX'}
                            value={form.data.items}
                            onChange={(e) => form.setData('items', e.target.value)}
                        />
                        {form.errors.items && <span className="text-sm text-danger">{form.errors.items}</span>}
                    </label>
                )}

                {mode === 'file' && (
                    <label className="flex flex-col gap-1">
                        <span className="font-medium">{t('storage.import.file')}</span>
                        <input type="file" accept=".txt,.csv,text/plain,text/csv" className="field" onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)} />
                        {form.errors.file && <span className="text-sm text-danger">{form.errors.file}</span>}
                    </label>
                )}

                {mode === 'form' && (
                    <div className="grid gap-4 md:grid-cols-2">
                        {accountFields.map((f) => (
                            <Field
                                key={f}
                                label={t(`storage.account.${f}`)}
                                name={`account-${f}`}
                                type={f.includes('password') ? 'password' : 'text'}
                                autoComplete="off"
                                value={form.data.account[f] ?? ''}
                                onChange={(e) => form.setData('account', { ...form.data.account, [f]: e.target.value })}
                            />
                        ))}
                        {form.errors.account && <p className="text-sm text-danger md:col-span-2">{form.errors.account}</p>}
                    </div>
                )}

                <div className="flex justify-end gap-2">
                    <button type="button" className="btn-ghost" onClick={onClose}>
                        {t('common.cancel')}
                    </button>
                    <button type="submit" className="btn-primary" disabled={form.processing}>
                        {t('storage.import.submit')}
                    </button>
                </div>
            </form>
        </Panel>
    );
}
