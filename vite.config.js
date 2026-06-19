import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { svelte } from '@sveltejs/vite-plugin-svelte';

const projectPath = process.env.DOCKER_PROJECT_PATH || '/';

export default defineConfig({
    plugins: [
        svelte(),
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/pipeline-health-dashboard.css',
                'resources/css/datasets-dashboard.css',
                'resources/css/dashboard-dark-theme.css',
                'resources/css/hawki-rag-theme.css',
                'resources/js/pipeline-health-dashboard.js',
                'resources/js/datasets-dashboard.js',
                'resources/js/hawki-rag-playground.js',
                'resources/js/hawki-rag-experience.js',
                'resources/js/neo4j-graph-dashboard.js',
                'resources/js/pipeline-controller.js',
                'resources/js/settings-dashboard.js',
            ],
            refresh: true,
        }),
    ],
    // Ensure built assets resolve correctly when the app is mounted below a sub-path
    // such as "/hawki-rag/" behind the reverse proxy.
    base: projectPath.endsWith('/') ? projectPath : `${projectPath}/`,
    build: {
        // ELK is a single upstream graph-layout bundle loaded only by graph pages.
        // Keep the limit close to its current size so unrelated bundle growth still warns.
        chunkSizeWarningLimit: 1500,
        rollupOptions: {
            output: {
                manualChunks(id) {
                    const normalizedId = id.replaceAll('\\', '/');

                    if (normalizedId.includes('/node_modules/elkjs/')) {
                        return 'graph-layout-elk';
                    }

                    if (normalizedId.includes('/node_modules/cytoscape-elk/')) {
                        return 'graph-layout-adapter';
                    }

                    if (normalizedId.includes('/node_modules/cytoscape-cose-bilkent/')) {
                        return 'graph-layout-cose';
                    }

                    if (normalizedId.includes('/node_modules/cytoscape/')) {
                        return 'graph-engine';
                    }
                },
            },
        },
    },
});
