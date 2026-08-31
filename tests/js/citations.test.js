import assert from 'node:assert/strict';
import test from 'node:test';

import {
    answerCitationLabel,
    parseAnswerCitations,
} from '../../resources/js/playground/citations.js';

test('splits source markers from generated answer text', () => {
    assert.deepEqual(
        parseAnswerCitations('First claim [Source 1]. Second claim [Source 2].'),
        [
            {kind: 'text', text: 'First claim '},
            {kind: 'citation', sourceNumber: 1, sourceIndex: 0},
            {kind: 'text', text: '. Second claim '},
            {kind: 'citation', sourceNumber: 2, sourceIndex: 1},
            {kind: 'text', text: '.'},
        ],
    );
});

test('leaves an answer without citations as one text part', () => {
    assert.deepEqual(parseAnswerCitations('No citation.'), [
        {kind: 'text', text: 'No citation.'},
    ]);
});

test('uses a reference number without exposing document metadata', () => {
    assert.equal(answerCitationLabel(1), '[Reference 1]');
});
