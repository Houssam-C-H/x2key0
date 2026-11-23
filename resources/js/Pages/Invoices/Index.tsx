import { router } from '@inertiajs/react';
import { DashboardLayout } from '@/Layouts/DashboardLayout';
import { useShared, useT } from '@/lib/i18n';
import { formatUsd } from '@/lib/money';

interface Props {
    type: 'service_invoice' | 'withdrawal_voucher';
    invoices: { data: { number: string; href: string; period: string | null; total: number; currency: string; issuedAt: string }[]; page: number; lastPage: number };
}

/** Service invoices / Withdrawal vouchers (spec 1.7 / Part 16): issued documents, opened as printable pages. */
export default function InvoicesIndex({ type, invoices }: Props) {
    const t = useT();
    const { locale } = useShared();
    const service = type === 'service_invoice';

    return (
        <DashboardLayout title={t(service ? 'menu.invoices.service' : 'menu.invoices.vouchers')}>
            <p className="mb-4 text-sm text-muted">{t(service ? 'invoice.service_help' : 'invoice.voucher_help')}</p>
            {invoices.data.length === 0 ? (
                <p className="py-10 text-center text-sm text-muted">{t('common.no_results')}</p>
            ) : (
                <table className="w-full text-sm">
                    <thead className="text-xs uppercase text-muted">
                        <tr>
                            <th className="py-2 text-start">{t('invoice.number')}</th>
                            {service && <th className="py-2 text-start">{t('invoice.period')}</th>}
                            <th className="py-2 text-end">{t(service ? 'invoice.total' : 'invoice.paid_out')}</th>
                            <th className="py-2 text-start">{t('invoice.issued')}</th>
                            <th className="py-2" />
                        </tr>
                    </thead>
                    <tbody>
                        {invoices.data.map((i) => (
                            <tr key={i.number} className="border-t border-line">
                                <td className="py-2 font-mono">{i.number}</td>
                                {service && <td className="py-2">{i.period}</td>}
                                <td className="py-2 text-end">{formatUsd(i.total, locale)}</td>
                                <td className="py-2 text-muted">{new Date(i.issuedAt).toLocaleDateString(locale)}</td>
                                <td className="py-2 text-end">
                                    <a href={i.href} target="_blank" rel="noreferrer" className="btn-outline-pill px-3 py-1">
                                        {t('invoice.open')}
                                    </a>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
            {invoices.lastPage > 1 && (
                <div className="mt-4 flex justify-center gap-2">
                    {Array.from({ length: invoices.lastPage }, (_, i) => i + 1).map((p) => (
                        <button key={p} type="button" onClick={() => router.get(window.location.pathname, { page: p })} className={`h-8 w-8 rounded-md text-sm ${p === invoices.page ? 'bg-primary text-white' : 'bg-canvas'}`}>
                            {p}
                        </button>
                    ))}
                </div>
            )}
        </DashboardLayout>
    );
}
