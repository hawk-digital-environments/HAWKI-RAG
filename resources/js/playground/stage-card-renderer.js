// Preserve expanded log DOM while status polling updates stage metadata.
const states = new WeakMap();

export function resetStageCards(container) {
    states.delete(container);
    container.replaceChildren();
}

export function reconcileStageCards(container, taskId, entries, createCard) {
    let state = states.get(container);
    if (!state || state.taskId !== taskId) {
        resetStageCards(container);
        state = {taskId, cards: new Map()};
        states.set(container, state);
    }
    const names = new Set(entries.map(([name]) => name));
    for (const [name, saved] of state.cards) {
        if (!names.has(name)) {
            saved.card.remove();
            state.cards.delete(name);
        }
    }
    for (const [name, stage] of entries) {
        const signature = JSON.stringify(stage);
        let saved = state.cards.get(name);
        if (!saved) {
            saved = {card: createCard(name, stage), signature};
            state.cards.set(name, saved);
            container.appendChild(saved.card);
        } else if (saved.signature !== signature) {
            saved.card.updateStage(stage);
            saved.signature = signature;
        }
        saved.card.refreshStageLogs();
    }
}
