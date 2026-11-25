import { Logo } from '@/Components/Brand/Logo';
import { useShared, useT } from '@/lib/i18n';

/**
 * Footer (spec 1.3 #12). Links point to /{locale}/page/{slug}; the CMS pages and
 * legal texts (written with a lawyer) arrive in M17/M19.
 */
/** Built-in links that are sections of the site, not CMS pages. */
const SECTION_PATHS: Record<string, string> = { help: '/help', affiliate: '/affiliate', news: '/news' };

export function Footer() {
    const t = useT();
    const { locale, app, footer } = useShared();

    const columns: { title: string; links: [string, string][] }[] = [
        { title: 'footer.company', links: [['footer.about', 'about'], ['footer.contact', 'contact'], ['footer.news', 'news'], ['footer.affiliate', 'affiliate']] },
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

    // CMS pages (spec 1.3 #12) replace a column's built-in links once staff have published pages for it.
    const cms = footer?.columns ?? {};
    const keys: Record<string, string> = { 'footer.company': 'company', 'footer.support': 'support', 'footer.legal': 'legal', 'footer.policies': 'policies' };
    const shown = columns.map((col) => {
        const pages = cms[keys[col.title] ?? ''] ?? [];
        return { title: col.title, links: pages.length > 0 ? pages.map((p) => ({ label: p.label, href: p.href })) : col.links.map(([label, slug]) => ({ label: t(label), href: '/' + locale + (SECTION_PATHS[slug] ?? '/page/' + slug) })) };
    });
    const seoLinks = footer?.seoLinks ?? [];

    return (
        <footer className="mt-16 bg-surface-dark text-white/80">
            <div className="mx-auto max-w-7xl px-4 pt-10">
                <Logo tone="dark" size={28} label={app.name} />
            </div>
            <div className="mx-auto grid max-w-7xl gap-8 px-4 py-10 sm:grid-cols-2 lg:grid-cols-4">
                {shown.map((col) => (
                    <div key={col.title}>
                        <h3 className="mb-3 text-sm font-bold tracking-wide text-white uppercase">{t(col.title)}</h3>
                        <ul className="space-y-2 text-sm">
                            {col.links.map((l) => (
                                <li key={l.href}>
                                    <a href={l.href} className="hover:text-white">
                                        {l.label}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
            <div className="border-t border-white/10">
                <div className="mx-auto max-w-7xl space-y-2 px-4 py-6 text-xs text-white/60">
                    {seoLinks.length > 0 && (
                        <nav aria-label={t('footer.popular')} className="flex flex-wrap gap-x-4 gap-y-1">
                            {seoLinks.map((l) => (
                                <a key={l.href} href={l.href} className="hover:text-white">
                                    {l.label}
                                </a>
                            ))}
                        </nav>
                    )}
                    <p>{t('footer.disclaimer')}</p>
                    <p>
                        © {new Date().getFullYear()} {app.name}. {t('footer.rights')}
                    </p>
                </div>
            </div>
        </footer>
    );
}
