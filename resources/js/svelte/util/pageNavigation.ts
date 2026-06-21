import {pageUrl} from '../../playground/urls.js';

export interface DashboardNavItem {
    key: string;
    label: string;
    href: string;
}

export function dashboardNavItems(): DashboardNavItem[] {
    return [
        {key: 'datasets', label: 'Data Browser', href: pageUrl('/datasets')},
        {key: 'controller', label: 'Controller', href: pageUrl('/pipeline-controller')},
        {key: 'graph', label: 'Neo4j Graph', href: pageUrl('/neo4j-graph-explorer')},
        {key: 'playground', label: 'Playground', href: pageUrl('/hawki-rag-playground')},
    ];
}
