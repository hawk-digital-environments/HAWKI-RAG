import {pageUrl} from '../../playground/urls.js';

export interface DashboardNavItem {
    key: string;
    label: string;
    href: string;
}

export function dashboardNavItems(): DashboardNavItem[] {
    return [
        {key: 'heaps', label: 'Heap Browser', href: pageUrl('/heaps')},
        {key: 'controller', label: 'Controller', href: pageUrl('/pipeline-controller')},
        {key: 'graph', label: 'Neo4j Graph', href: pageUrl('/neo4j-graph-explorer')},
        {key: 'search', label: 'Search Console', href: pageUrl('/hawki-rag-search')},
    ];
}
