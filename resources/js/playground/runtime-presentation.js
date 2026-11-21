export function runtimePresentation(runtime = {}) {
    return {
        engine: 'RAGAnything',
        queryModel: runtime.query_model || 'Not configured',
        embeddingModel: runtime.embedding_model || 'Not configured',
        latestDataset: runtime.latest_dataset || 'No datasets yet',
    };
}
