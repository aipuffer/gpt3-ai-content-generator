/** Explicit opt-in flow. No autosave, timers, credential transport or provider selection. */
import { cloudConnectionRevision, watchCloudConnection, publishCloudConnectionChange } from '../shared/cloud-connection-sync.js';
import { refreshWebsiteActions } from './cloud-connection.js';
const __ = window.wp?.i18n?.__ || ((text) => text);

/** The folded connect choices in one line: "Standard safety level · works as Alex". */
const syncConnectSummary = (root) => {
    const summary = root?.querySelector('[data-mcp-connect-summary]');
    const level = root?.querySelector('#aipkit_mcp_access');
    if (!summary || !level) return;
    const user = root.querySelector('#aipkit_mcp_act_as')?.selectedOptions[0]?.dataset.name || summary.dataset.userName || '';
    summary.textContent = __('%1$s safety level · works as %2$s', 'gpt3-ai-content-generator')
        .replace('%1$s', level.selectedOptions[0]?.textContent.trim() || '')
        .replace('%2$s', user);
};
window.aipkit_initManageWebsite = function () {
    const settings = document.getElementById('aipkit_settings_container');
    if (!settings || settings.dataset.mcpBound) return;
    settings.dataset.mcpBound = 'true';
    window.aipkitMcpSettingsController?.dispose.abort();
    const dispose = new AbortController();
    let busy = false;
    let revision = 0;
    let setupButton = null;
    // The card sits in Connections; everything it manages is the Website tab of the AI Puffer Cloud panel.
    const card = () => settings.querySelector('#aipkit_manage_website');
    const panel = () => settings.querySelector('#aipkit_cloud_website');
    const focusIdentity = (current) => {
        const element = current.contains(document.activeElement) ? document.activeElement : null;
        return element ? { id: element.id, name: element.getAttribute('name'), action: element.dataset.mcpAction, level: element.hasAttribute('data-mcp-level'), grant: element.dataset.mcpGrant || element.closest('.aipkit_mcp_menu')?.querySelector('[data-mcp-grant]')?.dataset.mcpGrant, menu: element.hasAttribute('data-mcp-menu-toggle') } : null;
    };
    const apply = (data, preserveDraft = true, requestedFocus) => {
        const current = panel();
        if (!current || typeof data.html !== 'string') throw new Error('invalid_response');
        const template = document.createElement('template');
        template.innerHTML = data.html;
        const next = template.content.querySelector('#aipkit_cloud_website');
        const nextCard = template.content.querySelector('#aipkit_manage_website');
        if (!next || !nextCard) throw new Error('invalid_response');
        const focusKey = preserveDraft || requestedFocus === undefined ? focusIdentity(current) : requestedFocus;
        const openMenuGrant = preserveDraft ? current.querySelector('[data-mcp-menu]:not([hidden]) [data-mcp-grant]')?.dataset.mcpGrant : null;
        const form = current.querySelector('[data-mcp-connect]');
        // Set up buttons carry their own assistant; only real fields are kept as a draft.
        const draft = preserveDraft && form ? Object.fromEntries([...form.elements].filter(el => el.name && el.type !== 'submit').map(el => [el.name, el.type === 'checkbox' ? el.checked : el.value])) : null;
        const foldOpen = current.querySelector('.aipkit_settings_provider_fold')?.open;
        const optionsOpen = current.querySelector('.aipkit_mcp_connect_options')?.open;
        const moreOpen = current.querySelector('.aipkit_mcp_more_accounts')?.open;
        // Keep the existing elements: the shared panel code owns its focus trap and close state.
        const currentCard = card();
        if (currentCard) {
            currentCard.querySelector('.aipkit_settings_provider_card_main').innerHTML = nextCard.querySelector('.aipkit_settings_provider_card_main').innerHTML;
            currentCard.querySelector('[data-mcp-card]').dataset.aipkitProviderConnected = nextCard.querySelector('[data-mcp-card]').dataset.aipkitProviderConnected;
        }
        current.innerHTML = next.innerHTML;
        current.dataset.cloudAttention = next.dataset.cloudAttention;
        current.dataset.mcpEnabled = next.dataset.mcpEnabled;
        // Screens that invite setup (the website tip) follow whether website management is now on.
        window.dispatchEvent(new CustomEvent('aipkit:website-management-changed', { detail: { enabled: next.dataset.mcpEnabled === '1' } }));
        // Clear stale balances after disconnect, but preserve the server-rendered setup invitation.
        // A later panel view may reuse a cached usage read, so it must not hide that invitation.
        if (next.dataset.mcpEnabled === '0') {
            document.querySelectorAll('[data-aipkit-website-usage="usage"]').forEach(host => {
                host.dataset.mcpEnabled = '0';
                if (!host.hasAttribute('data-website-invite')) host.hidden = true;
            });
            window.dispatchEvent(new CustomEvent('aipkit:website-usage-changed'));
        }
        void refreshWebsiteActions(document, !preserveDraft);
        current.querySelector('[data-mcp-feedback]').textContent = data.message || '';
        current.querySelector('[data-mcp-feedback]').hidden = !data.message;
        const nextForm = current.querySelector('[data-mcp-connect]');
        if (draft && nextForm) {
            for (const element of nextForm.elements) {
                if (element.type === 'submit' || !Object.hasOwn(draft, element.name)) continue;
                if (element.type === 'checkbox') element.checked = draft[element.name];
                else if (element.tagName !== 'SELECT' || [...element.options].some(option => option.value === draft[element.name])) element.value = draft[element.name];
            }
        }
        if (preserveDraft && foldOpen) { const fold = current.querySelector('.aipkit_settings_provider_fold'); if (fold) fold.open = true; }
        if (preserveDraft && optionsOpen) { const options = current.querySelector('.aipkit_mcp_connect_options'); if (options) options.open = true; }
        if (preserveDraft && moreOpen) { const more = current.querySelector('.aipkit_mcp_more_accounts'); if (more) more.open = true; }
        if (openMenuGrant) {
            const menu = [...current.querySelectorAll('[data-mcp-menu]')].find(item => item.querySelector('[data-mcp-grant]')?.dataset.mcpGrant === openMenuGrant);
            if (menu) { menu.hidden = false; menu.parentElement.querySelector('[data-mcp-menu-toggle]')?.setAttribute('aria-expanded', 'true'); }
        }
        syncConnectSummary(current);
        window.aipkit_refreshSettingsSelectPickers?.();
        if (data.pair) {
            const url = new URL(data.pair.connect_url);
            if (url.protocol !== 'https:' || url.hostname !== 'mcp.aipuffer.org'
                || url.port || url.username || url.password || url.pathname !== '/connect' || url.hash) throw new Error('invalid_response');
            url.searchParams.set('launch', '1');
            current.querySelector('[data-mcp-connect-link]').href = url.href;
            current.querySelectorAll('[data-mcp-instructions]').forEach(item => { item.hidden = item.dataset.mcpInstructions !== data.pair.client_hint; });
            current.querySelector('[data-mcp-connect-link]').textContent = data.pair.client_hint === 'chatgpt' ? __('Open ChatGPT plugins', 'gpt3-ai-content-generator') : data.pair.client_hint === 'claude' ? __('Open AI Puffer in Claude', 'gpt3-ai-content-generator') : __('Open assistant setup', 'gpt3-ai-content-generator');
            current.querySelector('[data-mcp-handoff]').hidden = false;
        }
        if (focusKey) {
            const matches = [...current.querySelectorAll('button, input, select, a')];
            const restored = preserveDraft ? matches.find(el => focusKey.id ? el.id === focusKey.id : focusKey.name ? el.getAttribute('name') === focusKey.name : focusKey.action ? el.dataset.mcpAction === focusKey.action && el.dataset.mcpGrant === focusKey.grant : focusKey.menu ? el.hasAttribute('data-mcp-menu-toggle') && el.closest('.aipkit_mcp_menu')?.querySelector('[data-mcp-grant]')?.dataset.mcpGrant === focusKey.grant : focusKey.level ? el.hasAttribute('data-mcp-level') && el.dataset.mcpGrant === focusKey.grant : false) : null;
            const visible = el => el && el.getClientRects().length && !el.disabled && !el.closest('[aria-hidden="true"]');
            const fallback = data.message ? current.querySelector('[data-mcp-feedback]') : settings.querySelector('#aipkit_cloud_panel_title');
            const target = visible(restored) ? restored : visible(fallback) ? fallback : card()?.querySelector('[data-aipkit-provider-settings-open]');
            if (!target) return;
            target.tabIndex = target.tabIndex < 0 ? -1 : target.tabIndex;
            target.focus();
        }
    };
    const request = async (action, fields = {}) => {
        if (busy || !panel()) return;
        busy = true;
        const current = panel();
        const requestedFocus = focusIdentity(current);
        const expected = ++revision;
        const cloud = cloudConnectionRevision();
        const controls = [...current.querySelectorAll('button, input, select')].filter(el => !el.disabled);
        const nonce = current.querySelector('[data-mcp-nonce]').value;
        controls.forEach(el => { el.disabled = true; });
        current.setAttribute('aria-busy', 'true');
        try {
            const data = await window.aipkit_apiRequest('aipkit_mcp_settings', { _wpnonce: nonce, mcp_action: action, ...fields });
            if (!settings.isConnected || expected !== revision || cloud !== cloudConnectionRevision()) return;
            apply(data, action === 'view' || action === 'diagnostics', requestedFocus);
            if (action !== 'view' && action !== 'diagnostics') publishCloudConnectionChange();
            return data;
        } catch (error) {
            if (settings.isConnected && expected === revision && panel()) { const feedback = panel().querySelector('[data-mcp-feedback]'); feedback.textContent = error.message || __('Connection unavailable. Try again.', 'gpt3-ai-content-generator'); feedback.hidden = false;
                if (action === 'connect') {
                    const connect = setupButton && panel().contains(setupButton) ? setupButton : panel().querySelector('[data-mcp-connect] button[type="submit"]');
                    if (connect) connect.textContent = __('Retry connection', 'gpt3-ai-content-generator');
                }
            }
        } finally {
            current.removeAttribute('aria-busy'); controls.forEach(el => { el.disabled = false; }); busy = false;
            if (cloud !== cloudConnectionRevision() && settings.isConnected) void request('view');
        }
    };
    settings.addEventListener('submit', event => {
        const form = event.target.closest('[data-mcp-connect]');
        if (!form) return;
        event.preventDefault();
        if (form.reportValidity()) {
            const fields = Object.fromEntries(new FormData(form));
            // Each Set up button names its assistant (ChatGPT, Claude or another app).
            setupButton = event.submitter || null;
            if (setupButton?.name === 'client') fields.client = setupButton.value;
            void (async () => {
                const check = await request('diagnostics');
                if (check?.websiteBlocked === false) await request('connect', fields);
            })();
        }
    });
    // A safety level applies as soon as it is chosen; the reply re-renders the row with what the site now holds.
    settings.addEventListener('change', event => {
        if (event.target.matches('#aipkit_mcp_access, #aipkit_mcp_act_as')) syncConnectSummary(panel());
        const select = event.target.closest('[data-mcp-level]');
        if (select) void request('level', { grant_id: select.dataset.mcpGrant, access_level: select.value });
    });
    // Each assistant's ⋯ menu: one open at a time; a click elsewhere or Escape closes it.
    const closeMenus = (except = null) => {
        panel()?.querySelectorAll('[data-mcp-menu]:not([hidden])').forEach(menu => {
            if (menu === except) return;
            menu.hidden = true;
            menu.parentElement?.querySelector('[data-mcp-menu-toggle]')?.setAttribute('aria-expanded', 'false');
        });
    };
    window.addEventListener('click', event => {
        if (!event.target.closest?.('.aipkit_mcp_menu')) closeMenus();
    }, { signal: dispose.signal });
    settings.addEventListener('keydown', event => {
        const menu = event.target.closest?.('.aipkit_mcp_menu');
        const list = menu?.querySelector('[data-mcp-menu]');
        if (!list) return;
        const toggle = menu.querySelector('[data-mcp-menu-toggle]');
        if (event.key === 'Escape' && !list.hidden) {
            event.preventDefault();
            event.stopPropagation();
            closeMenus();
            toggle?.focus();
            return;
        }
        if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
        if (list.hidden && event.target !== toggle) return;
        const items = [...list.querySelectorAll('button:not(:disabled), a[href]')];
        if (!items.length) return;
        event.preventDefault();
        closeMenus(list);
        list.hidden = false;
        toggle?.setAttribute('aria-expanded', 'true');
        const index = items.indexOf(event.target);
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1
            : event.key === 'ArrowDown' ? (index + 1) % items.length
                : (index < 0 ? items.length - 1 : (index - 1 + items.length) % items.length);
        items[next].focus();
    });
    settings.addEventListener('focusout', event => {
        const menu = event.target.closest?.('.aipkit_mcp_menu');
        if (menu && !menu.contains(event.relatedTarget)) closeMenus();
    });
    settings.addEventListener('click', event => {
        const copy = event.target.closest('[data-mcp-copy]');
        if (copy?.dataset.mcpCopy !== undefined) {
            event.preventDefault();
            if (!navigator.clipboard?.writeText) { copy.textContent = __('Select the text to copy', 'gpt3-ai-content-generator'); return; }
            navigator.clipboard.writeText(copy.dataset.mcpCopy).then(() => { copy.textContent = __('Copied', 'gpt3-ai-content-generator'); }, () => { copy.textContent = __('Select the text to copy', 'gpt3-ai-content-generator'); });
            return;
        }
        const button = event.target.closest('[data-mcp-action]');
        if (!button) {
            const toggle = event.target.closest('[data-mcp-menu-toggle]');
            const menu = toggle?.parentElement?.querySelector('[data-mcp-menu]');
            if (!menu) return;
            const open = menu.hidden;
            closeMenus(menu);
            menu.hidden = !open;
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) menu.querySelector('a, button')?.focus();
            return;
        }
        closeMenus();
        const run = () => void request(button.dataset.mcpAction, button.dataset.mcpGrant ? { grant_id: button.dataset.mcpGrant } : {});
        // Turn off asks first, in the plugin's own confirmation dialog.
        if (button.dataset.confirmText) {
            if (typeof window.aipkit_showConfirmModal !== 'function') {
                const feedback = panel()?.querySelector('[data-mcp-feedback]');
                if (feedback) { feedback.hidden = false; feedback.textContent = __('The confirmation dialog could not open. Reload this page and try again.', 'gpt3-ai-content-generator'); feedback.focus(); }
                return;
            }
            window.aipkit_showConfirmModal(button.dataset.confirmText, {
                title: button.dataset.confirmTitle || '',
                confirmText: button.dataset.confirmButton || '',
                cancelText: button.dataset.cancelButton || '',
                variant: 'danger',
                onConfirm: run,
                onCancel: () => panel()?.querySelector('.aipkit_mcp_status [data-mcp-action="disconnect"]')?.focus(),
            });
            return;
        }
        run();
    });
    window.addEventListener('aipkit:cloud-connection-changed', () => { revision++; if (settings.isConnected) void request('view'); }, { signal: dispose.signal });
    // Back from an assistant's approval page: refresh whatever shows this state, the card or the open panel.
    window.addEventListener('focus', () => { if (settings.isConnected && (settings.querySelector('[data-aipkit-settings-section="connections"]')?.hidden === false || settings.querySelector('#aipkit_cloud_panel.aipkit-active'))) void request('view'); }, { signal: dispose.signal });
    window.aipkitMcpSettingsController = { dispose, read: () => settings.isConnected ? window.aipkit_apiRequest('aipkit_mcp_settings', { _wpnonce: panel()?.querySelector('[data-mcp-nonce]').value, mcp_action: 'view' }) : null, apply: data => { if (data && settings.isConnected && !busy) { revision++; apply(data); } } };
    watchCloudConnection('mcp-settings', () => window.aipkitMcpSettingsController?.read(), data => window.aipkitMcpSettingsController?.apply(data));
};
