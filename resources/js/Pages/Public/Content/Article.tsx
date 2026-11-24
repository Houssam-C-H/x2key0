import { Link } from '@inertiajs/react';
import { SeoHead } from '@/Components/Catalog/SeoHead';
import { PublicLayout } from '@/Layouts/PublicLayout';
import { useShared, useT } from '@/lib/i18n';
import type { Seo } from '@/types/catalog';

interface Props {
    kind: 'news' | 'help' | 'page';
    article: {
        title: string;
        html: string;
        publishedAt?: string | null;
        updatedAt?: string;
        cover?: string | null;
        section?: string;
        game?: { name: string; href: string } | null;
    };
    related: { title: string; href: string; current?: boolean; publishedAt?: string | null }[];
    seo: Seo;
}

/**
 * News article, help article or CMS page (spec Part 20). The HTML comes from Markdown rendered on the server with raw
 * HTML stripped and unsafe links removed (ContentRenderer), so it is safe to insert.
 */
export default function ContentArticle({ kind, article, related, seo }: Props) {
    const t = useT();
    const { locale } = useShared();
    const back = kind === 'news' ? { href: '/' + locale + '/news', label: t('content.news.title') } : kind === 'help' ? { href: '/' + locale + '/help', label: t('content.help.title') } : null;

    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <div className="mx-auto grid max-w-6xl gap-8 px-4 py-10 lg:grid-cols-[1fr_280px]">
                <article className="min-w-0">
                    {back && (
                        <Link href={back.href} className="text-sm text-primary">
                            ‹ {back.label}
                        </Link>
                    )}
                    <h1 className="mt-2 text-3xl font-extrabold">{article.title}</h1>
                    <p className="mt-1 text-sm text-muted">
                        {article.publishedAt && new Date(article.publishedAt).toLocaleDateString(locale)}
                        {article.updatedAt && t('content.updated', { date: new Date(article.updatedAt).toLocaleDateString(locale) })}
                        {article.section && t('content.help.section.' + article.section)}
                        {article.game && (
                            <>
                                {' · '}
                                <Link href={article.game.href} className="text-primary">
                                    {article.game.name}
                                </Link>
                            </>
                        )}
                    </p>
                    {article.cover && <img src={article.cover} alt="" className="mt-5 w-full rounded-lg" />}
                    <div className="prose-content mt-6 space-y-4 leading-relaxed [&_a]:text-primary [&_a]:underline [&_blockquote]:border-s-4 [&_blockquote]:border-warning [&_blockquote]:bg-warning-soft [&_blockquote]:p-3 [&_h2]:text-xl [&_h2]:font-bold [&_h3]:font-bold [&_li]:ms-5 [&_ol]:list-decimal [&_ul]:list-disc" dangerouslySetInnerHTML={{ __html: article.html }} />
                </article>
                {related.length > 0 && (
                    <aside className="space-y-2">
                        <h2 className="font-bold">{kind === 'help' ? t('content.help.in_section') : t('content.news.more')}</h2>
                        <ul className="space-y-2 text-sm">
                            {related.map((r) => (
                                <li key={r.href}>
                                    <Link href={r.href} className={r.current ? 'font-semibold text-primary' : 'hover:text-primary'} aria-current={r.current ? 'page' : undefined}>
                                        {r.title}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </aside>
                )}
            </div>
        </PublicLayout>
    );
}
