import './bootstrap';
import './playground/graph-visualization.js';

document.getElementById('neo4j-graph-refresh')?.addEventListener('click', () => {
    window.location.reload();
});
