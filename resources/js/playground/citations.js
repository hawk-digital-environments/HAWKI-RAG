/**
 * Split a generated answer into plain text and source references.
 *
 * The model-facing API intentionally uses stable `[Source N]` markers. The
 * browser turns those markers into links after it receives the ordered hits.
 *
 * @param {string} answer
 * @returns {Array<
 *   {kind: 'text', text: string} |
 *   {kind: 'citation', sourceNumber: number, sourceIndex: number}
 * >}
 */
export function parseAnswerCitations(answer) {
    const text = String(answer || '');
    const citationPattern = /\[\s*Source\s+(\d+)\s*\]/gi;
    const parts = [];
    let cursor = 0;

    for (const match of text.matchAll(citationPattern)) {
        const offset = match.index ?? 0;
        if (offset > cursor) {
            parts.push({kind: 'text', text: text.slice(cursor, offset)});
        }

        const sourceNumber = Number.parseInt(match[1], 10);
        parts.push({
            kind: 'citation',
            sourceNumber,
            sourceIndex: sourceNumber - 1,
        });
        cursor = offset + match[0].length;
    }

    if (cursor < text.length) {
        parts.push({kind: 'text', text: text.slice(cursor)});
    }

    return parts.length ? parts : [{kind: 'text', text}];
}

/**
 * Return the stable, privacy-safe label shown for a generated-answer citation.
 *
 * @param {number} sourceNumber
 * @returns {string}
 */
export function answerCitationLabel(sourceNumber) {
    return `[Reference ${sourceNumber}]`;
}
