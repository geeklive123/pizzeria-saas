import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { local } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/storefront.css',
                'resources/js/storefront.js',
            ],
            refresh: true,
            fonts: [
                local('Instrument Sans', {
                    optimizedFallbacks: false,
                    variants: [
                        { weight: 400, src: 'resources/fonts/instrument-sans-400-normal-DRC__1Mx.woff2' },
                        { weight: 400, src: 'resources/fonts/instrument-sans-400-normal-D1W7dsQl.woff' },
                        { weight: 500, src: 'resources/fonts/instrument-sans-500-normal-Dk9ku72i.woff2' },
                        { weight: 500, src: 'resources/fonts/instrument-sans-500-normal-Z6ESRlEs.woff' },
                        { weight: 600, src: 'resources/fonts/instrument-sans-600-normal-B7fBEWYG.woff2' },
                        { weight: 600, src: 'resources/fonts/instrument-sans-600-normal-B9e8oLYv.woff' },
                    ],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
