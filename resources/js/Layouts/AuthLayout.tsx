import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Flash } from '@/Components/Flash';
import { PublicLayout } from './PublicLayout';

/** Centered card for sign-in, sign-up and related screens. */
export function AuthLayout({ title, subtitle, children }: { title: string; subtitle?: string; children: ReactNode }) {
    return (
        <PublicLayout>
            <Head title={title} />
            <div className="bg-canvas px-4 py-12 sm:py-16">
                <div className="card mx-auto w-full max-w-md p-6 sm:p-8">
                    <h1 className="text-2xl font-bold">{title}</h1>
                    {subtitle && <p className="mt-1 text-muted">{subtitle}</p>}
                    <div className="mt-6">
                        <Flash />
                        {children}
                    </div>
                </div>
            </div>
        </PublicLayout>
    );
}
