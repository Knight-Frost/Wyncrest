import { defineConfig, loadEnv } from 'vite';
import path from 'path';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

// Backend origin for the dev proxy. Override with VITE_API_PROXY if the Laravel
// server runs somewhere other than http://localhost:8000.
const API_PROXY_TARGET = process.env.VITE_API_PROXY || 'http://localhost:8000';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');

    // Brand values for index.html — keep defaults in sync with src/config/brand.ts
    // so the document <title>/<meta> are driven by the same env as the SPA.
    const brand = {
        name: env.VITE_APP_NAME || 'Wyncrest',
        descriptor: env.VITE_BRAND_DESCRIPTOR || 'Property Platform',
        tagline: env.VITE_BRAND_TAGLINE || 'A higher standard for modern renting.',
        short: env.VITE_BRAND_SHORT_NAME || env.VITE_APP_NAME || 'Wyncrest',
    };

    return {
        plugins: [
            // The React and Tailwind plugins are both required for Make, even if
            // Tailwind is not being actively used – do not remove them
            react(),
            tailwindcss(),
            {
                // Inject brand strings into index.html so page title/metadata are
                // env-driven (no hardcoded product name in the HTML shell).
                name: 'html-brand-vars',
                transformIndexHtml(html) {
                    return html
                        .replace(/%BRAND_NAME%/g, brand.name)
                        .replace(/%BRAND_DESCRIPTOR%/g, brand.descriptor)
                        .replace(/%BRAND_TAGLINE%/g, brand.tagline)
                        .replace(/%BRAND_SHORT%/g, brand.short);
                },
            },
        ],
        resolve: {
            alias: {
                // Alias @ to the src directory
                '@': path.resolve(__dirname, './src'),
            },
        },
        server: {
            port: 5173,
            // Proxy API calls to the Laravel backend during development so the SPA
            // can use same-origin relative URLs (no CORS in dev).
            proxy: {
                '/api': { target: API_PROXY_TARGET, changeOrigin: true },
                // The admin console's cookie session primes its CSRF token here;
                // proxy it so the SPA can call it same-origin (sets the XSRF-TOKEN
                // + session cookies for localhost, shared across the dev ports).
                '/sanctum': { target: API_PROXY_TARGET, changeOrigin: true },
                // Serve uploaded/seeded media (public disk) through the backend so
                // relative /storage URLs resolve in dev exactly as they do in prod.
                '/storage': { target: API_PROXY_TARGET, changeOrigin: true },
            },
        },
        // File types to support raw imports. Never add .css, .tsx, or .ts files to this.
        assetsInclude: ['**/*.svg', '**/*.csv'],
    };
});
