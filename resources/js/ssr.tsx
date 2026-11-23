import { createInertiaApp } from '@inertiajs/react';
import createServer from '@inertiajs/react/server';
import { renderToString } from 'react-dom/server';
import { resolvePage } from './pages';

createServer((page) =>
    createInertiaApp({
        page,
        render: renderToString,
        title: (title) => (title ? `${title} · X2KEY` : 'X2KEY'),
        resolve: resolvePage,
        setup: ({ App, props }) => <App {...props} />,
    }),
);
