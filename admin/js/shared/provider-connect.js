import { bindCloudConnection } from '../settings/cloud-connection.js';

const __ = window.wp?.i18n?.__ || ((text) => text);
let activeDialog = null;
const providerKey = (value) => String(value || '').toLowerCase();

// Resolve the picker beside the notice, keeping changes in the invoking editor.
function findPicker(trigger) {
  const notice = trigger.closest('[data-aipkit-provider-notice]');
  if (notice?.dataset.aipkitConnectionModelSource) {
    const source = document.getElementById(notice.dataset.aipkitConnectionModelSource);
    return source?.parentElement?.querySelector('[data-aipkit-unified-model-selector]')?._aipkitUnifiedModelController || null;
  }
  const providerSelect = notice?.id
    ? document.querySelector(`select[data-aipkit-provider-notice-target="${CSS.escape(notice.id)}"]`)
    : null;
  if (!providerSelect) return null;
  let scope = providerSelect.parentElement;
  while (scope && scope !== document.body) {
    const selectors = scope.querySelectorAll('[data-aipkit-unified-model-selector]');
    for (const selector of selectors) {
      if (selector._aipkitUnifiedModelController) return selector._aipkitUnifiedModelController;
    }
    scope = scope.parentElement;
  }
  return null;
}

window.aipkit_openProviderConnection = async function (trigger, contextPicker = null, options = {}) {
  if (activeDialog) { activeDialog.focus(); return; }
  // A feature-specific connection (such as realtime) must not switch the main text model.
  const picker = options.provider ? null : contextPicker || findPicker(trigger);
  const dialog = document.createElement('dialog');
  dialog.className = 'aipkit_connect_dialog';
  dialog.dataset.aipkitConnectDialog = 'true';
  dialog.setAttribute('aria-labelledby', 'aipkit_connect_title');
  dialog.setAttribute('aria-describedby', 'aipkit_connect_subtitle');
  dialog.innerHTML = `<header class="aipkit_connect_header"><button type="button" class="aipkit_connect_icon_button" data-connect-back hidden><svg viewBox="0 0 20 20" width="20" height="20" aria-hidden="true"><path d="M12 4.5L6.5 10l5.5 5.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button><div class="aipkit_connect_heading"><h2 id="aipkit_connect_title"></h2><p class="aipkit_connect_subtitle" id="aipkit_connect_subtitle"></p></div><button type="button" class="aipkit_connect_icon_button" data-connect-close><svg viewBox="0 0 20 20" width="20" height="20" aria-hidden="true"><path d="M5 5l10 10M15 5L5 15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button></header><div class="aipkit_connect_body" data-connect-body></div><p class="aipkit_connect_feedback" role="status" aria-live="polite"></p>`;
  const title = dialog.querySelector('h2');
  const subtitle = dialog.querySelector('.aipkit_connect_subtitle');
  const setHeading = (text, hint = '') => { title.textContent = text; subtitle.textContent = hint; subtitle.hidden = !hint; };
  setHeading(__('Connect an AI provider', 'gpt3-ai-content-generator'), __('Choose where your AI runs. You can connect more than one.', 'gpt3-ai-content-generator'));
  const back = dialog.querySelector('[data-connect-back]');
  back.setAttribute('aria-label', __('Back to providers', 'gpt3-ai-content-generator'));
  dialog.querySelector('[data-connect-close]').setAttribute('aria-label', __('Close', 'gpt3-ai-content-generator'));
  const body = dialog.querySelector('[data-connect-body]');
  const feedback = dialog.querySelector('.aipkit_connect_feedback');
  body.innerHTML = `<div class="aipkit_connect_skeleton" aria-hidden="true">${'<span></span>'.repeat(5)}</div>`;
  body.setAttribute('aria-busy', 'true');
  body.setAttribute('aria-label', __('Loading providers…', 'gpt3-ai-content-generator'));
  let selectedProvider = '';
  let busy = false;
  let closed = false;
  let cloudConnecting = false;
  let cloudAccount = null;
  const finishConnection = () => {
    if (closed) return;
    const provider = selectedProvider;
    const panel = body.querySelector(`[data-connect-panel="${CSS.escape(provider)}"]`);
    const defaultModel = panel?.dataset.connectDefaultModel || '';
    dialog.addEventListener('close', () => picker?.useConnectedProvider(provider, defaultModel), { once: true });
    dialog.close();
  };
  const cloudChanged = (event) => {
    if (cloudConnecting && ['connect', 'check_email', 'verify'].includes(event.detail?.action) && event.detail?.connected && (event.detail.emailVerified === true || event.detail.hasCredits === true)) finishConnection();
  };
  window.addEventListener('aipkit:cloud-connection-changed', cloudChanged);
  dialog.addEventListener('submit', (event) => {
    cloudConnecting = selectedProvider === 'AIPufferCloud' && ['connect', 'check_email', 'verify'].includes(event.submitter?.value);
  }, true);
  const showChooser = () => {
    if (busy || body.querySelector('[aria-busy="true"]')) return;
    body.querySelectorAll('[data-connect-reveal]').forEach((toggle) => setRevealed(toggle, false));
    cloudAccount?.cancel();
    selectedProvider = '';
    setHeading(__('Connect an AI provider', 'gpt3-ai-content-generator'), __('Choose where your AI runs. You can connect more than one.', 'gpt3-ai-content-generator'));
    body.querySelectorAll('[data-connect-panel]').forEach((el) => { el.hidden = true; });
    body.querySelector('[data-connect-chooser]').hidden = false;
    body.querySelectorAll('[data-connect-provider]').forEach((choice) => {
      choice.querySelector('[data-connect-connected]').hidden = !window.aipkit_dashboard?.providerStatus?.[providerKey(choice.dataset.connectProvider)];
    });
    back.hidden = true;
    feedback.textContent = '';
    body.querySelector('[data-connect-provider]:not([hidden])')?.focus();
  };
  const setRevealed = (toggle, revealed) => {
    const input = document.getElementById(toggle.getAttribute('aria-controls'));
    if (input) input.type = revealed ? 'text' : 'password';
    toggle.setAttribute('aria-pressed', String(revealed));
    toggle.textContent = revealed ? __('Hide', 'gpt3-ai-content-generator') : __('Show', 'gpt3-ai-content-generator');
  };
  dialog.addEventListener('close', () => {
    closed = true;
    window.removeEventListener('aipkit:cloud-connection-changed', cloudChanged);
    dialog.remove();
    activeDialog = null;
    if (trigger.isConnected) trigger.focus({ preventScroll: true });
  }, { once: true });
  dialog.addEventListener('click', (event) => {
    if (event.target.closest('[data-connect-close]')) { dialog.close(); return; }
    if (event.target.closest('[data-connect-back]')) { showChooser(); return; }
    const reveal = event.target.closest('[data-connect-reveal]');
    if (reveal) { setRevealed(reveal, reveal.getAttribute('aria-pressed') !== 'true'); return; }
    const choice = event.target.closest('[data-connect-provider]');
    if (choice && !busy) {
      selectedProvider = choice.dataset.connectProvider;
      body.querySelector('[data-connect-chooser]').hidden = true;
      const panel = body.querySelector(`[data-connect-panel="${CSS.escape(selectedProvider)}"]`);
      panel.hidden = false;
      const connected = !!window.aipkit_dashboard?.providerStatus?.[providerKey(selectedProvider)];
      const submit = panel.querySelector('[data-connect-form] button[type="submit"]');
      if (submit) submit.textContent = connected ? __('Save', 'gpt3-ai-content-generator') : __('Connect', 'gpt3-ai-content-generator');
      const hint = connected ? '' : panel.dataset.connectCredentialType === 'password'
        ? __('Paste your API key to connect.', 'gpt3-ai-content-generator')
        : (panel.dataset.connectCredentialType === 'url' ? __('Enter your server address to connect.', 'gpt3-ai-content-generator') : '');
      setHeading(`${connected ? __('Manage', 'gpt3-ai-content-generator') : __('Connect', 'gpt3-ai-content-generator')} ${panel.dataset.connectLabel}`, hint);
      back.hidden = false;
      feedback.textContent = '';
      const focusTarget = [...panel.querySelectorAll('input:not([type="hidden"]):not(:disabled), button:not(:disabled)')].find((control) => !control.closest('[hidden]'));
      focusTarget?.focus();
    }
  });
  dialog.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-connect-form]');
    if (!form) return;
    event.preventDefault();
    if (busy) return;
    busy = true;
    back.disabled = true;
    const fields = Object.fromEntries(new FormData(form));
    const controls = [...form.querySelectorAll('input, button')];
    controls.forEach((control) => { control.disabled = true; });
    const button = form.querySelector('button[type="submit"]');
    const buttonLabel = button.textContent;
    button.textContent = __('Connecting…', 'gpt3-ai-content-generator');
    form.setAttribute('aria-busy', 'true');
    feedback.textContent = '';
    try {
      await window.aipkit_syncModels(null, selectedProvider, { connectionFields: fields, silent: true, showErrors: false, showLocalStatus: false, propagateError: true });
      form.querySelectorAll('[data-connect-reveal]').forEach((toggle) => setRevealed(toggle, false));
      const keyInput = form.querySelector('input[type="password"]');
      if (keyInput) keyInput.value = '';
      finishConnection();
    } catch (error) {
      if (!closed) feedback.textContent = error.message;
    } finally {
      busy = false;
      back.disabled = false;
      controls.forEach((control) => { control.disabled = false; });
      button.textContent = buttonLabel;
      form.removeAttribute('aria-busy');
    }
  });
  document.body.append(dialog);
  activeDialog = dialog;
  dialog.showModal();
  try {
    const response = await window.aipkit_apiRequest('aipkit_provider_connection', { operation: 'view' });
    if (closed) return;
    body.removeAttribute('aria-busy');
    body.removeAttribute('aria-label');
    body.innerHTML = response.html;
    cloudAccount = bindCloudConnection(dialog);
    // Show only providers supported by the invoking model picker.
    const allowed = picker?.getProviders().map((item) => providerKey(item.provider));
    if (allowed?.length && !allowed.includes('aipuffercloud')) allowed.push('aipuffercloud');
    if (allowed?.length) body.querySelectorAll('[data-connect-provider]').forEach((choice) => { choice.hidden = !allowed.includes(providerKey(choice.dataset.connectProvider)); });
    showChooser();
    const initialProvider = options.initialProvider || options.provider;
    if (initialProvider) body.querySelector(`[data-connect-provider="${CSS.escape(initialProvider)}"]`)?.click();
  } catch (error) {
    if (!closed) { body.removeAttribute('aria-busy'); body.textContent = ''; feedback.textContent = error.message; }
  }
};
