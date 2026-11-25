import type { SVGProps } from 'react';

/** X2KEY line-icon set (24×24, 1.75 stroke). Paths are original. */
const paths: Record<string, string> = {
    menu: 'M4 6h16M4 12h16M4 18h16',
    search: 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14Zm9 16-4.35-4.35',
    chat: 'M5 5h14a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H10l-4 3v-3H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z',
    bell: 'M6 16V11a6 6 0 1 1 12 0v5l1.5 2h-15L6 16Zm4 3a2 2 0 0 0 4 0',
    cart: 'M3 4h2l2.2 10.2a1 1 0 0 0 1 .8h8.6a1 1 0 0 0 1-.76L20 8H6.2M9 20h.01M17 20h.01',
    store: 'M4 9l1.5-5h13L20 9M4 9v10h16V9M4 9h16M9 19v-5h6v5',
    scale: 'M12 4v16M6 20h12M5 8h14M5 8l-2.5 6a3 3 0 0 0 5 0L5 8Zm14 0-2.5 6a3 3 0 0 0 5 0L19 8Z',
    lifebuoy: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm0 5a4 4 0 1 0 0 8 4 4 0 0 0 0-8ZM5.6 5.6l3.6 3.6m5.6 5.6 3.6 3.6m0-12.8-3.6 3.6m-5.6 5.6-3.6 3.6',
    receipt: 'M6 3h12v18l-3-2-3 2-3-2-3 2V3Zm3 5h6M9 12h6M9 16h3',
    ticket: 'M4 7a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v3a2 2 0 0 0 0 4v3a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-3a2 2 0 0 0 0-4V7Zm10-1v12',
    share: 'M8 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm14-6a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm0 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0ZM7.7 10.6l8.6-3.2m-8.6 6 8.6 3.2',
    vault: 'M4 5h16v14H4V5Zm8 4a3 3 0 1 0 0 6 3 3 0 0 0 0-6Zm0 3h.01M7 19v2m10-2v2',
    gear: 'M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6Zm7.4 3a7.4 7.4 0 0 0-.1-1.2l2-1.5-2-3.5-2.4 1a7.5 7.5 0 0 0-2-1.2L14.5 3h-5l-.4 2.6a7.5 7.5 0 0 0-2 1.2l-2.4-1-2 3.5 2 1.5a7.4 7.4 0 0 0 0 2.4l-2 1.5 2 3.5 2.4-1a7.5 7.5 0 0 0 2 1.2l.4 2.6h5l.4-2.6a7.5 7.5 0 0 0 2-1.2l2.4 1 2-3.5-2-1.5c.07-.4.1-.8.1-1.2Z',
    chevron: 'm9 6 6 6-6 6',
    home: 'M4 11 12 4l8 7v9h-5v-6H9v6H4v-9Z',
    globe: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm-9 9h18M12 3c2.5 2.6 3.7 5.6 3.7 9S14.5 18.4 12 21c-2.5-2.6-3.7-5.6-3.7-9S9.5 5.6 12 3Z',
    x: 'M6 6l12 12M18 6 6 18',
    user: 'M12 4a4 4 0 1 0 0 8 4 4 0 0 0 0-8ZM4 20a8 8 0 0 1 16 0',
    coin: 'M12 4c4.4 0 8 1.6 8 3.5S16.4 11 12 11 4 9.4 4 7.5 7.6 4 12 4Zm-8 3.5v4.5C4 13.9 7.6 15.5 12 15.5s8-1.6 8-3.5V7.5M4 12v4.5C4 18.4 7.6 20 12 20s8-1.6 8-3.5V12',
    bolt: 'M13 3 5 13h6l-1 8 8-10h-6l1-8Z',
    box: 'M4 7.5 12 4l8 3.5v9L12 20l-8-3.5v-9Zm0 0L12 11l8-3.5M12 11v9',
    trophy: 'M8 4h8v5a4 4 0 0 1-8 0V4Zm0 2H5v1a3 3 0 0 0 3 3m8-4h3v1a3 3 0 0 1-3 3m-4 3v4m-3 3h6m-5-3h4',
    user_check: 'M10 4a4 4 0 1 0 0 8 4 4 0 0 0 0-8ZM3 20a7 7 0 0 1 11-5.7M15 18l2 2 4-4',
    gamepad: 'M7 9h4M9 7v4m6-1h.01M18 12h.01M6 6h12a3 3 0 0 1 3 3l1 6a2.5 2.5 0 0 1-4.4 2L16 15H8l-1.6 2A2.5 2.5 0 0 1 2 15l1-6a3 3 0 0 1 3-3Z',
    gift: 'M4 10h16v3H4v-3Zm1 3h14v7H5v-7Zm7-3v10M12 10c-1.5-3-5-4-5-1.5S10 10 12 10Zm0 0c1.5-3 5-4 5-1.5S14 10 12 10Z',
    tag: 'M4 4h7l9 9-7 7-9-9V4Zm4 4h.01',
    shield: 'M12 3 4.5 6v5.5c0 4.6 3.2 8.4 7.5 9.5 4.3-1.1 7.5-4.9 7.5-9.5V6L12 3Zm-3 9 2 2 4-4',
    lock: 'M6 11h12v9H6v-9Zm2 0V8a4 4 0 0 1 8 0v3m-4 4v2',
    clock: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm0 4v5l3 2',
    star: 'm12 3.5 2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8L3.5 9.7l5.9-.9L12 3.5Z',
    headset: 'M4 14v-2a8 8 0 0 1 16 0v2M4 14h3v5H5a1 1 0 0 1-1-1v-4Zm16 0h-3v5h2a1 1 0 0 0 1-1v-4Zm-3 5c0 1-1.5 2-5 2',
    arrow: 'M5 12h14m-6-6 6 6-6 6',
    check: 'm5 12 4.5 4.5L19 7',
    sparkle: 'M12 3v4m0 10v4M3 12h4m10 0h4M6.3 6.3l2.8 2.8m5.8 5.8 2.8 2.8m0-11.4-2.8 2.8m-5.8 5.8-2.8 2.8',
    wallet: 'M4 7h14a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a1 1 0 0 1-1-1V7Zm0 0 11-3v3m1 7h.01',
    repeat: 'M17 3l3 3-3 3M4 11V9a3 3 0 0 1 3-3h13M7 21l-3-3 3-3m13-2v2a3 3 0 0 1-3 3H4',
    monitor: 'M3 5h18v11H3V5Zm6 15h6m-3-4v4',
    puzzle: 'M5 8h3a2 2 0 1 1 4 0h3v3a2 2 0 1 1 0 4v4H5v-4a2 2 0 1 0 0-4V8Z',
    card: 'M3 6h18v12H3V6Zm0 4h18M7 15h3',
    mail: 'M3 6h18v12H3V6Zm0 0 9 7 9-7',
    bag: 'M5 8h14l-1 12H6L5 8Zm4 0V6a3 3 0 0 1 6 0v2',
    phone: 'M8 3h8a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Zm3 15h2',
    copy: 'M9 9h11v11H9V9Zm-4 6H4V4h11v1',
    grid: 'M4 4h6v6H4V4Zm10 0h6v6h-6V4ZM4 14h6v6H4v-6Zm10 0h6v6h-6v-6Z',
    fire: 'M12 21a7 7 0 0 0 7-7c0-4-3-6-4-9-1 3-3 3-3 3s0-3-2-5c0 4-5 6-5 11a7 7 0 0 0 7 7Zm0 0a3 3 0 0 0 3-3c0-2-2-3-2-4-1 1-2 1-2 1s-2 1-2 3a3 3 0 0 0 3 3Z',
};

export type IconName = keyof typeof paths;

interface IconProps extends SVGProps<SVGSVGElement> {
    name: string;
    size?: number;
}

export function Icon({ name, size = 20, className, ...rest }: IconProps) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.75}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            className={className}
            {...rest}
        >
            <path d={paths[name] ?? paths.box} />
        </svg>
    );
}

/** Icon per category type code, for tiles and chips. */
export const categoryIcon: Record<string, string> = {
    currency: 'coin',
    top_up: 'bolt',
    items: 'box',
    boosting: 'trophy',
    accounts: 'user_check',
    video_games: 'gamepad',
    gift_cards: 'gift',
    coupons_savings: 'tag',
    subscriptions: 'repeat',
    software_apps: 'monitor',
    game_dlc: 'puzzle',
    gaming_ecards: 'card',
    egift_cards: 'mail',
    retail_gift_cards: 'bag',
    mobile_recharges: 'phone',
};
