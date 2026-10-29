/** Bounded status reads while the website connection sheet is visible. Never creates or approves access. */
export function watchPendingAssistant({ pending, visible, busy, check, changed, signal }) {
    const deadlines = new Map();
    let timer = null;
    let controller = null;
    let generation = 0;
    let turn = 0;
    const cancel = () => {
        clearTimeout(timer); timer = null;
        generation++;
        controller?.abort();
    };
    const candidates = () => pending().filter(item => {
        if (!deadlines.has(item.id)) deadlines.set(item.id, Math.min(Date.now() + 300000, item.expires));
        return Date.now() < deadlines.get(item.id);
    });
    const sync = (immediate = false) => {
        if (signal.aborted || !visible()) { cancel(); return; }
        if (!candidates().length) { cancel(); return; }
        if (controller) return;
        if (immediate) { clearTimeout(timer); timer = null; }
        if (timer === null) timer = setTimeout(tick, immediate ? 0 : 4000);
    };
    const tick = async () => {
        timer = null;
        if (signal.aborted || !visible()) return;
        const items = candidates();
        if (!items.length) return;
        if (busy()) { sync(); return; }
        const item = items[turn++ % items.length];
        const expected = generation;
        const request = new AbortController(); controller = request;
        try {
            const result = await check(item.id, request.signal);
            if (!signal.aborted && !request.signal.aborted && generation === expected && visible()
                && pending().some(row => row.id === item.id) && ['active', 'paused', 'revoked', 'expired'].includes(result?.state)) {
                await changed();
            }
        } catch (_) { /* Transient errors leave Check available; retry only inside this bounded window. */ }
        finally {
            if (controller === request) controller = null;
            sync();
        }
    };
    signal.addEventListener('abort', cancel, { once: true });
    return { sync, invalidate: cancel };
}
