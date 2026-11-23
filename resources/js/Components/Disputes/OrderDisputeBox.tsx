import { Link } from '@inertiajs/react';
import { useT } from '@/lib/i18n';

export interface OrderDispute {
    case: { href: string; status: string } | null;
    canOpen: boolean;
    openHref: string;
    supportHref: string;
}

const OPEN = ['open', 'awaiting_counterparty', 'negotiating', 'escalated'];

/** Order page strip (spec Part 10): the current case, or "Open a dispute" when the rules allow it. */
export function OrderDisputeBox({ dispute }: { dispute: OrderDispute }) {
    const t = useT();
    const open = dispute.case !== null && OPEN.includes(dispute.case.status);

    if (!dispute.case && !dispute.canOpen) {
        return (
            <p className="mt-4 text-sm">
                <Link href={dispute.supportHref} className="text-primary hover:underline">
                    {t('ticket.contact_about_order')}
                </Link>
            </p>
        );
    }

    return (
        <div className={`mt-4 flex flex-wrap items-center gap-3 rounded-md p-3 text-sm ${open ? 'bg-warning/10' : 'bg-canvas'}`}>
            <span className="flex-1">
                {dispute.case ? t('dispute.order.case', { status: t(`dispute.status.${dispute.case.status}`) }) : t('dispute.order.help')}
            </span>
            {dispute.case && (
                <Link href={dispute.case.href} className="btn-outline-pill px-3 py-1">
                    {t('dispute.order.view')}
                </Link>
            )}
            <Link href={dispute.supportHref} className="btn-ghost px-3 py-1">
                {t('ticket.contact_support')}
            </Link>
            {!open && dispute.canOpen && (
                <Link href={dispute.openHref} className="btn-outline-pill px-3 py-1 text-danger">
                    {t('dispute.order.open')}
                </Link>
            )}
        </div>
    );
}
