import test from 'node:test';
import assert from 'node:assert/strict';
import {reconcileStageCards, resetStageCards} from '../../resources/js/playground/stage-card-renderer.js';

function fixture() {
    const container = {children: [], replaceChildren() {this.children = [];}, appendChild(card) {this.children.push(card);}};
    const create = (name, stage) => ({name, stage, open: true, scrollTop: 123, log: 'previous events', updates: 0, refreshes: 0,
        updateStage(next) {this.stage = next; this.updates++;},
        refreshStageLogs() {this.refreshes++;},
        remove() {container.children = container.children.filter(c => c !== this);},
    });
    return {container, create};
}

test('polling retains open log panels, content and scroll without rebuilding cards', () => {
    const {container, create} = fixture();
    const entries = [['scrape', {status: 'completed'}], ['ingest', {status: 'running'}]];
    reconcileStageCards(container, 'task-a', entries, create);
    const card = container.children[1];
    reconcileStageCards(container, 'task-a', structuredClone(entries), create);
    assert.equal(container.children[1], card);
    assert.equal(card.updates, 0);
    assert.equal(card.refreshes, 2);
    assert.equal(card.open, true);
    assert.equal(card.scrollTop, 123);
    assert.equal(card.log, 'previous events');
    reconcileStageCards(container, 'task-a', [['scrape', entries[0][1]], ['ingest', {status:'completed'}]], create);
    assert.equal(container.children[1], card);
    assert.equal(card.stage.status, 'completed');
    assert.equal(card.updates, 1);
});

test('changing tasks or clearing selection discards old log panels', () => {
    const {container, create} = fixture();
    const entries = [['scrape', {status:'running'}]];
    reconcileStageCards(container, 'task-a', entries, create);
    const old = container.children[0];
    reconcileStageCards(container, 'task-b', entries, create);
    assert.notEqual(container.children[0], old);
    resetStageCards(container);
    assert.equal(container.children.length, 0);
    reconcileStageCards(container, 'task-b', entries, create);
    assert.equal(container.children.length, 1);
});
