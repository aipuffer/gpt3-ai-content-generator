const __ = window.wp?.i18n?.__ || ((text) => text);
const sprintf = window.wp?.i18n?.sprintf || ((format, ...args) => args.reduce((text, arg) => text.replace('%s', arg), format));

/** Freemius email editing shared by onboarding, Usage and provider dialogs. */
export function bindAccountEmail(root, { busy, refresh, onEdit = () => {} }) {
    let active = null, saving = false, paused = [];
    // An ownership change needs two emails approved; it reaches this site only when the final link opens it.
    let pending = null;
    const find = (selector) => active?.querySelector(selector);
    const clear = () => {
        find('[data-email-error]').hidden = true;
    };
    const span = (text) => Object.assign(document.createElement('span'), { textContent: text });
    const title = (text) => Object.assign(document.createElement('strong'), { textContent: text });
    function pendingMessage() {
        // Settings and Usage replace the account section when they refresh; follow it to the new one.
        if (!pending.controls.isConnected) pending.controls = root.querySelector('[data-cloud-account-controls]') || pending.controls;
        return pending.controls.querySelector('[data-email-message]');
    }
    function showOwnershipChange(note = '') {
        const message = pendingMessage();
        if (!message) return null;
        const check = Object.assign(document.createElement('button'), { type: 'button', className: pending.buttonClass, textContent: __('Check again', 'gpt3-ai-content-generator') });
        check.dataset.action = 'check-email-change';
        const actions = document.createElement('span');
        actions.className = 'aipkit_cloud_email_message_actions';
        actions.append(check);
        if (note) actions.append(span(note));
        message.classList.remove('is-done');
        message.replaceChildren(
            title(__('Waiting for confirmation', 'gpt3-ai-content-generator')),
            span(pending.notice), ' ',
            /* translators: %s: the new email address. */
            span(sprintf(__('Then %s gets an email for final approval. Once it is approved, check again.', 'gpt3-ai-content-generator'), pending.email)),
            actions
        );
        message.hidden = false;
        return check;
    }
    async function checkOwnershipChange(button) {
        let outcome = '';
        await busy(button, async () => {
            try { await refresh(); outcome = 'checked'; }
            catch (_) { outcome = 'failed'; }
        });
        if (!pending || !outcome) return;
        const message = pendingMessage();
        const current = pending.controls.querySelector('[data-cloud-email]')?.textContent.trim().toLowerCase();
        if (outcome === 'checked' && message && current === pending.email.toLowerCase()) {
            message.classList.add('is-done');
            message.replaceChildren(
                title(__('Email changed', 'gpt3-ai-content-generator')),
                /* translators: %s: the new email address. */
                span(sprintf(__('This site’s account now uses %s.', 'gpt3-ai-content-generator'), pending.email))
            );
            message.hidden = false;
            pending = null;
            return;
        }
        showOwnershipChange(outcome === 'failed'
            ? __('Could not check right now. Try again in a moment.', 'gpt3-ai-content-generator')
            : __('Not changed yet. Approve both emails, then check again.', 'gpt3-ai-content-generator'))?.focus();
    }
    function updateOwnership() {
        if (!active) return;
        const form = find('[data-cloud-email-editor]');
        const input = find('[data-field="account_email"]');
        const email = input.value.trim();
        const changed = input.checkValidity() && email !== find('[data-cloud-email]').textContent.trim();
        const ownership = find('[data-email-ownership]');
        ownership.hidden = !changed;
        form.querySelectorAll('[data-new-email]').forEach(label => { label.textContent = changed ? email : ''; });
        const selected = form.querySelector('[name="email_ownership"]:checked')?.value;
        find('[data-email-transfer]').hidden = !changed || selected !== 'both';
    }
    function cancel() {
        if (!active || saving) return;
        find('[data-cloud-email-editor]').hidden = true;
        find('[data-cloud-account]').hidden = false;
        find('[data-field="account_email"]').value = '';
        find('[data-action="edit-email"]').setAttribute('aria-expanded', 'false');
        clear();
        paused.forEach(button => { button.disabled = false; });
        paused = [];
        const prior = active;
        active = null;
        onEdit(false);
        // Opening the editor hid a change still waiting for approval; bring it back.
        if (pending) showOwnershipChange();
        prior.querySelector('[data-action="edit-email"]')?.focus();
    }
    async function save(button) {
        if (!active || saving) return;
        const form = find('[data-cloud-email-editor]');
        const input = find('[data-field="account_email"]');
        if (form.hidden || !form.dataset.emailAction || !input.reportValidity()) return;
        const email = input.value.trim();
        if (email === find('[data-cloud-email]').textContent) { cancel(); return; }
        const owner = form.querySelector('[name="email_ownership"]:checked')?.value;
        const assets = form.querySelector('[name="email_transfer"]:checked')?.value;
        if (!['both', 'current', 'new'].includes(owner) || (owner === 'both' && !['all', 'plugin'].includes(assets))) {
            find('[data-email-error]').textContent = __('Choose who owns the addresses and what should move.', 'gpt3-ai-content-generator');
            find('[data-email-error]').hidden = false;
            return;
        }
        const transferType = owner === 'current' ? 'transfer_to_client' : owner === 'both' && assets === 'all' ? 'merge' : 'transfer';
        let accepted = false, ownershipNotice = '';
        saving = true;
        await busy(button, async () => {
            clear();
            try {
                const body = new FormData();
                for (const [key, value] of Object.entries({ action: form.dataset.emailAction, security: form.dataset.emailSecurity, module_id: form.dataset.emailModule, email_address: email, transfer_type: transferType })) body.append(key, value);
                const response = await fetch(form.action, { method: 'POST', credentials: 'same-origin', body });
                const result = await response.json();
                if (!result?.success) {
                    const error = result?.error || result?.data;
                    if (error?.code === 'change_ownership' && typeof error.url === 'string') {
                        const account = new URL(find('[data-cloud-manage-email]').href, form.action);
                        const target = new URL(error.url, form.action);
                        if (target.origin === account.origin && target.pathname === account.pathname
                            && target.searchParams.get('page') === account.searchParams.get('page')
                            && target.searchParams.get('fs_action') === 'change_owner'
                            && target.searchParams.get('state') === 'init'
                            && target.searchParams.get('candidate_email') === email
                            && target.searchParams.get('transfer_type') === transferType
                            && target.searchParams.has('_wpnonce') && !target.username && !target.password) {
                            const confirmation = await fetch(target.href, { credentials: 'same-origin', redirect: 'follow' });
                            if (!confirmation.ok || new URL(confirmation.url).origin !== target.origin) throw new Error(__('Could not start the account change. Please try again shortly.', 'gpt3-ai-content-generator'));
                            const page = new DOMParser().parseFromString(await confirmation.text(), 'text/html');
                            const notice = [...page.querySelectorAll('.fs-notice.success')].find(item => item.textContent.includes(find('[data-cloud-email]').textContent.trim()));
                            if (!notice) throw new Error(__('Could not confirm whether the ownership email was sent. Check your inbox before trying again.', 'gpt3-ai-content-generator'));
                            ownershipNotice = notice.querySelector('.fs-notice-body')?.textContent.trim() || __('Check your current email to confirm this account change.', 'gpt3-ai-content-generator');
                            return;
                        }
                    }
                    throw new Error(typeof error?.message === 'string' ? error.message : __('Could not update your email. Please try again.', 'gpt3-ai-content-generator'));
                }
            } catch (error) {
                find('[data-email-error]').textContent = error.message || __('Could not update your email. Please try again.', 'gpt3-ai-content-generator');
                find('[data-email-error]').hidden = false;
                return;
            }
            accepted = true;
            // The email was accepted: a failed view refresh must never repeat the update.
            try {
                await refresh();
            } catch (_) {
                find('[data-email-message]').textContent = __('Email updated, but account details could not be refreshed. Reload this page before continuing.', 'gpt3-ai-content-generator');
                find('[data-email-message]').hidden = false;
                // Block repeating an already accepted update until this page is reloaded.
                form.dataset.emailAction = '';
                find('[data-action="edit-email"]').hidden = true;
            }
        });
        saving = false;
        if (ownershipNotice) {
            // Closing the editor shows it, titled apart from the explanations read before saving: nothing has changed yet.
            pending = { email, notice: ownershipNotice, controls: active, buttonClass: find('[data-action="cancel-email"]')?.className || '' };
            cancel();
            return;
        }
        if (accepted) {
            pending = null;
            cancel();
            root.querySelector('[data-cloud-account-controls] [data-action="edit-email"]')?.focus();
        }
    }
    root.addEventListener('click', event => {
        const check = event.target.closest('[data-action="check-email-change"]');
        if (check && root.contains(check)) {
            event.preventDefault();
            if (!check.disabled) checkOwnershipChange(check);
            return;
        }
        const resend = event.target.closest('[data-cloud-resend]');
        if (resend && root.contains(resend)) {
            event.preventDefault();
            if (resend.disabled) return;
            const status = resend.closest('.aipkit_cloud_email_recovery').querySelector('[data-cloud-resend-status]');
            busy(resend, async () => {
                status.hidden = false;
                status.textContent = __('Sending verification email…', 'gpt3-ai-content-generator');
                try {
                    const url = new URL(resend.dataset.url, window.location.href);
                    const account = new URL(root.querySelector('[data-cloud-manage-email]').href, window.location.href);
                    if (url.origin !== window.location.origin || url.pathname !== account.pathname || url.searchParams.get('page') !== account.searchParams.get('page') || url.searchParams.get('fs_action') !== 'verify_email') throw new Error('invalid_url');
                    const response = await fetch(url.href, { credentials: 'same-origin', redirect: 'follow' });
                    if (!response.ok || new URL(response.url).origin !== url.origin) throw new Error('request_failed');
                    const page = new DOMParser().parseFromString(await response.text(), 'text/html');
                    const email = root.querySelector('[data-cloud-email]').textContent.trim();
                    const notice = [...page.querySelectorAll('.fs-notice.success')].find(element => email && element.textContent.includes(email));
                    if (!notice) throw new Error('unconfirmed');
                    status.textContent = notice.querySelector('.fs-notice-body')?.textContent.trim() || __('Verification email requested. Check your inbox.', 'gpt3-ai-content-generator');
                } catch (_) {
                    status.textContent = __('Could not confirm the email request. Please try again shortly.', 'gpt3-ai-content-generator');
                }
            });
            return;
        }
        const button = event.target.closest('[data-action="edit-email"], [data-action="cancel-email"]');
        if (!button || button.disabled) return;
        event.preventDefault();
        if (button.dataset.action === 'cancel-email') { cancel(); return; }
        cancel();
        active = button.closest('[data-cloud-account-controls]');
        if (!find('[data-cloud-email-editor]')?.dataset.emailAction) { active = null; return; }
        find('[data-cloud-email-editor]').hidden = false;
        find('[data-cloud-account]').hidden = true;
        find('[data-email-message]').hidden = true;
        button.setAttribute('aria-expanded', 'true');
        clear();
        const scope = active.closest('#aipkit_cloud_connection') || root;
        paused = [...scope.querySelectorAll('[name="cloud_action"]')].filter(control => !control.disabled);
        paused.forEach(control => { control.disabled = true; });
        find('[data-field="account_email"]').value = find('[data-cloud-email]').textContent;
        find('[data-cloud-email-editor]').querySelectorAll('input[type="radio"]').forEach(input => { input.checked = false; });
        updateOwnership();
        onEdit(true);
        find('[data-field="account_email"]').focus();
    });
    root.addEventListener('submit', event => {
        if (!event.target.matches('[data-cloud-email-editor]')) return;
        event.preventDefault();
        return save(event.submitter || event.target.querySelector('button[type="submit"]'));
    });
    root.addEventListener('input', event => {
        if (!active) return;
        if (event.target.matches('[data-field="account_email"]')) {
            find('[data-cloud-email-editor]').querySelectorAll('input[type="radio"]').forEach(input => { input.checked = false; });
            clear();
            updateOwnership();
        } else if (event.target.matches('[name="email_ownership"], [name="email_transfer"]')) {
            if (event.target.name === 'email_ownership' && event.target.value !== 'both') {
                find('[data-cloud-email-editor]').querySelectorAll('[name="email_transfer"]').forEach(input => { input.checked = false; });
            }
            clear();
            updateOwnership();
        }
    });
    root.addEventListener('keydown', event => {
        if (active && event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); cancel(); }
    });
    return { cancel, isEditing: () => !!active };
}
