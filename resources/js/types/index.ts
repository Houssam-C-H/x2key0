export type Dir = 'ltr' | 'rtl';

export interface LocaleOption {
    code: string;
    name: string;
    dir: Dir;
}

export interface CategoryType {
    code: string;
    label: string;
    hero: boolean;
}

export interface AuthUser {
    id: number;
    publicId: string;
    username: string;
    name: string;
    email: string;
    emailVerified: boolean;
    isStaff: boolean;
    seller: { slug: string; status: string; opensSellerCenter: boolean } | null;
}

/** Props shared on every page by HandleInertiaRequests. */
export interface SharedProps {
    app: { name: string };
    locale: string;
    dir: Dir;
    locales: LocaleOption[];
    currency: string;
    /** Display rate for approximate prices (ADR-036); null = USD. */
    fx?: { currency: string; rate: number } | null;
    currencies: string[];
    translations: Record<string, string>;
    categoryTypes: CategoryType[];
    auth: { user: AuthUser | null };
    flash: { success: string | null; error: string | null };
    menu: DashboardMenu | null;
    stockAlert: { count: number; titles: string[] } | null;
    storageDisabled?: boolean;
    inbox: { unread: number } | null;
    chat: { unread: number } | null;
    footer: { columns: Record<string, { label: string; href: string }[]>; seoLinks: { label: string; href: string }[] } | null;
    bookmarkPrompt: boolean;
    megaMenu: { code: string; label: string; href: string; games: { name: string; href: string; count: number; icon?: string | null }[] }[];
    errors: Record<string, string>;
    [key: string]: unknown;
}

export interface MenuLeaf {
    key: string;
    label: string;
    href: string;
    active: boolean;
}

export interface MenuBranch {
    key: string;
    label: string;
    open: boolean;
    children: MenuNode[];
}

export type MenuNode = MenuLeaf | MenuBranch;

export interface MenuGroup {
    key: string;
    label: string;
    icon: string;
    audience: 'all' | 'seller';
    open: boolean;
    children: MenuNode[];
}

export interface ActivePage {
    key: string;
    label: string;
    milestone: string;
    group: string | null;
}

export interface DashboardMenu {
    groups: MenuGroup[];
    active: ActivePage | null;
}

export function isBranch(node: MenuNode): node is MenuBranch {
    return 'children' in node;
}
