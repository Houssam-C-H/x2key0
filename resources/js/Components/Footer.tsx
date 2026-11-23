import { useShared, useT } from '@/lib/i18n';

/**
 * Footer (spec 1.3 #12). Links point to /{locale}/page/{slug}; the CMS pages and
 * legal texts (written with a lawyer) arrive in M17/M19.
 */
export function Footer() {
    const t = useT();
    const { locale, app } = useShared();

    const columns: { title: string; links: [string, string][] }[] = [
        { title: 'footer.company', links: [['footer.about', 'about'], ['footer.contact', 'contact'], ['footer.affiliate', 'affiliate']] },
        { title: 'footer.support', links: [['footer.help', 'help'], ['footer.how_to_buy', 'how-to-buy'], ['footer.how_to_sell', 'how-to-sell']] },
        { title: 'footer.legal', links: [['footer.terms', 'terms'], ['footer.privacy', 'privacy'], ['footer.cookies', 'cookies']] },
        {
            title: 'footer.policies',
            links: [
                ['footer.refund', 'refund'],
                ['footer.buyer_protection', 'buyer-protection'],
                ['footer.seller_agreement', 'seller-agreement'],
                ['footer.prohibited', 'prohibited-items'],
                ['footer.ip_policy', 'ip-policy'],
            ],
        },
    ];

    return (
        <footer className="mt-16 bg-surface-dark text-white/80">
            <div className="mx-auto grid max-w-7xl gap-8 px-4 py-12 sm:grid-cols-2 lg:grid-cols-4">
                {columns.map((col) => (
                    <div key={col.title}>
                        <h3 className="mb-3 text-sm font-bold tracking-wide text-white uppercase">{t(col.title)}</h3>
                        <ul className="space-y-2 text-sm">
                            {col.links.map(([label, slug]) => (
                                <li key={slug}>
                                    <a href={`/${locale}/page/${slug}`} className="hover:text-white">
                                        {t(label)}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
            <div className="border-t border-white/10">
                <div className="mx-auto max-w-7xl space-y-2 px-4 py-6 text-xs text-white/60">
                    <p>{t('footer.disclaimer')}</p>
                    <p>
                        © {new Date().getFullYear()} {app.name}. {t('footer.rights')}
                    </p>
                </div>
            </div>
        </footer>
    );
}
