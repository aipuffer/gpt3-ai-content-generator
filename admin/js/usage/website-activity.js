/**
 * Usage › Activity › Website: what ChatGPT and Claude read and changed on this site (website management).
 *
 * The Usage page owns the chips, search, drawer and announcements; this module fetches its own rows from
 * aipkit_mcp_activity and renders them with the same feed, pill and drawer markup. Runs of reads by one assistant
 * collapse into one row so they don't bury the changes. Summaries only: the server never sends request or response
 * bodies.
 */
const PER_PAGE = 20;
const GROUP_WINDOW = 15 * 60;
const GROUP_MIN = 3;
const TONES = { ok: 'is-ok', warn: 'is-warning', bad: 'is-error', grey: '' };

export function createWebsiteActivity(deps) {
    const { container, elements, escapeHtml, __, _n, sprintf, formatNumber, formatTimestamp, timeHtml, openDrawer, announce, showStatus, getSearch } = deps;
    const list = container.querySelector('#aipkit_usage_website_list');
    const more = container.querySelector('#aipkit_usage_website_more');
    const breakdown = container.querySelector('[data-website-breakdown]');
    const breakdownContent = breakdown?.querySelector('[data-website-breakdown-content]');
    const breakdownTotal = breakdown?.querySelector('[data-website-breakdown-total]');
    let breakdownSequence = 0;
    const state = { filter: '', page: 1, sequence: 0, counts: null, connected: true, rows: new Map(), selected: '', loaded: false };

    if (!list) {
        return null;
    }

    const loadBreakdown = async () => {
        if (!breakdownContent) return;
        const sequence = ++breakdownSequence;
        breakdownContent.setAttribute('aria-busy', 'true');
        breakdownTotal.textContent = '—';
        breakdownContent.textContent = __('Loading website actions…', 'gpt3-ai-content-generator');
        try {
            const data = await window.aipkit_apiRequest('aipkit_mcp_activity', { view: 'breakdown', days: deps.getDays() });
            if (sequence !== breakdownSequence) return;
            breakdown.hidden = !data.connected && !data.total;
            breakdownTotal.textContent = sprintf(_n('%s action', '%s actions', data.total, 'gpt3-ai-content-generator'), formatNumber(data.total));
            // Allocate rounded shares together so the displayed percentages add up to 100.
            const shares = data.categories.map((item) => item.actions / data.total * 100);
            const percentages = shares.map(Math.floor);
            shares.map((share, index) => ({ index, fraction: share - percentages[index] }))
                .sort((a, b) => b.fraction - a.fraction || a.index - b.index)
                .slice(0, 100 - percentages.reduce((sum, value) => sum + value, 0))
                .forEach(({ index }) => percentages[index]++);
            // One stacked bar for what the actions were for, largest first, and a legend with each count. Each part's
            // share is in its hover text; more kinds add legend items, never rows.
            const tone = (index) => Math.min(index, 4);
            const parts = data.categories.map((item, index) => {
                const share = `${escapeHtml(item.label)}: ${escapeHtml(formatNumber(item.actions))} · ${escapeHtml(formatNumber(percentages[index]))}%`;
                return `<span class="aipkit_usage_stack_part" data-tone="${tone(index)}" style="width:${shares[index]}%" title="${share}"></span>`;
            }).join('');
            const legend = data.categories.map((item, index) => `<li><span class="aipkit_usage_stack_swatch" data-tone="${tone(index)}" aria-hidden="true"></span>${escapeHtml(item.label)} <b>${escapeHtml(formatNumber(item.actions))}</b></li>`).join('');
            breakdownContent.innerHTML = data.total > 0
                ? `<div class="aipkit_usage_stack" aria-hidden="true">${parts}</div><ul class="aipkit_usage_stack_legend">${legend}</ul>`
                : escapeHtml(__('No website actions on this site in this period.', 'gpt3-ai-content-generator'));
        } catch (error) {
            if (sequence !== breakdownSequence) return;
            breakdown.hidden = false;
            breakdownContent.textContent = __('Website action history is unavailable. Try Refresh.', 'gpt3-ai-content-generator');
        } finally {
            if (sequence === breakdownSequence) {
                breakdown.removeAttribute('data-website-breakdown-pending');
                breakdownContent.removeAttribute('aria-busy');
                // The breakdown is one of the balance cards: the row re-counts its cards when it shows or hides.
                deps.onBreakdown?.();
            }
        }
    };

    const pill = (item) => `<span class="aipkit_usage_pill ${TONES[item.pill?.tone] ?? ''}">${escapeHtml(item.pill?.label || '')}</span>`;

    const meta = (item) => {
        if (item.state === 'pending_approval' && item.expires_at) {
            const minutes = Math.max(1, Math.ceil((item.expires_at * 1000 - Date.now()) / 60000));
            return [item.assistant, item.acting_user ? sprintf(__('as %s', 'gpt3-ai-content-generator'), item.acting_user) : '', sprintf(_n('expires in %s min', 'expires in %s min', minutes, 'gpt3-ai-content-generator'), formatNumber(minutes))].filter(Boolean).join(' · ');
        }
        if (item.pill?.tone === 'warn' && item.state !== 'pending_approval') {
            return [item.assistant, __('we couldn’t confirm whether it went through', 'gpt3-ai-content-generator')].join(' · ');
        }
        return [
            item.assistant,
            item.owner_review ? __('recorded after your review', 'gpt3-ai-content-generator') : (item.acting_user ? sprintf(__('as %s', 'gpt3-ai-content-generator'), item.acting_user) : ''),
            item.actions ? sprintf(_n('%s action', '%s actions', item.actions, 'gpt3-ai-content-generator'), formatNumber(item.actions)) : __('no action used', 'gpt3-ai-content-generator'),
        ].filter(Boolean).join(' · ');
    };

    const row = (item) => `
        <li>
            <button type="button" class="aipkit_usage_feed_row${state.selected === item.id ? ' is-active' : ''}" data-website-op="${escapeHtml(item.id)}">
                <span class="aipkit_usage_feed_icon is-website" title="${escapeHtml(__('Website', 'gpt3-ai-content-generator'))}" aria-hidden="true">W</span>
                <span class="aipkit_usage_feed_main">
                    <span class="aipkit_usage_feed_title"><span class="screen-reader-text">${escapeHtml(__('Website', 'gpt3-ai-content-generator'))}: </span>${escapeHtml(item.title)}</span>
                    <span class="aipkit_usage_feed_meta">${escapeHtml(meta(item))}</span>
                </span>
                <span class="aipkit_usage_feed_side">${pill(item)}${timeHtml(item.created_at)}</span>
            </button>
        </li>
    `;

    /** Consecutive successful reads can collapse; refusals keep their own diagnostic drawer. */
    const groupRow = (items) => {
        const first = items[0];
        const actions = items.reduce((sum, item) => sum + (item.actions || 0), 0);
        const subjects = items.map((item) => item.subject).filter(Boolean);
        const shown = subjects.slice(0, 8).map((subject) => `“${subject}”`).join(' · ');
        return `
            <li class="aipkit_usage_website_group">
                <button type="button" class="aipkit_usage_feed_row" aria-expanded="false" data-website-group>
                    <span class="aipkit_usage_feed_icon is-website" aria-hidden="true">W</span>
                    <span class="aipkit_usage_feed_main">
                        <span class="aipkit_usage_feed_title">${escapeHtml(sprintf(_n('%1$s read %2$s item', '%1$s read %2$s items', items.length, 'gpt3-ai-content-generator'), first.assistant, formatNumber(items.length)))}</span>
                        <span class="aipkit_usage_feed_meta">${escapeHtml([__('searches and reads', 'gpt3-ai-content-generator'), sprintf(_n('%s action', '%s actions', actions, 'gpt3-ai-content-generator'), formatNumber(actions))].join(' · '))}</span>
                    </span>
                    <span class="aipkit_usage_feed_side"><span class="aipkit_usage_pill">${escapeHtml(__('Read', 'gpt3-ai-content-generator'))}</span>${timeHtml(first.created_at)}</span>
                </button>
                <p class="aipkit_usage_website_group_items" hidden>${escapeHtml(shown + (subjects.length > 8 ? ' · ' + sprintf(__('%s more', 'gpt3-ai-content-generator'), formatNumber(subjects.length - 8)) : ''))}</p>
            </li>
        `;
    };

    const render = (items) => {
        if (state.filter !== '' || getSearch()) {
            return items.map(row).join('');
        }
        const html = [];
        for (let i = 0; i < items.length;) {
            let j = i;
            while (items[j]?.kind === 'read' && items[j]?.state === 'succeeded' && items[j + 1]?.kind === 'read' && items[j + 1]?.state === 'succeeded' && items[j + 1].assistant === items[i].assistant
                && items[i].created_at - items[j + 1].created_at <= GROUP_WINDOW) {
                j++;
            }
            if (items[i].kind === 'read' && items[i].state === 'succeeded' && j - i + 1 >= GROUP_MIN) {
                html.push(groupRow(items.slice(i, j + 1)));
                i = j + 1;
            } else {
                html.push(row(items[i]));
                i++;
            }
        }
        return html.join('');
    };

    const empty = () => {
        if (getSearch()) {
            return `<li class="aipkit_usage_state"><p>${escapeHtml(__('Nothing matches your search.', 'gpt3-ai-content-generator'))}</p></li>`;
        }
        if (state.filter === 'attention') {
            return `<li class="aipkit_usage_state"><p>${escapeHtml(__('Nothing needs you right now.', 'gpt3-ai-content-generator'))}</p></li>`;
        }
        if (state.filter) {
            return `<li class="aipkit_usage_state"><p>${escapeHtml(__('Nothing here yet.', 'gpt3-ai-content-generator'))}</p></li>`;
        }
        if (!state.connected) {
            return `
                <li class="aipkit_usage_state aipkit_usage_website_empty">
                    <span class="aipkit_usage_feed_icon is-website" aria-hidden="true">W</span>
                    <p><strong>${escapeHtml(__('Website management is off', 'gpt3-ai-content-generator'))}</strong></p>
                    <p>${escapeHtml(__('Let ChatGPT or Claude read posts, write drafts and publish with your approval.', 'gpt3-ai-content-generator'))}</p>
                    <button type="button" class="aipkit_usage_btn aipkit_usage_btn--primary aipkit_usage_btn--small" data-aipkit-cloud-open="website" aria-haspopup="dialog">${escapeHtml(__('Set up', 'gpt3-ai-content-generator'))}</button>
                </li>
            `;
        }
        return `
            <li class="aipkit_usage_state aipkit_usage_website_empty">
                <span class="aipkit_usage_feed_icon is-website" aria-hidden="true">W</span>
                <p><strong>${escapeHtml(__('No website activity yet', 'gpt3-ai-content-generator'))}</strong></p>
                <p>${escapeHtml(__('When ChatGPT or Claude reads or changes this site, each action shows up here.', 'gpt3-ai-content-generator'))}</p>
            </li>
        `;
    };

    const syncChipCount = () => {
        const count = container.querySelector('[data-aipkit-usage-view="website"] [data-aipkit-usage-count]');
        if (count) {
            count.textContent = state.counts ? formatNumber(state.counts.all) : '';
        }
    };

    const request = (page, perPage) => window.aipkit_apiRequest('aipkit_mcp_activity', { filter: state.filter, search: getSearch(), page, per_page: perPage });

    const load = async (reset = true) => {
        const sequence = ++state.sequence;
        if (reset) {
            state.page = 1;
            state.rows.clear();
            list.setAttribute('aria-busy', 'true');
            list.innerHTML = Array.from({ length: 5 }, () => '<li class="aipkit_usage_feed_skeleton" aria-hidden="true"><span class="aipkit_usage_skeleton"></span><span class="aipkit_usage_skeleton"></span></li>').join('');
            if (more) more.hidden = true;
        } else if (more) {
            more.disabled = true;
        }
        try {
            const response = await request(state.page, PER_PAGE);
            if (sequence !== state.sequence) return;
            const items = Array.isArray(response.items) ? response.items : [];
            items.forEach((item) => state.rows.set(item.id, item));
            state.counts = response.counts || null;
            state.connected = response.connected !== false;
            state.loaded = true;
            if (reset) {
                list.innerHTML = items.length ? render(items) : empty();
                const total = state.filter === 'changes' ? state.counts?.changes : state.filter === 'reads' ? state.counts?.reads : state.filter === 'attention' ? state.counts?.attention : state.counts?.all;
                announce(sprintf(__('%1$s: %2$s results', 'gpt3-ai-content-generator'), __('Website', 'gpt3-ai-content-generator'), formatNumber(getSearch() ? items.length : total || 0)));
            } else {
                list.insertAdjacentHTML('beforeend', render(items));
            }
            if (more) more.hidden = !response.has_more;
            syncChipCount();
            deps.onCounts?.();
        } catch (error) {
            if (sequence !== state.sequence) return;
            if (reset) {
                list.innerHTML = `<li>${deps.loadErrorHtml()}</li>`;
            } else {
                state.page = Math.max(1, state.page - 1);
                showStatus(__('More activity couldn’t load. Try again.', 'gpt3-ai-content-generator'), 'error');
            }
        } finally {
            if (sequence === state.sequence) {
                list.removeAttribute('aria-busy');
                if (more) more.disabled = false;
            }
        }
    };

    /** Fills the chip count without rendering, so the chip shows its number before the view is opened. */
    const prefetchCounts = async () => {
        try {
            const response = await request(1, 1);
            if (!state.loaded) {
                state.counts = response.counts || null;
                state.connected = response.connected !== false;
                syncChipCount();
                deps.onCounts?.();
            }
        } catch (error) {
            // The count stays empty; opening the view loads it again.
        }
    };

    const markActive = () => {
        list.querySelectorAll('[data-website-op]').forEach((button) => button.classList.toggle('is-active', button.dataset.websiteOp === state.selected));
    };

    const openDetail = (id, opener) => {
        const item = state.rows.get(id);
        if (!item || !elements.detailDrawer) return;
        state.selected = id;
        markActive();
        elements.detailTitle.textContent = item.title;
        elements.detailStatus.className = `aipkit_usage_pill ${TONES[item.pill?.tone] ?? ''}`;
        elements.detailStatus.textContent = item.pill?.label || '';
        elements.detailStatus.hidden = false;
        if (elements.detailSub) elements.detailSub.textContent = [item.assistant, formatTimestamp(item.created_at)].filter(Boolean).join(' · ');
        if (elements.detailMeta) {
            const rows = [
                [__('Assistant', 'gpt3-ai-content-generator'), item.assistant],
                [__('Safety level', 'gpt3-ai-content-generator'), item.level],
                [__('Acted as', 'gpt3-ai-content-generator'), item.acting_user],
                [__('Website actions', 'gpt3-ai-content-generator'), formatNumber(item.actions || 0)],
                [__('Reference', 'gpt3-ai-content-generator'), item.id.slice(0, 8) + '…' + item.id.slice(-4)],
            ].filter((pair) => pair[1] !== '' && pair[1] !== undefined && pair[1] !== null);
            elements.detailMeta.innerHTML = rows.map(([label, value]) => `<div><dt>${escapeHtml(label)}</dt><dd>${escapeHtml(value)}</dd></div>`).join('');
            elements.detailMeta.hidden = false;
        }
        const steps = [[__('Requested', 'gpt3-ai-content-generator'), item.created_at, 'is-ok']];
        if (item.state === 'pending_approval') {
            steps.push([__('Waiting for your approval', 'gpt3-ai-content-generator'), 0, 'is-warning']);
        } else if (item.updated_at && item.state !== 'received') {
            steps.push([item.owner_review ? sprintf(__('%s, after your review', 'gpt3-ai-content-generator'), item.pill?.label || '') : item.pill?.label || '', item.updated_at, TONES[item.pill?.tone] || 'is-muted']);
        }
        // The drawer body has no side padding of its own: each detail view pads itself, like conversations and tasks.
        elements.detailPanel.innerHTML = `
            <div class="aipkit_usage_website_detail">
                <ol class="aipkit_usage_website_steps">
                    ${steps.map(([label, ts, tone]) => `<li class="${tone}"><span>${escapeHtml(label)}</span>${ts ? timeHtml(ts) : ''}</li>`).join('')}
                </ol>
                ${item.subject ? `<dl class="aipkit_usage_kv aipkit_usage_kv--single"><div><dt>${escapeHtml(__('About', 'gpt3-ai-content-generator'))}</dt><dd>${escapeHtml(item.subject)}</dd></div></dl>` : ''}
                ${item.failure ? `<p class="aipkit_usage_website_failure"><strong>${escapeHtml(item.failure.code)}</strong><br>${escapeHtml(item.failure.message)}</p>` : ''}
                <p class="aipkit_usage_website_note">${escapeHtml(__('Shows a summary only. The full request and response stay private and aren’t displayed.', 'gpt3-ai-content-generator'))}</p>
            </div>
        `;
        if (elements.detailFooter) {
            const links = Array.isArray(item.links) ? item.links : [];
            elements.detailFooter.innerHTML = links.map((link, index) => `
                <a class="aipkit_usage_btn${index === 0 && link.type === 'review' ? ' aipkit_usage_btn--primary' : ''}" href="${escapeHtml(link.url)}">${escapeHtml(link.label)}</a>
            `).join('') + `<button type="button" class="aipkit_usage_btn" data-aipkit-cloud-open="website" aria-haspopup="dialog">${escapeHtml(sprintf(__('Manage %s', 'gpt3-ai-content-generator'), item.assistant))}</button>`;
            elements.detailFooter.hidden = false;
        }
        openDrawer(elements.detailDrawer, opener);
    };

    list.addEventListener('click', (event) => {
        const group = event.target.closest('[data-website-group]');
        if (group) {
            const open = group.getAttribute('aria-expanded') !== 'true';
            group.setAttribute('aria-expanded', open ? 'true' : 'false');
            const items = group.parentElement.querySelector('.aipkit_usage_website_group_items');
            if (items) items.hidden = !open;
            return;
        }
        const button = event.target.closest('[data-website-op]');
        if (button) openDetail(button.dataset.websiteOp, button);
    });
    more?.addEventListener('click', () => {
        state.page += 1;
        load(false);
    });

    return {
        load,
        prefetchCounts,
        loadBreakdown,
        /** The Website sub-filters, for the Usage page's sub-chip row. */
        subchips: () => ({
            key: 'website',
            current: state.filter,
            label: __('Filter website activity', 'gpt3-ai-content-generator'),
            // A filter with nothing in it has no chip; All and the open filter always stay.
            items: [
                { value: '', label: __('All', 'gpt3-ai-content-generator'), count: state.counts?.all },
                { value: 'changes', label: __('Changes', 'gpt3-ai-content-generator'), count: state.counts?.changes },
                { value: 'reads', label: __('Reads', 'gpt3-ai-content-generator'), count: state.counts?.reads },
                { value: 'attention', label: __('Needs you', 'gpt3-ai-content-generator'), count: state.counts?.attention },
            ].filter((item) => item.value === '' || item.value === state.filter || item.count == null || Number(item.count) > 0),
        }),
        setFilter: (value) => {
            state.filter = ['changes', 'reads', 'attention'].includes(value) ? value : '';
        },
        count: () => (state.counts ? state.counts.all : null),
        /** Everything: one period of website actions to merge with conversations and content, newest first. */
        everything: (page, perPage, days) => window.aipkit_apiRequest('aipkit_mcp_activity', { search: getSearch(), page, per_page: perPage, days }),
        /** Everything renders website rows with the same markup and opens them in the same drawer. */
        renderRow: (item) => {
            state.rows.set(item.id, item);
            return row(item);
        },
        openDetail,
        clearActive: () => {
            state.selected = '';
            markActive();
        },
    };
}
