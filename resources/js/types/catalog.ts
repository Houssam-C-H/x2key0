export interface Seo {
    title: string;
    description: string;
    canonical: string;
    alternates: { locale: string; href: string }[];
    noindex: boolean;
    jsonLd: Record<string, unknown>[];
}

export interface Crumb {
    name: string;
    href: string;
}

export interface CategoryTab {
    code: string;
    label: string;
    href: string;
    count: number;
    active: boolean;
}

export interface ProductCardData {
    id: string;
    name: string;
    href: string;
    image: string | null;
    platform: string | null;
    region: { code: string; name: string; flag: string | null } | null;
    offers: number;
    priceCents: number | null;
    msrpCents: number | null;
    discountPercent: number | null;
}

export interface GameCard {
    slug: string;
    name: string;
    href: string;
    hot: boolean;
    isNew: boolean;
    count: number;
    categories: { code: string; label: string; href: string; count: number }[];
}

export interface FilterOption {
    value: string;
    label: string;
    count: number;
}

export interface FilterDef {
    key: string;
    param: string;
    type: 'facet' | 'range';
    label: string;
    unit: string | null;
    bounds?: { min: number; max: number };
    options: FilterOption[];
}

export interface GameCategoryInfo {
    title: string;
    label: string;
    game: string;
    gameHref: string;
    typeCode: string;
    notice: string | null;
}

export interface MegaMenuColumn {
    code: string;
    label: string;
    href: string;
    games: { name: string; href: string; count: number }[];
}
