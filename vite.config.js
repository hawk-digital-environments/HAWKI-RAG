import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

const projectPath = process.env.DOCKER_PROJECT_PATH || '/';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/pipeline-dashboard.css',
                'resources/css/task-manager.css',
                'resources/css/pipeline-health-dashboard.css',
                'resources/css/datasets-dashboard.css',
                'resources/css/dashboard-dark-theme.css',
                'resources/js/app.js',
                'resources/js/pipeline-dashboard.js',
                'resources/js/task-manager.js',
                'resources/js/pipeline-health-dashboard.js',
                'resources/js/datasets-dashboard.js',
                'resources/js/neo4j-graph-dashboard.js',
                'resources/js/pipeline-controller.js',
            ],
            refresh: true,
        }),
    ],
    // Ensure built assets resolve correctly when the app is mounted below a sub-path
    // such as "/hawki-rag/" behind the reverse proxy.
    base: projectPath.endsWith('/') ? projectPath : `${projectPath}/`,
});
