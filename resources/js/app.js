import './bootstrap';
import './health-gate.js';
import './playground/logs.js';
import './playground/query.js';

if (document.getElementById('neo4j-graph-canvas')) {
    import('./playground/graph-visualization.js').catch((error) => {
        console.error('Failed to load graph visualization.', error);
    });
}
