/** This screen reuses Cloud/Freemius setup. No background requests or approval from the browser. */
function initDirectoryConnect() {
    const root = document.getElementById('aipkit-directory-connect');
    if (!root || root.dataset.accountReady === 'true') return;
    const workspace = root.parentElement;
    const observer = new MutationObserver(() => {
        const account = workspace.querySelector('#aipkit_cloud_connection');
        if (account?.dataset.cloudState !== 'connected') return;
        observer.disconnect();
        // The server rechecks verification, user permissions and the original directory request.
        window.location.reload();
    });
    observer.observe(workspace, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-cloud-state'] });
    window.addEventListener('pagehide', () => observer.disconnect(), { once: true });
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initDirectoryConnect, { once: true });
else initDirectoryConnect();
