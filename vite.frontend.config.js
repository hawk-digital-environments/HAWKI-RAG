import {defineConfig} from 'vite';
import {svelte} from '@sveltejs/vite-plugin-svelte';
import {resolve} from 'node:path';

const prefix = (process.env.DOCKER_PROJECT_PATH || '').replace(/^\/+|\/+$/g, '');
export default defineConfig({
    root: 'frontend',
    base: `/${prefix ? `${prefix}/` : ''}`,
    plugins: [svelte()],
    publicDir: false,
    build: {outDir: resolve('public/frontend'), emptyOutDir: true, chunkSizeWarningLimit: 1500},
});
