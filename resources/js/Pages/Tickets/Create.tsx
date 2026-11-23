import { useForm } from '@inertiajs/react';
import { Field, TextArea } from '@/Components/Field';
import { SupportEmails } from '@/Components/Tickets/SupportEmails';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';

interface Props {
    topics: { value: string; fields: { key: string; required: boolean; max: number }[] }[];
    orders: { id: string; number: string }[];
    selectedOrder: string | null;
    maxFiles: number;
    emails: { merchant: string; customer: string; dispute: string };
}

/** New ticket (spec 1.9): topic → follow-up form, subject, optional order, message, attachments. */
export default function TicketCreate({ topics, orders, selectedOrder, maxFiles, emails }: Props) {
    const t = useT();
    const { errors } = useShared();
    const form = useForm<{ topic: string; subject: string; body: string; order: string; form: Record<string, string>; files: File[] }>({
        topic: selectedOrder ? 'buying_paying' : (topics[0]?.value ?? 'other'),
        subject: '',
        body: '',
        order: selectedOrder ?? '',
        form: {},
        files: [],
    });
    const fields = topics.find((x) => x.value === form.data.topic)?.fields ?? [];
    const fieldError = (key: string) => (form.errors as Record<string, string | undefined>)[key];

    return (
        <DashboardLayout title={t('ticket.new')} bare>
            <div className="grid gap-6 lg:grid-cols-[1fr_280px]">
                <form
                    className="space-y-6"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/tickets', { forceFormData: true });
                    }}
                >
                    <Panel title={t('ticket.create.questions')} description={t('ticket.create.intro')}>
                        {errors.ticket && <p className="mb-4 rounded-md bg-danger/5 p-3 text-sm text-danger">{errors.ticket}</p>}
                        <div className="grid gap-4 sm:grid-cols-2">
                            <label className="flex flex-col gap-1">
                                <span className="font-medium">{t('ticket.create.topic')}</span>
                                <select className="field" value={form.data.topic} onChange={(e) => form.setData((d) => ({ ...d, topic: e.target.value, form: {} }))}>
                                    {topics.map((x) => (
                                        <option key={x.value} value={x.value}>
                                            {t(`ticket.topic.${x.value}`)}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="flex flex-col gap-1">
                                <span className="font-medium">{t('ticket.create.order')}</span>
                                <select className="field" value={form.data.order} onChange={(e) => form.setData('order', e.target.value)}>
                                    <option value="">{t('ticket.create.no_order')}</option>
                                    {orders.map((o) => (
                                        <option key={o.id} value={o.id}>
                                            {o.number}
                                        </option>
                                    ))}
                                </select>
                                {form.errors.order && <span className="text-sm text-danger">{form.errors.order}</span>}
                            </label>
                        </div>

                        {fields.length > 0 && (
                            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                                {fields.map((f) => (
                                    <Field
                                        key={f.key}
                                        label={`${t(`ticket.field.${f.key}`)}${f.required ? ' *' : ''}`}
                                        name={`form-${f.key}`}
                                        required={f.required}
                                        maxLength={f.max}
                                        value={form.data.form[f.key] ?? ''}
                                        onChange={(e) => form.setData('form', { ...form.data.form, [f.key]: e.target.value })}
                                        error={fieldError(`form.${f.key}`)}
                                    />
                                ))}
                            </div>
                        )}

                        <div className="mt-4 space-y-4">
                            <Field label={t('ticket.create.subject')} name="subject" required maxLength={150} value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} error={form.errors.subject} />
                            <TextArea label={t('ticket.create.message')} name="body" rows={6} value={form.data.body} onChange={(v) => form.setData('body', v)} error={form.errors.body} />
                            <label className="flex flex-col gap-1 text-sm">
                                <span className="font-medium">{t('ticket.create.files', { count: maxFiles })}</span>
                                <input type="file" multiple accept="image/*,application/pdf,text/plain" onChange={(e) => form.setData('files', Array.from(e.target.files ?? []).slice(0, maxFiles))} />
                                {(fieldError('files') ?? fieldError('files.0')) && <span className="text-danger">{fieldError('files') ?? fieldError('files.0')}</span>}
                            </label>
                        </div>
                    </Panel>
                    <div className="flex justify-end">
                        <button type="submit" className="btn-primary" disabled={form.processing}>
                            {t('ticket.create.submit')}
                        </button>
                    </div>
                </form>
                <SupportEmails emails={emails} />
            </div>
        </DashboardLayout>
    );
}
