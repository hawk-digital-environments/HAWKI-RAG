export function stageLogPresentation(log = {}) {
    return {text: log.text || 'No stage events yet. This stage may not run for this input.', downloadable: Boolean(log.text)};
}
