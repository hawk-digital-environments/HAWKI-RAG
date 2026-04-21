import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

const projectPath = process.env.DOCKER_PROJECT_PATH || '/';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
    // Ensure built assets resolve correctly when the app is mounted below a sub-path
    // such as "/hawki-rag/" behind the reverse proxy.
    base: projectPath.endsWith('/') ? projectPath : `${projectPath}/`,
});
