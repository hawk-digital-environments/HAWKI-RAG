import './bootstrap';
import './playground/logs.js';
import './playground/query.js';
import './playground/ingestion.js';
import './playground/graph-visualization.js';

document.getElementById('playground-refresh')?.addEventListener('click', () => {
    window.location.reload();
});
