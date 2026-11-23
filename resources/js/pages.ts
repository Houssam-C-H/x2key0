import type { ResolvedComponent } from '@inertiajs/react';

const pages = import.meta.glob<{ default: ResolvedComponent }>('./Pages/**/*.tsx');

/** Resolves an Inertia component name ("Public/Home") to its lazily loaded page component. */
export async function resolvePage(name: string): Promise<ResolvedComponent> {
    const loader = pages[`./Pages/${name}.tsx`];

    if (!loader) {
        throw new Error(`Inertia page not found: ${name}`);
    }

    return (await loader()).default;
}
