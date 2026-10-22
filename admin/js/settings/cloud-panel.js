import { bindCloudConnection } from './cloud-connection.js';

/**
 * The AI Puffer Cloud sheet in Settings and Home. One sheet, two jobs: "Manage my website" (the website pane) when
 * opened from the website card or Connections, and AI Puffer Cloud as an AI provider (connection line, default
 * model) when opened from Settings › AI. The account and the credit balance live on Home.
 *
 * Openers name the job with data-aipkit-cloud-open ("website", anything else is the provider sheet), and website
 * openers may name a first step with data-aipkit-website-start (chatgpt, claude or resume); Settings'
 * provider sheets open and close the panel itself. Its Cloud section and Website pane are replaced by their own
 * scripts, so the header and footer follow whatever those parts say (their data-cloud-* attributes) instead of being
 * re-rendered here. Other entry points mount the same panel over their current workspace.
 */
const CONNECTED = ['connected', 'verify', 'revoked', 'unavailable'];
const getPanel = () => document.getElementById('aipkit_cloud_panel');
const setHidden = (element, hidden) => {
    if (element && element.hidden !== hidden) element.hidden = hidden;
};
let panelRequest = null;
let lastOpener = null;
// A website opener can also name a first step (data-aipkit-website-start): Set up for ChatGPT or Claude, or Resume.
const STEPS = ['chatgpt', 'claude', 'resume'];
let pendingStep = null;

/**
 * Runs the step in the website pane, the same as clicking it there. A step the pane doesn't offer right now (Cloud not
 * connected, email not verified, a setup already waiting) leaves the pane on what it shows instead.
 */
function startStep(panel, step) {
    const pane = panel.querySelector('#aipkit_cloud_website');
    if (!pane || panel.dataset.cloudTab !== 'website') return;
    const control = step === 'resume'
        ? pane.querySelector('[data-mcp-action="resume"]')
        : pane.querySelector(`[data-mcp-connect] button[name="client"][value="${step}"]`);
    if (!control || control.disabled || !control.getClientRects().length) return;
    if (step === 'resume') control.click();
    else control.form?.requestSubmit(control);
}

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

/** Fetch only the shared sheet. Its parent module, input values and generated output stay mounted. */
async function openInWorkspace(opener) {
    const workspace = document.getElementById('aipkit_module-container');
    if (!workspace || typeof window.aipkit_apiRequest !== 'function') return;
    const moduleRevision = workspace.dataset.moduleRevision;
    const requestedStep = pendingStep;
    opener.setAttribute('aria-busy', 'true');
    const feedback = opener.closest('[data-aipkit-cloud-announcement]')?.querySelector('[data-cloud-announcement-feedback]')
        || opener.closest('[data-aipkit-website-tip]')?.querySelector('[data-website-tip-feedback]');
    if (feedback) feedback.hidden = true;
    try {
        if (!panelRequest) {
            panelRequest = window.aipkit_apiRequest('aipkit_provider_connection', { operation: 'website_view' });
        }
        const response = await panelRequest;
        // A navigation while the request was in flight must not mount a stale sheet or run a deferred step.
        if (!workspace.isConnected || !opener.isConnected || workspace.dataset.moduleRevision !== moduleRevision) {
            if (pendingStep === requestedStep) pendingStep = null;
            return;
        }
        let panel = getPanel();
        if (!panel) {
            const template = document.createElement('template');
            template.innerHTML = response.html;
            const host = template.content.querySelector('#aipkit_settings_container');
            panel = host?.querySelector('#aipkit_cloud_panel');
            if (!panel) throw new Error('invalid_response');
            workspace.append(host);
            bindCloudConnection(host);
            window.aipkit_initManageWebsite?.();
            window.aipkit_initAiProviderCards?.();
            window.aipkit_initSettingsSelectPickers?.();
            window.aipkit_initSettingsSavedData?.();
            window.aipkit_attachAutoSaveListeners?.();
        }
        bindPanel(panel);
        setTab(panel, opener.dataset.aipkitCloudOpen);
        window.aipkit_openProviderModal?.('cloud', opener);
    } catch (error) {
        if (pendingStep === requestedStep) pendingStep = null;
        if (feedback && opener.isConnected && workspace.isConnected && workspace.dataset.moduleRevision === moduleRevision) {
            feedback.textContent = window.wp?.i18n?.__('Website setup could not be opened. Please try again.', 'gpt3-ai-content-generator') || 'Website setup could not be opened. Please try again.';
            feedback.hidden = false;
        }
    } finally {
        panelRequest = null;
        opener.removeAttribute('aria-busy');
    }
}

document.addEventListener('click', (event) => {
    const opener = event.target.closest('[data-aipkit-cloud-open]');
    if (opener) {
        lastOpener = opener;
        const step = opener.dataset.aipkitWebsiteStart;
        pendingStep = opener.dataset.aipkitCloudOpen === 'website' && STEPS.includes(step) ? { step, until: Date.now() + 15000 } : null;
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
        event.preventDefault();
        void openInWorkspace(opener);
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
    sync(panel);
    const step = pendingStep && Date.now() < pendingStep.until ? pendingStep.step : '';
    pendingStep = null;
    // After the panel's own open work (focus, layout), so the step's result shows in the open sheet.
    if (step) window.requestAnimationFrame(() => startStep(panel, step));
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
