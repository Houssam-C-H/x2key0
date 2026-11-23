import { Head } from '@inertiajs/react';
import type { Seo } from '@/types/catalog';

/**
 * Title, description, canonical, hreflang alternates, robots and JSON-LD (spec Part 22).
 * JSON-LD is built server-side from our own data and serialised with JSON.stringify (no user HTML).
 */
export function SeoHead({ seo }: { seo: Seo }) {
    return (
        <Head title={seo.title}>
            <meta head-key="description" name="description" content={seo.description} />
            <link head-key="canonical" rel="canonical" href={seo.canonical} />
            {seo.alternates.map((a) => (
                <link key={a.locale} head-key={`alt-${a.locale}`} rel="alternate" hrefLang={a.locale} href={a.href} />
            ))}
            {seo.noindex && <meta head-key="robots" name="robots" content="noindex,follow" />}
            {seo.jsonLd.map((data, i) => (
                <script key={i} head-key={`ld-${i}`} type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(data).replace(/</g, '\\u003c') }} />
            ))}
        </Head>
    );
}
