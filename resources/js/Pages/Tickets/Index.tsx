import { Link, router } from '@inertiajs/react';
import { SupportEmails } from '@/Components/Tickets/SupportEmails';
import { DashboardLayout } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';

interface Props {
    status: string | null;
    counts: Record<string, number>;
    tickets: {
        data: { id: string; href: string; topic: string; subject: string; order: string | null; status: string; createdAt: string; lastReplyAt: string | null }[];
        page: number;
        lastPage: number;
    };
    emails: { merchant: string; customer: string; dispute: string };
}

export const TICKET_STATUS_STYLE: Record<string, string> = {
    pending: 'bg-warning/15 text-warning',
    awaiting_reply: 'bg-primary-soft text-primary',
    closed: 'bg-canvas text-muted',
};

/** Support Center ticket list (spec 1.9): All · Pending · Awaiting Reply · Closed, rows with topic, order, dates, state. */
export default function TicketsIndex({ status, counts, tickets, emails }: Props) {
    const t = useT();
    const { locale } = useShared();
    const tabs: (string | null)[] = [null, 'pending', 'awaiting_reply', 'closed'];

    return (
        <DashboardLayout title={t('ticket.center')} bare>
            <div className="grid gap-6 lg:grid-cols-[1fr_280px]">
                <section className="card p-6">
                    <div className="mb-4 flex flex-wrap items-center gap-2">
                        {tabs.map((tab) => (
                            <Link
                                key={tab ?? 'all'}
                                href={tab ? `/tickets?status=${tab}` : '/tickets'}
                                className={`rounded-full px-3 py-1.5 text-sm font-semibold ${status === tab ? 'bg-primary text-white' : 'bg-canvas'}`}
                            >
                                {t(`ticket.tab.${tab ?? 'all'}`)} <span className="opacity-75">{counts[tab ?? 'all'] ?? 0}</span>
                            </Link>
                        ))}
                        <Link href="/tickets/new" className="btn-primary ms-auto">
                            {t('ticket.new')}
                        </Link>
                    </div>

                    {tickets.data.length === 0 ? (
                        <p className="py-10 text-center text-sm text-muted">{t('common.no_results')}</p>
                    ) : (
                        <ul className="divide-y divide-line">
                            {tickets.data.map((ticket) => (
                                <li key={ticket.id} className="py-3">
                                    <Link href={ticket.href} className="block hover:text-primary">
                                        <p className="font-semibold">
                                            {t(`ticket.topic.${ticket.topic}`)} : {ticket.subject}
                                        </p>
                                    </Link>
                                    <div className="mt-1 flex flex-wrap gap-3 text-xs text-muted">
                                        {ticket.order && <span>{t('ticket.order_number', { number: ticket.order })}</span>}
                                        <span>{t('ticket.created_on', { date: new Date(ticket.createdAt).toLocaleString(locale) })}</span>
                                        {ticket.lastReplyAt && <span>{t('ticket.last_reply', { date: new Date(ticket.lastReplyAt).toLocaleString(locale) })}</span>}
                                        <span className={`rounded-full px-2 py-0.5 font-semibold ${TICKET_STATUS_STYLE[ticket.status] ?? ''}`}>{t(`ticket.status.${ticket.status}`)}</span>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}

                    {tickets.lastPage > 1 && (
                        <div className="mt-4 flex justify-center gap-2">
                            {Array.from({ length: tickets.lastPage }, (_, i) => i + 1).map((p) => (
                                <button
                                    key={p}
                                    type="button"
                                    onClick={() => router.get('/tickets', { ...(status ? { status } : {}), page: p }, { preserveScroll: true })}
                                    className={`h-8 w-8 rounded-md text-sm ${p === tickets.page ? 'bg-primary text-white' : 'bg-canvas'}`}
                                >
                                    {p}
                                </button>
                            ))}
                        </div>
                    )}
                </section>
                <SupportEmails emails={emails} />
            </div>
        </DashboardLayout>
    );
}
