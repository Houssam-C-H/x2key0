import { router } from '@inertiajs/react';
import { useShared, useT } from '@/lib/i18n';

/** Month selector shared by the statements (spec 1.7: "month selector"). */
export function MonthPicker({ path, month, months, extra = {} }: { path: string; month: string; months: string[]; extra?: Record<string, string | undefined> }) {
    const t = useT();
    const { locale } = useShared();
    const label = (m: string) => new Date(`${m}-01T12:00:00`).toLocaleDateString(locale, { year: 'numeric', month: 'long' });

    return (
        <label className="flex items-center gap-2 text-sm">
            <span className="font-medium">{t('statement.month')}</span>
            <select className="field w-auto py-1.5" value={month} onChange={(e) => router.get(path, { ...extra, month: e.target.value }, { preserveScroll: true, preserveState: true })}>
                {months.map((m) => (
                    <option key={m} value={m}>
                        {label(m)}
                    </option>
                ))}
            </select>
        </label>
    );
}
