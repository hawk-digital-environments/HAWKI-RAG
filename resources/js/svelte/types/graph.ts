export interface GraphSourceDocument {
    docId?: string;
    label?: string;
    title?: string;
    originalFilename?: string;
    sourceUrl?: string;
    markdownPreviewPath?: string;
    localPath?: string;
    markdownSnippet?: string;
    missing?: boolean;
}

export interface GraphNode {
    id: string;
    label?: string;
    type?: string;
    score?: number | null;
    highlighted?: boolean;
    properties?: Record<string, unknown>;
    source_document_ids?: string[];
    source_documents?: GraphSourceDocument[];
}

export interface GraphEdge {
    id: string;
    source: string;
    target: string;
    type?: string;
    weight?: number;
}

export interface GraphPayload {
    ok?: boolean;
    nodes?: GraphNode[];
    edges?: GraphEdge[];
    warnings?: string[];
    message?: string;
    error?: string;
}

export interface GraphSearchPayload {
    ok?: boolean;
    results?: GraphNode[];
    warnings?: string[];
    message?: string;
    error?: string;
}
