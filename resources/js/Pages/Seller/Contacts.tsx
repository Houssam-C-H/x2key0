import { Link } from '@inertiajs/react';
import { DashboardLayout } from '@/Layouts/DashboardLayout';
import { useT } from '@/lib/i18n';

interface Props {
    manager: { name: string } | null;
    contacts: { id: number; channel: string; value: string; hours: string | null; isPrimary: boolean; staff: string | null }[];
    merchantEmail: string;
    ticketUrl: string;
}

/** Merchant Services Contacts (spec 1.8): assigned manager, channels, merchant e-mail, ticket link. */
export default function Contacts({ manager, contacts, merchantEmail, ticketUrl }: Props) {
    const t = useT();

    return (
        <DashboardLayout title={t('menu.seller.contacts')}>
            <div className="space-y-6">
                <p className="text-sm text-muted">{t('seller.contacts.intro')}</p>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="rounded-md border border-line p-4">
                        <p className="text-xs text-muted">{t('seller.contacts.email')}</p>
                        <a href={`mailto:${merchantEmail}`} className="font-semibold text-primary hover:underline">
                            {merchantEmail}
                        </a>
                    </div>
                    <div className="rounded-md border border-line p-4">
                        <p className="text-xs text-muted">{t('seller.contacts.manager')}</p>
                        <p className="font-semibold">{manager?.name ?? t('seller.contacts.unassigned')}</p>
                    </div>
                </div>

                {contacts.length > 0 && (
                    <ul className="divide-y divide-line rounded-md border border-line">
                        {contacts.map((c) => (
                            <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 p-4">
                                <div>
                                    <p className="font-semibold">
                                        {t(`seller.contacts.channel.${c.channel}`)}
                                        {c.isPrimary && <span className="ms-2 rounded-full bg-primary-soft px-2 py-0.5 text-xs text-primary">{t('seller.contacts.primary')}</span>}
                                    </p>
                                    {c.staff && <p className="text-xs text-muted">{c.staff}</p>}
                                </div>
                                <div className="text-end">
                                    <p className="font-mono text-sm">{c.value}</p>
                                    {c.hours && <p className="text-xs text-muted">{c.hours}</p>}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                <Link href={ticketUrl} className="btn-primary">
                    {t('seller.contacts.ticket')}
                </Link>
            </div>
        </DashboardLayout>
    );
}
