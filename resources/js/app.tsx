import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot, hydrateRoot } from 'react-dom/client';
import { resolvePage } from './pages';

createInertiaApp({
    title: (title) => (title ? `${title} · X2KEY` : 'X2KEY'),
    resolve: resolvePage,
    setup({ el, App, props }) {
        if (el.hasChildNodes()) {
            hydrateRoot(el, <App {...props} />);
        } else {
            createRoot(el).render(<App {...props} />);
        }
    },
    progress: {
        color: '#5b3df5',
    },
});
