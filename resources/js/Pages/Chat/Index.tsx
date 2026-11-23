import { Link, router } from '@inertiajs/react';
import { type FormEvent, useEffect, useRef, useState } from 'react';
import { DashboardLayout } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';
import { xsrfToken } from '@/lib/offer';

interface Conversation {
    id: string;
    type: 'order' | 'pre_sale' | 'support';
    with: { name: string; online: boolean; href: string | null };
    order: string | null;
    last: { text: string; at: string } | null;
    unread: number;
}

interface ChatMessage {
    id: number;
    mine: boolean;
    role: string;
    kind: 'text' | 'system' | 'auto_reply' | 'auto_hours';
    text: string;
    hidden: boolean;
    at: string;
}

interface Props {
    conversations: Conversation[];
    active: { id: string; with: Conversation['with']; order: { number: string; public_id: string } | null } | null;
    messages: ChatMessage[];
    tab: 'all' | 'unread' | 'read';
    maxLength: number;
}

const POLL_MS = 5000;

/** Chat (spec 12.1): conversation list with All / Unread / Read, thread, safety banner, contact-info notice. Polls every 5 s. */
export default function Chat({ conversations, active, messages: initial, tab: initialTab, maxLength }: Props) {
    const t = useT();
    const { locale } = useShared();
    const [tab, setTab] = useState(initialTab);
    const [query, setQuery] = useState('');
    const [messages, setMessages] = useState(initial);
    const [online, setOnline] = useState(active?.with.online ?? false);
    const [body, setBody] = useState('');
    const [sending, setSending] = useState(false);
    const [failed, setFailed] = useState(false);
    const endRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        setMessages(initial);
        setOnline(active?.with.online ?? false);
    }, [initial, active?.id, active?.with.online]);

    useEffect(() => {
        if (!active) {
            return;
        }
        const id = window.setInterval(async () => {
            try {
                const response = await fetch(`/chat/${active.id}/poll`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (response.ok) {
                    const data = (await response.json()) as { messages: ChatMessage[]; online: boolean };
                    setMessages(data.messages);
                    setOnline(data.online);
                }
            } catch {
                /* network hiccup: try again on the next tick */
            }
        }, POLL_MS);

        return () => window.clearInterval(id);
    }, [active?.id]);

    useEffect(() => {
        endRef.current?.scrollIntoView({ block: 'end' });
    }, [messages.length, active?.id]);

    const totalUnread = conversations.filter((c) => c.unread > 0).length;
    const shown = conversations
        .filter((c) => (tab === 'unread' ? c.unread > 0 : tab === 'read' ? c.unread === 0 : true))
        .filter((c) => c.with.name.toLowerCase().includes(query.trim().toLowerCase()) || (c.order ?? '').toLowerCase().includes(query.trim().toLowerCase()));

    const send = async (e: FormEvent) => {
        e.preventDefault();
        if (!active || body.trim() === '' || sending) {
            return;
        }
        setSending(true);
        setFailed(false);
        try {
            const response = await fetch(`/chat/${active.id}/messages`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
                body: JSON.stringify({ body }),
            });
            if (!response.ok) {
                setFailed(true);
            } else {
                setBody('');
                router.reload({ only: ['messages', 'conversations'] });
            }
        } finally {
            setSending(false);
        }
    };

    return (
        <DashboardLayout title={t('header.chat')} bare>
            <section className="card grid min-h-[32rem] grid-cols-1 overflow-hidden p-0 md:grid-cols-[320px_1fr]">
                <aside className={`border-line md:border-e ${active ? 'hidden md:block' : ''}`}>
                    <div className="space-y-2 border-b border-line p-3">
                        <input className="input w-full" type="search" value={query} onChange={(e) => setQuery(e.target.value)} placeholder={t('chat.search')} aria-label={t('chat.search')} />
                        <div className="flex gap-1">
                            {(['all', 'unread', 'read'] as const).map((k) => (
                                <button
                                    key={k}
                                    type="button"
                                    aria-pressed={tab === k}
                                    onClick={() => setTab(k)}
                                    className={`rounded-full border px-3 py-1 text-xs font-semibold ${tab === k ? 'border-primary bg-primary-soft text-primary' : 'border-line'}`}
                                >
                                    {t(`chat.tab.${k}`)}
                                    {k === 'unread' && totalUnread > 0 ? ` (${totalUnread})` : ''}
                                </button>
                            ))}
                        </div>
                    </div>
                    <ul className="max-h-[36rem] divide-y divide-line overflow-y-auto">
                        {shown.length === 0 && <li className="p-4 text-sm text-muted">{t('chat.empty_list')}</li>}
                        {shown.map((c) => (
                            <li key={c.id}>
                                <Link href={`/chat/${c.id}`} className={`flex items-start gap-2 p-3 hover:bg-canvas ${active?.id === c.id ? 'bg-primary-soft' : ''}`}>
                                    <span aria-hidden className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${c.with.online ? 'bg-success' : 'bg-line'}`} />
                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-center justify-between gap-2">
                                            <span className="truncate text-sm font-semibold">{c.with.name}</span>
                                            {c.last && <span className="shrink-0 text-[11px] text-muted">{new Date(c.last.at).toLocaleDateString(locale)}</span>}
                                        </span>
                                        <span className="block truncate text-xs text-muted">{c.order ? `${c.order} · ` : ''}{c.last?.text ?? t('chat.no_messages')}</span>
                                    </span>
                                    {c.unread > 0 && <span className="rounded-full bg-danger px-1.5 text-[10px] font-bold leading-4 text-white">{c.unread}</span>}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </aside>

                <div className={`flex min-h-[32rem] flex-col ${active ? '' : 'hidden md:flex'}`}>
                    <div role="note" className="bg-warning-soft px-4 py-2 text-xs text-ink">
                        {t('chat.safety')}
                    </div>
                    {!active ? (
                        <div className="m-auto max-w-sm p-8 text-center">
                            <h2 className="text-lg font-bold">{t('chat.welcome')}</h2>
                            <p className="mt-2 text-sm text-muted">{t('chat.welcome_help')}</p>
                        </div>
                    ) : (
                        <>
                            <header className="flex items-center gap-3 border-b border-line px-4 py-3">
                                <Link href="/chat" className="text-sm text-primary md:hidden">
                                    ←
                                </Link>
                                <span aria-hidden className={`h-2.5 w-2.5 rounded-full ${online ? 'bg-success' : 'bg-line'}`} />
                                <div className="min-w-0">
                                    {active.with.href ? (
                                        <a href={active.with.href} className="truncate font-bold hover:text-primary">
                                            {active.with.name}
                                        </a>
                                    ) : (
                                        <span className="truncate font-bold">{active.with.name}</span>
                                    )}
                                    <span className="block text-xs text-muted">{online ? t('chat.online') : t('chat.offline')}</span>
                                </div>
                                {active.order && (
                                    <Link href={`/buy/orders/${active.order.public_id}`} className="ms-auto text-sm text-primary" prefetch={false}>
                                        {active.order.number}
                                    </Link>
                                )}
                            </header>
                            <ol className="flex-1 space-y-2 overflow-y-auto p-4" aria-live="polite" aria-label={t('chat.thread')}>
                                {messages.map((m) =>
                                    m.kind === 'system' ? (
                                        <li key={m.id} className="text-center text-xs text-muted">
                                            {m.text}
                                        </li>
                                    ) : (
                                        <li key={m.id} className={`flex ${m.mine ? 'justify-end' : 'justify-start'}`}>
                                            <div className={`max-w-[80%] rounded-2xl px-3 py-2 text-sm ${m.mine ? 'bg-primary text-white' : 'bg-canvas'}`}>
                                                <p className="whitespace-pre-wrap break-words">{m.text}</p>
                                                {m.kind.startsWith('auto') && <p className="mt-1 text-[10px] opacity-70">{t('chat.auto_reply')}</p>}
                                                {m.hidden && <p className="mt-1 text-[11px] font-semibold text-warning">{t('chat.contact_hidden')}</p>}
                                                <time className="mt-1 block text-[10px] opacity-60" dateTime={m.at}>
                                                    {new Date(m.at).toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' })}
                                                </time>
                                            </div>
                                        </li>
                                    ),
                                )}
                                <div ref={endRef} />
                            </ol>
                            <form onSubmit={send} className="border-t border-line p-3">
                                {failed && <p className="mb-2 text-sm text-danger">{t('chat.send_failed')}</p>}
                                <div className="flex gap-2">
                                    <textarea
                                        className="input min-h-[2.75rem] flex-1 resize-y"
                                        rows={2}
                                        maxLength={maxLength}
                                        value={body}
                                        onChange={(e) => setBody(e.target.value)}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter' && !e.shiftKey) {
                                                e.preventDefault();
                                                void send(e as unknown as FormEvent);
                                            }
                                        }}
                                        placeholder={t('chat.placeholder')}
                                        aria-label={t('chat.placeholder')}
                                    />
                                    <button type="submit" className="btn-primary self-end" disabled={sending || body.trim() === ''}>
                                        {t('chat.send')}
                                    </button>
                                </div>
                            </form>
                        </>
                    )}
                </div>
            </section>
        </DashboardLayout>
    );
}
