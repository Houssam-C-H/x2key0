import { Link } from '@inertiajs/react';
import { SeoHead } from '@/Components/Catalog/SeoHead';
import { PublicLayout } from '@/Layouts/PublicLayout';
import { useT } from '@/lib/i18n';
import type { Seo } from '@/types/catalog';

/** Help center (spec Part 20 `/help`): sections with their articles, and a way to open a ticket. */
export default function HelpIndex({ sections, seo }: { sections: { code: string; articles: { title: string; href: string }[] }[]; seo: Seo }) {
    const t = useT();

    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <div className="mx-auto max-w-5xl px-4 py-10">
                <h1 className="text-3xl font-extrabold">{t('content.help.title')}</h1>
                <p className="mt-2 text-muted">{t('content.help.description')}</p>
                {sections.length === 0 ? (
                    <p className="mt-6 text-muted">{t('content.help.empty')}</p>
                ) : (
                    <div className="mt-8 grid gap-5 sm:grid-cols-2">
                        {sections.map((s) => (
                            <section key={s.code} className="card p-5">
                                <h2 className="mb-3 font-bold">{t('content.help.section.' + s.code)}</h2>
                                <ul className="space-y-2 text-sm">
                                    {s.articles.map((a) => (
                                        <li key={a.href}>
                                            <Link href={a.href} className="hover:text-primary">
                                                {a.title}
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        ))}
                    </div>
                )}
                <p className="mt-8 rounded-md bg-primary-soft p-4 text-sm">
                    {t('content.help.ticket')}{' '}
                    <a href="/tickets" className="font-semibold text-primary">
                        {t('content.help.ticket_cta')}
                    </a>
                </p>
            </div>
        </PublicLayout>
    );
}
