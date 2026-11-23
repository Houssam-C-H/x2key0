import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { TextArea } from '@/Components/Field';
import { DashboardLayout, Panel } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';
import { TICKET_STATUS_STYLE } from './Index';

interface Props {
    ticket: {
        id: string;
        href: string;
        topic: string;
        subject: string;
        status: string;
        form: Record<string, string>;
        order: { number: string; href: string } | null;
        dispute: string | null;
        createdAt: string;
        closedAt: string | null;
        satisfaction: number | null;
        messages: { id: number; role: string; body: string; at: string; files: { name: string; size: number; href: string }[] }[];
    };
    can: { reply: boolean; close: boolean; reopen: boolean; rate: boolean };
    reopenUntil: string | null;
    maxFiles: number;
}

/** A ticket thread (spec Part 11 / 6.5): messages and files, reply, close, reopen within the window, rating. */
export default function TicketShow({ ticket, can, reopenUntil, maxFiles }: Props) {
    const t = useT();
    const { locale, errors } = useShared();
    const reply = useForm<{ body: string; files: File[] }>({ body: '', files: [] });
    const rate = useForm({ score: 5, comment: '' });
    const [hover, setHover] = useState<number | null>(null);

    return (
        <DashboardLayout title={`${t(`ticket.topic.${ticket.topic}`)} : ${ticket.subject}`} bare>
            <Panel title={ticket.subject}>
                <div className="flex flex-wrap items-center gap-3 text-sm">
                    <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${TICKET_STATUS_STYLE[ticket.status] ?? ''}`}>{t(`ticket.status.${ticket.status}`)}</span>
                    <span className="text-muted">{t(`ticket.topic.${ticket.topic}`)}</span>
                    {ticket.order && (
                        <Link href={ticket.order.href} className="text-primary hover:underline">
                            {t('ticket.order_number', { number: ticket.order.number })}
                        </Link>
                    )}
                    <span className="text-muted">{t('ticket.created_on', { date: new Date(ticket.createdAt).toLocaleString(locale) })}</span>
                    <span className="ms-auto flex gap-2">
                        {can.close && (
                            <button type="button" className="btn-outline-pill px-3 py-1" onClick={() => window.confirm(t('ticket.confirm_close')) && router.post(`${ticket.href}/close`, {}, { preserveScroll: true })}>
                                {t('ticket.action.close')}
                            </button>
                        )}
                        {can.reopen && (
                            <button type="button" className="btn-outline-pill px-3 py-1" onClick={() => router.post(`${ticket.href}/reopen`, {}, { preserveScroll: true })}>
                                {t('ticket.action.reopen')}
                            </button>
                        )}
                    </span>
                </div>
                <p className="mt-3 text-sm">{t(`ticket.explain.${ticket.status}`)}</p>
                {ticket.status === 'closed' && reopenUntil && can.reopen && <p className="mt-1 text-xs text-muted">{t('ticket.reopen_until', { date: new Date(reopenUntil).toLocaleDateString(locale) })}</p>}
                {ticket.dispute && (
                    <p className="mt-2 text-sm">
                        <Link href={ticket.dispute} className="text-primary hover:underline">
                            {t('ticket.converted')}
                        </Link>
                    </p>
                )}
                {errors.ticket && <p className="mt-3 rounded-md bg-danger/5 p-3 text-sm text-danger">{errors.ticket}</p>}
                {Object.keys(ticket.form).length > 0 && (
                    <dl className="mt-4 grid gap-2 rounded-md bg-canvas p-3 text-sm sm:grid-cols-2">
                        {Object.entries(ticket.form).map(([k, v]) => (
                            <div key={k}>
                                <dt className="text-xs text-muted">{t(`ticket.field.${k}`)}</dt>
                                <dd className="font-medium">{v}</dd>
                            </div>
                        ))}
                    </dl>
                )}
            </Panel>

            <Panel title={t('ticket.thread')}>
                <ul className="space-y-3">
                    {ticket.messages.map((m) =>
                        m.role === 'system' ? (
                            <li key={m.id} className="text-center text-xs text-muted">
                                {m.body} · {new Date(m.at).toLocaleString(locale)}
                            </li>
                        ) : (
                            <li key={m.id} className={`flex ${m.role === 'user' ? 'justify-end' : 'justify-start'}`}>
                                <div className={`max-w-[80%] rounded-lg px-3 py-2 text-sm ${m.role === 'user' ? 'bg-primary text-white' : 'bg-canvas'}`}>
                                    <p className="text-xs opacity-75">
                                        {t(m.role === 'user' ? 'ticket.you' : 'dispute.by.staff')} · {new Date(m.at).toLocaleString(locale)}
                                    </p>
                                    <p className="mt-1 whitespace-pre-line">{m.body}</p>
                                    {m.files.map((f) => (
                                        <a key={f.href} href={f.href} target="_blank" rel="noreferrer" className="mt-1 block text-xs underline">
                                            {f.name} ({Math.ceil(f.size / 1024)} KB)
                                        </a>
                                    ))}
                                </div>
                            </li>
                        ),
                    )}
                </ul>

                {can.reply && (
                    <form
                        className="mt-6 space-y-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            reply.post(`${ticket.href}/messages`, { preserveScroll: true, forceFormData: true, onSuccess: () => reply.reset() });
                        }}
                    >
                        <TextArea label={t('ticket.reply')} name="body" rows={4} value={reply.data.body} onChange={(v) => reply.setData('body', v)} error={reply.errors.body} />
                        <div className="flex flex-wrap items-center gap-3">
                            <input type="file" multiple aria-label={t('ticket.create.files', { count: maxFiles })} onChange={(e) => reply.setData('files', Array.from(e.target.files ?? []).slice(0, maxFiles))} />
                            <button type="submit" className="btn-primary ms-auto" disabled={reply.processing || (reply.data.body.trim() === '' && reply.data.files.length === 0)}>
                                {t('dispute.thread.send')}
                            </button>
                        </div>
                    </form>
                )}
            </Panel>

            {(can.rate || ticket.satisfaction !== null) && (
                <Panel title={t('ticket.rate.title')}>
                    {ticket.satisfaction !== null ? (
                        <p className="text-sm">{t('ticket.rate.thanks', { score: ticket.satisfaction })}</p>
                    ) : (
                        <form
                            className="space-y-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                rate.post(`${ticket.href}/rate`, { preserveScroll: true });
                            }}
                        >
                            <div className="flex gap-1" onMouseLeave={() => setHover(null)}>
                                {[1, 2, 3, 4, 5].map((n) => (
                                    <button
                                        key={n}
                                        type="button"
                                        aria-label={t('ticket.rate.star', { count: n })}
                                        onMouseEnter={() => setHover(n)}
                                        onClick={() => rate.setData('score', n)}
                                        className={`text-2xl ${(hover ?? rate.data.score) >= n ? 'text-warning' : 'text-line'}`}
                                    >
                                        ★
                                    </button>
                                ))}
                            </div>
                            <TextArea label={t('ticket.rate.comment')} name="comment" rows={2} value={rate.data.comment} onChange={(v) => rate.setData('comment', v)} error={rate.errors.comment} />
                            <button type="submit" className="btn-primary" disabled={rate.processing}>
                                {t('ticket.rate.submit')}
                            </button>
                        </form>
                    )}
                </Panel>
            )}
        </DashboardLayout>
    );
}
