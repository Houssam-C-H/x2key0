import { useT } from '@/lib/i18n';

/** Side box with the merchant, customer and dispute service e-mails (spec 1.9). */
export function SupportEmails({ emails }: { emails: { merchant: string; customer: string; dispute: string } }) {
    const t = useT();

    return (
        <aside className="card h-fit space-y-3 p-5 text-sm">
            <h2 className="font-bold">{t('ticket.emails.title')}</h2>
            {(['customer', 'merchant', 'dispute'] as const).map((k) => (
                <div key={k}>
                    <p className="text-xs text-muted">{t(`ticket.emails.${k}`)}</p>
                    <a href={`mailto:${emails[k]}`} className="font-semibold text-primary hover:underline">
                        {emails[k]}
                    </a>
                </div>
            ))}
        </aside>
    );
}
