import { DashboardLayout } from '@/Layouts/DashboardLayout';
import { useT } from '@/lib/i18n';
import type { ActivePage } from '@/types';

/** Empty dashboard page shown until the milestone that builds the real screen ships. */
export default function Placeholder({ page }: { page: ActivePage | null }) {
    const t = useT();
    const title = page?.label ?? t('placeholder.title');

    return (
        <DashboardLayout title={title}>
            <div className="flex flex-col items-center justify-center gap-3 py-16 text-center">
                <span className="flex h-14 w-14 items-center justify-center rounded-full bg-canvas text-2xl font-bold text-muted">!</span>
                <p className="font-semibold">{t('placeholder.title')}</p>
                {page && <p className="text-muted">{t('placeholder.body', { milestone: page.milestone })}</p>}
            </div>
        </DashboardLayout>
    );
}
