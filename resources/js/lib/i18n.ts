import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types';

export type Translate = (key: string, replace?: Record<string, string | number>) => string;

/**
 * Translates with the strings shared by the server (lang/{locale}.json, English fallback).
 * `:name` placeholders are replaced, matching Laravel's __() syntax.
 */
export function useT(): Translate {
    const { translations } = usePage<SharedProps>().props;

    return (key, replace = {}) => {
        let value = translations[key] ?? key;

        for (const [name, replacement] of Object.entries(replace)) {
            value = value.replaceAll(`:${name}`, String(replacement));
        }

        return value;
    };
}

export function useShared(): SharedProps {
    return usePage<SharedProps>().props;
}

/** Prefixes a public path with the current locale: lp('/games') → '/fr/games'. */
export function useLocalePath(): (path?: string) => string {
    const { locale } = useShared();

    return (path = '') => `/${locale}${path === '/' ? '' : path}`;
}
