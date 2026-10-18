/**
 * The AI Puffer Cloud sheet in Settings and Home. One sheet, two jobs: "Manage my website" (the website pane) when
 * opened from the website card or Connections, and AI Puffer Cloud as an AI provider (connection line, default
 * model) when opened from Settings › AI. The account and the credit balance live on Home.
 *
 * Openers name the job with data-aipkit-cloud-open ("website", anything else is the provider sheet); Settings'
 * provider sheets open and close the panel itself. Its Cloud section and Website pane are replaced by their own
 * scripts, so the header and footer follow whatever those parts say (their data-cloud-* attributes) instead of being
 * re-rendered here. Other entry points can open Settings when no panel is mounted.
 */
const CONNECTED = ['connected', 'verify', 'revoked'];
const getPanel = () => document.getElementById('aipkit_cloud_panel');
const setHidden = (element, hidden) => {
    if (element && element.hidden !== hidden) element.hidden = hidden;
};
// Expire a deferred opener if its destination never mounts the panel.
let request = null;
let lastOpener = null;

function setTab(panel, tab) {
    const next = tab === 'website' ? 'website' : 'credits';
    panel.dataset.cloudTab = next;
    const title = panel.querySelector('#aipkit_cloud_panel_title');
    if (title) title.textContent = (next === 'website' ? title.dataset.cloudTitleWebsite : title.dataset.cloudTitleProvider) || title.textContent;
    const body = panel.querySelector('#aipkit_cloud_panel_body');
    if (body) body.scrollTop = 0;
    sync(panel);
}

function sync(panel) {
    const section = panel.querySelector('#aipkit_cloud_connection');
    const state = section?.dataset.cloudState || 'signup';
    const connected = CONNECTED.includes(state);
    const website = panel.dataset.cloudTab === 'website';
    panel.dataset.cloudState = state;

    // The Cloud pill belongs to the provider sheet; the website sheet says its own state in its status line.
    const pill = panel.querySelector('[data-cloud-panel-pill]');
    if (pill) {
        pill.textContent = section?.dataset.cloudLabel || '';
        pill.className = `aipkit_cloud_panel_pill${state === 'connected' ? ' is-ok' : state === 'signup' ? '' : ' is-warn'}`;
        setHidden(pill, website && connected);
    }
    const sub = panel.querySelector('[data-cloud-panel-sub]');
    if (sub) {
        const email = section?.dataset.cloudSub || '';
        sub.textContent = website && connected && email ? `AI Puffer Cloud · ${email}` : email || sub.dataset.defaultText || '';
    }
    panel.querySelectorAll('[data-cloud-connected-only]').forEach((part) => { setHidden(part, !connected); });
    panel.querySelectorAll('[data-cloud-provider-only]').forEach((part) => { if (website) setHidden(part, true); });
}

function bindPanel(panel) {
    if (panel.dataset.cloudPanelBound) return;
    panel.dataset.cloudPanelBound = 'true';
    setTab(panel, panel.dataset.cloudTab);
    sync(panel);
    let pending = 0;
    new MutationObserver(() => {
        if (pending) return;
        pending = window.requestAnimationFrame(() => { pending = 0; sync(panel); });
    }).observe(panel.querySelector('#aipkit_cloud_panel_body'), { childList: true, subtree: true, attributes: true, attributeFilter: ['data-mcp-enabled', 'hidden'] });

}

document.addEventListener('click', (event) => {
    const opener = event.target.closest('[data-aipkit-cloud-open]');
    if (opener) {
        lastOpener = opener;
        const panel = getPanel();
        if (panel) {
            bindPanel(panel);
            setTab(panel, opener.dataset.aipkitCloudOpen);
            // Settings' own openers are provider-sheet triggers; anything else opens the panel here.
            if (!opener.hasAttribute('data-aipkit-provider-settings-open')) {
                event.preventDefault();
                window.aipkit_openProviderModal?.('cloud', opener);
            }
            return;
        }
        if (typeof window.aipkit_loadModule !== 'function') return;
        event.preventDefault();
        request = { tab: opener.dataset.aipkitCloudOpen, until: Date.now() + 15000 };
        window.__aipkitRequestedProviderCard = 'AIPufferCloud';
        window.aipkit_loadModule('settings');
        return;
    }
    const switcher = event.target.closest('[data-cloud-tab-switch]');
    const panel = getPanel();
    if (switcher && panel?.contains(switcher)) {
        setTab(panel, switcher.dataset.cloudTabSwitch);
        panel.querySelector('#aipkit_cloud_panel_title')?.focus();
    }
}, true);

window.addEventListener('aipkit:provider-modal-opened', (event) => {
    const panel = event.detail?.modal;
    if (!panel || panel.id !== 'aipkit_cloud_panel') return;
    bindPanel(panel);
    if (request && Date.now() < request.until) {
        setTab(panel, request.tab);
    }
    request = null;
    sync(panel);
});

window.addEventListener('aipkit:provider-modal-closed', (event) => {
    if (event.detail?.modal?.id !== 'aipkit_cloud_panel') return;
    // A refresh can replace the card that opened the panel; focus goes back to the card that replaced it.
    if (lastOpener && !lastOpener.isConnected && document.activeElement === document.body) {
        const tab = lastOpener.dataset.aipkitCloudOpen;
        [...document.querySelectorAll(`[data-aipkit-cloud-open="${tab}"]`)].find((element) => element.getClientRects().length)?.focus();
    }
    lastOpener = null;

});

window.aipkit_initCloudPanel = () => {
    const panel = getPanel();
    if (panel) bindPanel(panel);
};
