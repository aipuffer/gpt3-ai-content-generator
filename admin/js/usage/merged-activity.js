// Merge paginated, newest-first sources without displaying rows ahead of an unseen page.
// Commit cursors only after a complete batch succeeds, so retries never skip activity.
export function createMergedActivity(loaders) {
    let sources = loaders.map((load) => ({ load, page: 1, more: true, buffer: [] }));
    let rows = [];
    return {
        async next(size) {
            const next = sources.map((source) => ({ ...source, buffer: [...source.buffer] }));
            const batch = [];
            while (batch.length < size) {
                await Promise.all(next.map(async (source) => {
                    if (!source.buffer.length && source.more) {
                        const result = await source.load(source.page);
                        source.buffer = result.rows;
                        source.more = Boolean(result.more);
                        source.page += 1;
                        if (!source.buffer.length && source.more) throw new Error('Empty activity page with more results');
                    }
                }));
                const available = next.filter((source) => source.buffer.length);
                if (!available.length) break;
                available.sort((a, b) => b.buffer[0].timestamp - a.buffer[0].timestamp);
                batch.push(available[0].buffer.shift());
            }
            sources = next;
            rows = [...rows, ...batch];
            return { rows, more: sources.some((source) => source.more || source.buffer.length) };
        },
    };
}
