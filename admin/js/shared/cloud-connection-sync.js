/** Same-site admin tabs exchange an invalidation only; each reads its own authorized view. */
function createSync() {
    let revision = 0;
    let writes = 0;
    const subscribers = new Map();
    const ajaxUrl = window.aipkit_dashboard?.ajaxurl || window.aipkitSetup?.ajaxUrl;
    const key = ajaxUrl ? `aipkit:cloud-connection:${ajaxUrl}` : null;

    async function refresh(subscriber) {
        if (!subscriber.dirty || subscriber.loading || writes) return;
        subscriber.dirty = false;
        subscriber.loading = true;
        const expected = revision;
        const isCurrent = () => expected === revision && !writes;
        let failed = false;
        try {
            const response = await subscriber.read(isCurrent);
            if (isCurrent()) subscriber.apply(response);
            else subscriber.dirty = true;
        } catch (_) {
            // Keep the last valid state; retry on focus or the next notification, without polling.
            subscriber.dirty = true;
            failed = true;
        } finally {
            subscriber.loading = false;
            if (!failed) void refresh(subscriber);
        }
    }

    const refreshAll = () => subscribers.forEach(subscriber => { void refresh(subscriber); });
    if (key) {
        window.addEventListener('storage', (event) => {
            if (event.key !== key || !event.newValue) return;
            revision++;
            subscribers.forEach(subscriber => { subscriber.dirty = true; });
            refreshAll();
        });
        window.addEventListener('focus', refreshAll);
    }
    return {
        revision: () => revision,
        advance: () => { revision++; },
        begin: () => {
            revision++;
            writes++;
            return () => { writes--; refreshAll(); };
        },
        publish: () => {
            if (!key) return;
            try { window.localStorage.setItem(key, `${Date.now()}:${Math.random()}`); } catch (_) { /* Storage can be disabled. Same-page updates still work. */ }
        },
        watch: (name, read, apply) => {
            if (!subscribers.has(name)) subscribers.set(name, { read, apply, dirty: false, loading: false });
        },
    };
}

// admin-main and Usage are separate bundles; their read/write guards must share one revision.
const sync = window.aipkitCloudConnectionSync ||= createSync();
export const cloudConnectionRevision = sync.revision;
export const advanceCloudConnectionRevision = sync.advance;
export const beginCloudConnectionChange = sync.begin;
export const publishCloudConnectionChange = sync.publish;
export const watchCloudConnection = sync.watch;
