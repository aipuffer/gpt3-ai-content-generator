/**
 * The AI Puffer Cloud panel in Settings: one side panel for the account, AI credits and website management.
 *
 * Openers name a tab with data-aipkit-cloud-open; Settings' provider sheets open and close the panel itself. Its
 * Cloud section and Website pane are replaced by their own scripts, so the header, tabs, dots and footer follow
 * whatever those parts say (their data-cloud-* attributes) instead of being re-rendered here. Settings and Usage both mount the
 * same panel in place. Other entry points can open Settings when no panel is mounted.
 */
const TABS = ['account', 'credits', 'website'];
const CONNECTED = ['connected', 'verify', 'revoked'];
const getPanel = () => document.getElementById('aipkit_cloud_panel');
const setText = (element, value) => {
    if (element && element.textContent !== value) element.textContent = value;
};
const setHidden = (element, hidden) => {
    if (element && element.hidden !== hidden) element.hidden = hidden;
};
// Expire a deferred opener if its destination never mounts the panel.
let request = null;
let lastOpener = null;

function setTab(panel, tab) {
    const next = TABS.includes(tab) ? tab : 'credits';
    panel.dataset.cloudTab = next;
    panel.querySelectorAll('[role="tab"][data-cloud-tab-switch]').forEach((button) => {
        const selected = button.dataset.cloudTabSwitch === next;
        button.setAttribute('aria-selected', selected ? 'true' : 'false');
        button.tabIndex = selected ? 0 : -1;
    });
    const body = panel.querySelector('#aipkit_cloud_panel_body');
    if (body) {
        body.setAttribute('aria-labelledby', `aipkit_cloud_tab_${next}`);
        body.scrollTop = 0;
    }
}

function sync(panel) {
    const section = panel.querySelector('#aipkit_cloud_connection');
    const state = section?.dataset.cloudState || 'signup';
    const connected = CONNECTED.includes(state);
    panel.dataset.cloudState = state;

    const pill = panel.querySelector('[data-cloud-panel-pill]');
    if (pill) {
        pill.textContent = section?.dataset.cloudLabel || '';
        pill.className = `aipkit_cloud_panel_pill${state === 'connected' ? ' is-ok' : state === 'signup' ? '' : ' is-warn'}`;
    }
    const sub = panel.querySelector('[data-cloud-panel-sub]');
    if (sub) sub.textContent = section?.dataset.cloudSub || sub.dataset.defaultText || '';
    const checked = panel.querySelector('[data-cloud-panel-checked]');
    if (checked) checked.textContent = connected ? section?.dataset.cloudChecked || '' : '';

    setHidden(panel.querySelector('[data-cloud-tabs]'), !connected);
    panel.querySelectorAll('[data-cloud-connected-only]').forEach((part) => { setHidden(part, !connected); });

    const website = panel.querySelector('#aipkit_cloud_website');
    const dots = {
        account: state === 'verify' || state === 'revoked',
        credits: false,
        website: website?.dataset.cloudAttention === '1',
    };
    Object.entries(dots).forEach(([tab, on]) => {
        const dot = panel.querySelector(`[data-cloud-dot="${tab}"]`);
        setHidden(dot, !on);
    });

    // The Account tab's summary tile repeats the Website tab's balance once that has been checked.
    const usage = panel.querySelector('[data-aipkit-website-usage="panel"]');
    const tile = panel.querySelector('[data-cloud-website-tile]');
    const enabled = usage?.dataset.mcpEnabled === '1';
    const remaining = enabled
        ? usage.querySelector('[data-website-remaining]')?.textContent.trim()
        : '';
    setText(tile?.querySelector('[data-cloud-website-value]'), enabled ? remaining || '—' : tile?.dataset.cloudOffText || '—');
    setText(tile?.querySelector('[data-cloud-website-meta]'), (enabled ? tile?.dataset.cloudRemainingText : tile?.dataset.cloudOffMeta) || '');
    setText(tile?.querySelector('[data-cloud-website-link]'), (enabled ? tile?.dataset.cloudDetailsText : tile?.dataset.cloudSetupText) || '');
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

    panel.querySelector('[data-cloud-tabs]')?.addEventListener('keydown', (event) => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        const index = TABS.indexOf(panel.dataset.cloudTab);
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? TABS.length - 1
            : (index + (event.key === 'ArrowRight' ? 1 : -1) + TABS.length) % TABS.length;
        setTab(panel, TABS[next]);
        panel.querySelector(`#aipkit_cloud_tab_${TABS[next]}`)?.focus();
    });
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
        if (switcher.getAttribute('role') !== 'tab') panel.querySelector(`#aipkit_cloud_tab_${panel.dataset.cloudTab}`)?.focus();
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
