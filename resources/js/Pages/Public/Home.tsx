import { Head, Link } from '@inertiajs/react';
import { categoryIcon, Icon } from '@/Components/Icon';
import { PublicLayout } from '@/Layouts/PublicLayout';
import { useLocalePath, useShared, useT } from '@/lib/i18n';

/** Home shell: hero with the 8 category tiles (spec 1.3 #1). Other modules arrive in M17. */
export default function Home() {
    const t = useT();
    const { categoryTypes } = useShared();
    const lp = useLocalePath();
    const tiles = categoryTypes.filter((c) => c.hero);

    return (
        <PublicLayout>
            <Head title={t('home.hero_tagline')} />

            <section className="relative overflow-hidden bg-surface-dark text-white">
                <div
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-0 opacity-60"
                    style={{
                        background:
                            'radial-gradient(60% 80% at 15% 20%, rgb(91 61 245 / .55), transparent 60%), radial-gradient(50% 70% at 85% 30%, rgb(219 39 119 / .35), transparent 60%)',
                    }}
                />
                <div className="relative mx-auto max-w-7xl px-4 pt-16 pb-12">
                    <p className="text-sm font-medium tracking-wide text-white/70 uppercase">{t('home.hero_tagline')}</p>
                    <h1 className="mt-3 max-w-3xl text-4xl leading-tight font-extrabold sm:text-6xl">{t('home.hero_title')}</h1>
                    <p className="mt-4 max-w-2xl text-base text-white/75 sm:text-lg">{t('home.hero_subtitle')}</p>

                    <ul className="mt-10 grid grid-cols-4 gap-3 sm:grid-cols-8">
                        {tiles.map((c) => (
                            <li key={c.code}>
                                <Link
                                    href={lp(`/catalog/${c.code.replaceAll('_', '-')}`)}
                                    className="flex aspect-square flex-col items-center justify-center gap-2 rounded-lg border border-white/10 bg-white/5 p-2 text-center text-xs font-semibold backdrop-blur transition hover:bg-white/15 sm:text-sm"
                                >
                                    <Icon name={categoryIcon[c.code] ?? 'box'} size={26} />
                                    {c.label}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </div>
            </section>

            <section className="mx-auto max-w-7xl px-4 py-12">
                <div className="rounded-md border-s-4 border-warning bg-warning-soft p-4 text-sm">{t('home.modules_coming')}</div>
            </section>
        </PublicLayout>
    );
}
