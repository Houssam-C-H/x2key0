import type { ReactNode } from 'react';
import { BookmarkPrompt } from '@/Components/BookmarkPrompt';
import { CookieBanner } from '@/Components/CookieBanner';
import { Footer } from '@/Components/Footer';
import { Header } from '@/Components/Header';
import { useShared } from '@/lib/i18n';

/** Public shell (spec 21.1): header, mega menu, content, footer, cookie banner. */
export function PublicLayout({ children }: { children: ReactNode }) {
    const { dir, locale } = useShared();

    return (
        <div dir={dir} lang={locale} className="flex min-h-screen flex-col">
            <Header />
            <BookmarkPrompt />
            <main className="flex-1">{children}</main>
            <Footer />
            <CookieBanner />
        </div>
    );
}
