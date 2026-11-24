import { Link } from '@inertiajs/react';
import { SeoHead } from '@/Components/Catalog/SeoHead';
import { PublicLayout } from '@/Layouts/PublicLayout';
import { useShared, useT } from '@/lib/i18n';
import type { Seo } from '@/types/catalog';

interface Article {
    slug: string;
    title: string;
    excerpt: string | null;
    cover: string | null;
    href: string;
    publishedAt: string | null;
}

/** Game News list (spec Part 20 `/news`). */
export default function NewsIndex({ articles, pagination, seo }: { articles: Article[]; pagination: { page: number; last: number }; seo: Seo }) {
    const t = useT();
    const { locale } = useShared();

    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <div className="mx-auto max-w-7xl px-4 py-10">
                <h1 className="mb-6 text-3xl font-extrabold">{t('content.news.title')}</h1>
                {articles.length === 0 ? (
                    <p className="text-muted">{t('content.news.empty')}</p>
                ) : (
                    <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        {articles.map((a) => (
                            <li key={a.slug} className="card overflow-hidden">
                                <Link href={a.href} className="block">
                                    {a.cover ? <img src={a.cover} alt="" loading="lazy" className="aspect-[16/9] w-full object-cover" /> : <div className="aspect-[16/9] bg-gradient-to-br from-primary/80 to-surface-dark-2" />}
                                    <div className="space-y-1 p-4">
                                        <h2 className="line-clamp-2 font-bold">{a.title}</h2>
                                        {a.publishedAt && <p className="text-xs font-semibold text-danger">{new Date(a.publishedAt).toLocaleDateString(locale)}</p>}
                                        {a.excerpt && <p className="line-clamp-3 text-sm text-muted">{a.excerpt}</p>}
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
                {pagination.last > 1 && (
                    <nav className="mt-8 flex justify-center gap-2" aria-label={t('content.pages')}>
                        {Array.from({ length: pagination.last }, (_, i) => i + 1).map((p) => (
                            <Link key={p} href={'/' + locale + '/news?page=' + p} aria-current={p === pagination.page ? 'page' : undefined} className={'rounded-md border px-3 py-1 text-sm ' + (p === pagination.page ? 'border-primary bg-primary-soft text-primary' : 'border-line')}>
                                {p}
                            </Link>
                        ))}
                    </nav>
                )}
            </div>
        </PublicLayout>
    );
}
