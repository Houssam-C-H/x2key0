import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Logo, LogoMark } from '@/Components/Brand/Logo';
import { Flash } from '@/Components/Flash';
import { Icon } from '@/Components/Icon';
import { useShared, useT } from '@/lib/i18n';
import { PublicLayout } from './PublicLayout';

interface Props {
    title: string;
    /** Words shown in brand red after the title ("Log in to" + "your account"). */
    accent?: string;
    subtitle?: string;
    /** Text on the artwork panel (large screens). */
    panelTitle?: string;
    panelText?: string;
    children: ReactNode;
}

/**
 * Sign-in, sign-up and related screens: the form on the start side, a brand artwork panel on the other
 * (hidden on small screens).
 */
export function AuthLayout({ title, accent, subtitle, panelTitle, panelText, children }: Props) {
    const t = useT();
    const { app } = useShared();

    return (
        <PublicLayout>
            <Head title={accent ? `${title} ${accent}` : title} />
            <div className="bg-canvas px-4 py-8 sm:py-12">
                <div className="mx-auto grid w-full max-w-6xl overflow-hidden rounded-3xl bg-white shadow-xl shadow-ink/5 ring-1 ring-line lg:grid-cols-2">
                    <div className="flex flex-col justify-center px-6 py-10 sm:px-12 lg:px-16 lg:py-14">
                        <div className="mx-auto w-full max-w-md">
                            <div className="mb-8 flex justify-center">
                                <Logo size={32} label={app.name} />
                            </div>
                            <h1 className="text-center font-display text-2xl font-bold tracking-tight sm:text-3xl">
                                {title} {accent && <span className="text-primary">{accent}</span>}
                            </h1>
                            {subtitle && <p className="mt-2 text-center text-sm text-muted">{subtitle}</p>}
                            <div className="mt-8">
                                <Flash />
                                {children}
                            </div>
                        </div>
                    </div>

                    <AuthArtwork title={panelTitle ?? t('auth.panel.title')} text={panelText ?? t('auth.panel.text')} />
                </div>
            </div>
        </PublicLayout>
    );
}

/** Brand artwork: night sky to deep red, the X2KEY mark lit from inside, facets around it, the panel text on top. */
function AuthArtwork({ title, text }: { title: string; text: string }) {
    const t = useT();

    return (
        <div className="relative hidden p-3 lg:block">
            <div
                className="relative h-full min-h-[34rem] overflow-hidden rounded-2xl text-white"
                style={{
                    backgroundImage:
                        'radial-gradient(70% 55% at 55% 62%, color-mix(in srgb, var(--color-primary) 55%, transparent), transparent 70%), linear-gradient(170deg, var(--color-surface-dark-2) 0%, var(--color-surface-dark) 45%, color-mix(in srgb, var(--color-brand-deep) 80%, black) 100%)',
                }}
            >
                {/* Faceted rings around the mark */}
                <div aria-hidden="true" className="absolute inset-0 flex items-center justify-center pt-16">
                    {[340, 270, 200].map((s, i) => (
                        <span
                            key={s}
                            className="absolute rotate-45 rounded-[2.5rem] border"
                            style={{
                                width: s,
                                height: s,
                                borderColor: `color-mix(in srgb, var(--color-brand-glow) ${18 + i * 14}%, transparent)`,
                                boxShadow: i === 2 ? '0 0 80px color-mix(in srgb, var(--color-primary) 60%, transparent), inset 0 0 60px color-mix(in srgb, var(--color-primary) 35%, transparent)' : undefined,
                            }}
                        />
                    ))}
                    <LogoMark size={150} tone="dark" className="relative" style={{ filter: 'drop-shadow(0 0 40px color-mix(in srgb, var(--color-brand-red) 65%, transparent))' }} />
                </div>

                {/* Horizon glow */}
                <div aria-hidden="true" className="absolute inset-x-0 bottom-0 h-1/3" style={{ backgroundImage: 'linear-gradient(to top, color-mix(in srgb, var(--color-brand-deep) 70%, transparent), transparent)' }} />

                <div className="relative p-8">
                    <h2 className="font-display text-2xl font-bold">{title}</h2>
                    <p className="mt-2 max-w-sm text-sm text-white/80">{text}</p>
                </div>

                <ul className="absolute inset-x-8 bottom-8 grid grid-cols-3 gap-2 text-xs">
                    {(
                        [
                            ['shield', 'home.trust.protection'],
                            ['lock', 'home.trust.payments'],
                            ['headset', 'home.trust.support'],
                        ] as const
                    ).map(([icon, key]) => (
                        <li key={key} className="flex items-center gap-1.5 rounded-lg bg-white/10 px-2.5 py-2 backdrop-blur">
                            <Icon name={icon} size={14} className="shrink-0 text-brand-glow" />
                            <span className="truncate">{t(key)}</span>
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}
