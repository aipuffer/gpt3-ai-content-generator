import { createMergedActivity } from './merged-activity.js';
import { summarizeRequestPayload } from './request-payload-summary.js';
import { bindCloudConnection, refreshCloudCredits, refreshWebsiteActions } from '../settings/cloud-connection.js';
import { createWebsiteActivity } from './website-activity.js';

(function () {
    'use strict';

    const DEFAULT_DAYS = 30;
    const DEFAULT_PER_PAGE = 20;
    const FEED_PER_PAGE = 20;
    const LOGS_PER_PAGE_STORAGE_KEY = 'aipkit_stats_logs_per_page';
    const LOGS_PER_PAGE_OPTIONS = [10, 20, 50];
    const DAYS_STORAGE_KEY = 'aipkit_stats_days';
    const VIEW_STORAGE_KEY = 'aipkit_usage_view';
    const VALID_TABS = ['logs', 'requests', 'website', 'cloud', 'pricing', 'activity', 'balances', 'woocommerce'];
    const BILLING_TABS = ['pricing', 'activity', 'balances', 'woocommerce'];
    const VALID_DAY_VALUES = ['1', '7', '30', '90'];
    const VIEWS = ['everything', 'conversations', 'tasks', 'website', 'requests', 'errors'];
    const CONVERSATION_MODULES = ['', 'chat', 'chatbot'];
    const AUTOMATION_MODULES = ['content_writer_automation', 'autogpt'];
    const REFUSAL_CODES = ['word_banned', 'ip_banned'];
    const PRICING_PROVIDER_ORDER = ['openai', 'google', 'claude', 'openrouter', 'azure', 'deepseek', 'xai', 'ollama', 'replicate'];
    const PRICING_PROVIDER_LABELS = {
        openai: 'OpenAI',
        google: 'Google',
        claude: 'Anthropic',
        openrouter: 'OpenRouter',
        azure: 'Azure',
        deepseek: 'DeepSeek',
        xai: 'xAI',
        ollama: 'Ollama',
        replicate: 'Replicate',
    };

    const escapeHtml = (value) => {
        if (typeof window.aipkit_escapeHtml === 'function') {
            return window.aipkit_escapeHtml(value);
        }
        const text = value === null || value === undefined ? '' : String(value);
        return text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };

    const renderTablePlaceholder = (body, columns, message, isError = false) => {
        if (!body) {
            return;
        }
        body.innerHTML = `
            <tr>
                <td colspan="${columns}" class="aipkit_stats_table_placeholder${isError ? ' aipkit_text-danger' : ''}">${escapeHtml(message)}</td>
            </tr>
        `;
    };

    const formatNumber = (value) => {
        const numberValue = Number(value || 0);
        if (Number.isNaN(numberValue)) {
            return '0';
        }
        return numberValue.toLocaleString();
    };

    const copyTextToClipboard = async (value) => {
        const text = String(value || '');
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            await navigator.clipboard.writeText(text);
            return;
        }
        const helper = document.createElement('textarea');
        helper.value = text;
        helper.setAttribute('readonly', '');
        helper.style.position = 'fixed';
        helper.style.opacity = '0';
        document.body.appendChild(helper);
        helper.select();
        const copied = document.execCommand('copy');
        helper.remove();
        if (!copied) {
            throw new Error('Copy failed');
        }
    };

    const formatTimestamp = (timestamp) => {
        const ts = Number(timestamp);
        if (!ts) {
            return '';
        }
        const date = new Date(ts * 1000);
        if (Number.isNaN(date.getTime())) {
            return '';
        }
        return date.toLocaleString();
    };

    const formatMessageTime = (timestamp) => {
        const ts = Number(timestamp);
        if (!ts) {
            return '';
        }
        const date = new Date(ts * 1000);
        if (Number.isNaN(date.getTime())) {
            return '';
        }
        return date.toLocaleTimeString([], {
            hour: 'numeric',
            minute: '2-digit',
            second: '2-digit',
        });
    };

    const formatRelativeTimestamp = (timestamp) => {
        const ts = Number(timestamp);
        if (!ts) {
            return '';
        }
        const nowSeconds = Date.now() / 1000;
        const diffSeconds = Math.floor(nowSeconds - ts);
        if (Number.isNaN(diffSeconds) || diffSeconds < 0) {
            return formatTimestamp(ts);
        }
        if (diffSeconds < 60) {
            return 'Just now';
        }
        const ranges = [
            { unit: 'year', seconds: 31536000 },
            { unit: 'month', seconds: 2592000 },
            { unit: 'week', seconds: 604800 },
            { unit: 'day', seconds: 86400 },
            { unit: 'hour', seconds: 3600 },
            { unit: 'minute', seconds: 60 },
        ];
        const rtf = typeof Intl !== 'undefined' && Intl.RelativeTimeFormat
            ? new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' })
            : null;
        for (const range of ranges) {
            if (diffSeconds >= range.seconds) {
                const value = Math.floor(diffSeconds / range.seconds);
                if (rtf) {
                    return rtf.format(-value, range.unit);
                }
                return `${value} ${range.unit}${value !== 1 ? 's' : ''} ago`;
            }
        }
        return formatTimestamp(ts);
    };

    const normalizeMessageContent = (content) => {
        if (typeof content === 'string') {
            return content;
        }
        if (content === null || content === undefined) {
            return '';
        }
        try {
            return JSON.stringify(content, null, 2);
        } catch (error) {
            return String(content);
        }
    };

    const normalizeProviderKey = (value) => String(value || '').trim().toLowerCase();
    const normalizeShortcodeAttrValue = (value) => String(value || '')
        .trim()
        .replace(/\s+/g, ' ')
        .replace(/"/g, '\'');

    const buildPricingSelectionValue = (providerKey, modelId) => (
        `${encodeURIComponent(normalizeProviderKey(providerKey))}::${encodeURIComponent(String(modelId || ''))}`
    );

    const buildPricingUsageTypeValue = (moduleKey, operationKey) => (
        `${String(moduleKey || '')}::${String(operationKey || '')}`
    );

    const parsePricingUsageTypeValue = (value) => {
        const [moduleKey, operationKey] = String(value || '').split('::');
        return {
            module: moduleKey || 'chat',
            operation: operationKey || '',
        };
    };

    const getPricingProviderLabel = (providerKey) => {
        const normalizedKey = normalizeProviderKey(providerKey);
        if (PRICING_PROVIDER_LABELS[normalizedKey]) {
            return PRICING_PROVIDER_LABELS[normalizedKey];
        }

        return normalizedKey
            .split(/[_\s-]+/)
            .filter(Boolean)
            .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
            .join(' ');
    };

    function aipkit_initStats() {
        const container = document.getElementById('aipkit_stats_container');
        if (!container) {
            return;
        }
        if (container.dataset.aipkitUsageBound === 'true') {
            return;
        }
        container.dataset.aipkitUsageBound = 'true';
        bindCloudConnection(container);
        const closeHints = (except = null) => {
            container.querySelectorAll('[data-aipkit-usage-hint]').forEach((open) => {
                if (open !== except) {
                    open.setAttribute('aria-expanded', 'false');
                    open.setAttribute('data-dismissed', 'true');
                }
            });
        };
        // The account chip's menu (Change email, Disconnect). It stays open while the email is being changed.
        const accountMenu = {
            popover: () => container.querySelector('[data-account-popover]'),
            chip: () => container.querySelector('[data-account-chip]'),
            editing: () => Boolean(container.querySelector('[data-account-popover] [data-cloud-email-editor]:not([hidden])')),
            toggle(open) {
                const popover = this.popover();
                const chip = this.chip();
                if (!popover || !chip) return;
                popover.hidden = !open;
                chip.setAttribute('aria-expanded', open ? 'true' : 'false');
            },
        };
        container.addEventListener('click', (event) => {
            const chip = event.target.closest('[data-account-chip]');
            if (chip) accountMenu.toggle(chip.getAttribute('aria-expanded') !== 'true');
            // A card's renewal hint opens on hover and focus; a click or tap keeps it open (touch screens have no hover).
            const hint = event.target.closest('[data-aipkit-usage-hint]');
            closeHints(hint);
            if (hint) {
                const expanded = hint.getAttribute('aria-expanded') !== 'true';
                hint.setAttribute('aria-expanded', String(expanded));
                hint.setAttribute('data-dismissed', String(!expanded));
            }
        });
        for (const eventName of ['pointerover', 'focusin']) {
            container.addEventListener(eventName, (event) => {
                const hint = event.target.closest('[data-aipkit-usage-hint]');
                if (hint && !hint.contains(event.relatedTarget)) hint.removeAttribute('data-dismissed');
            });
        }
        container.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape' || !event.target.closest('[data-account-menu]') || accountMenu.popover()?.hidden !== false || accountMenu.editing()) return;
            event.preventDefault();
            accountMenu.toggle(false);
            accountMenu.chip()?.focus({ preventScroll: true });
        });
        // Usage mounts the shared Cloud sheet, without the rest of Settings.
        window.aipkit_initCloudPanel?.();
        window.aipkit_initManageWebsite?.();
        window.aipkit_initAiProviderCards?.();
        window.aipkit_initSettingsSelectPickers?.();
        window.aipkit_initSettingsSavedData?.();
        window.aipkit_attachAutoSaveListeners?.();

        if (typeof window.aipkit_initContentWriterPopovers === 'function') {
            window.aipkit_initContentWriterPopovers(container);
        }
        const state = {
            days: parseInt(container.dataset.defaultDays || DEFAULT_DAYS, 10) || DEFAULT_DAYS,
            view: 'everything',
            group: '',
            botId: '',
            requestsSource: 'all',
            perPage: DEFAULT_PER_PAGE,
            page: 1,
            search: '',
            overview: null,
            overviewSequence: 0,
            logsSequence: 0,
            detailSequence: 0,
            logRows: new Map(),
            currentDetail: null,
            selectedLogId: null,
            selectedLogIds: new Set(),
            visibleLogs: [],
            pricingRules: [],
            activeTab: 'pricing',
            requestsPage: 1,
            selectedRequestIds: new Set(),
            deletingRequests: false,
            requestsSequence: 0,
            pricingManagementLoaded: false,
            creditsLoaded: false,
        };

        const __ = (wp.i18n && wp.i18n.__) ? wp.i18n.__ : (text) => text;
        const _n = (wp.i18n && wp.i18n._n) ? wp.i18n._n : (single, plural, number) => (Number(number) === 1 ? single : plural);

        const userCreditsState = {
            page: 1,
            search: '',
            botTitles: {},
            expandedUserId: '',
        };

        let searchTimer = null;
        let userSearchTimer = null;
        let balanceSaveTimer = null;
        let pricingModalResetTimer = null;
        let settingsSaveTimer = null;

        const elements = {
            status: container.querySelector('#aipkit_stats_status'),
            tabButtons: container.querySelectorAll('[data-aipkit-stats-tab]'),
            tabPanels: container.querySelectorAll('[data-aipkit-stats-panel]'),
            periodButtons: container.querySelectorAll('[data-aipkit-usage-days]'),
            periodToggle: container.querySelector('[data-aipkit-usage-period-toggle]'),
            periodMenu: container.querySelector('#aipkit_usage_period_menu'),
            periodCurrent: container.querySelector('[data-aipkit-usage-period-current]'),
            periodLabels: container.querySelectorAll('[data-aipkit-usage-period-label]'),
            viewChips: container.querySelectorAll('[data-aipkit-usage-view]'),
            viewPanels: container.querySelectorAll('[data-aipkit-usage-panel]'),
            subchips: container.querySelector('[data-aipkit-usage-subchips]'),
            announce: container.querySelector('[data-aipkit-usage-announce]'),
            cards: container.querySelector('[data-aipkit-usage-cards]'),
            aiCard: container.querySelector('[data-aipkit-usage-ai-card]'),
            websiteCard: container.querySelector('[data-aipkit-usage-website-card]'),
            header: container.querySelector('.aipkit_usage_header'),
            refresh: container.querySelector('[data-aipkit-dashboard-refresh]'),
            summaryCard: container.querySelector('[data-aipkit-usage-summary-card]'),
            summaryValues: container.querySelectorAll('[data-aipkit-usage-summary]'),
            feedList: container.querySelector('#aipkit_usage_feed_list'),
            feedFooter: container.querySelector('[data-aipkit-usage-feed-footer]'),
            feedMore: container.querySelector('#aipkit_usage_feed_more'),
            searchInput: container.querySelector('#aipkit_stats_search_input'),
            exportBtn: container.querySelector('#aipkit_stats_export_btn'),
            deleteAllBtn: container.querySelector('#aipkit_stats_delete_all_btn'),
            tableMenu: container.querySelector('#aipkit_stats_table_menu'),
            tableMenuTrigger: container.querySelector('#aipkit_stats_table_menu_trigger'),
            tableBody: container.querySelector('#aipkit_stats_table_body'),
            requestsBody: container.querySelector('#aipkit_stats_requests_body'),
            requestsPagination: container.querySelector('#aipkit_stats_requests_pagination'),
            requestsHeader: container.querySelector('#aipkit_stats_requests_header'),
            requestsSelection: container.querySelector('#aipkit_stats_requests_selection'),
            requestsSelectAll: container.querySelector('#aipkit_stats_requests_select_all'),
            requestsCount: container.querySelector('#aipkit_stats_requests_count'),
            requestsDelete: container.querySelector('#aipkit_stats_requests_delete'),
            requestsClear: container.querySelector('#aipkit_stats_requests_clear'),
            tableHeader: container.querySelector('#aipkit_stats_logs_table_header'),
            selectionHeader: container.querySelector('#aipkit_stats_logs_selection_header'),
            selectAllLogs: null,
            selectionCount: container.querySelector('#aipkit_stats_logs_selection_count'),
            bulkDeleteLogs: container.querySelector('#aipkit_stats_logs_bulk_delete'),
            clearLogSelection: container.querySelector('#aipkit_stats_logs_selection_clear'),
            pagination: container.querySelector('#aipkit_stats_pagination'),
            detailDrawer: container.querySelector('#aipkit_usage_detail_drawer'),
            detailTitle: container.querySelector('#aipkit_usage_detail_title'),
            detailStatus: container.querySelector('#aipkit_usage_detail_status'),
            detailSub: container.querySelector('#aipkit_usage_detail_sub'),
            detailMeta: container.querySelector('#aipkit_usage_detail_meta'),
            detailPanel: container.querySelector('#aipkit_stats_detail_panel'),
            detailFooter: container.querySelector('#aipkit_usage_detail_footer'),
            billingDrawer: container.querySelector('#aipkit_usage_billing_drawer'),
            ledgerDays: container.querySelector('#aipkit_stats_ledger_days_filter'),
            retentionOpen: document.getElementById('aipkit_stats_retention_open'),
            retentionModal: document.getElementById('aipkit_stats_retention_modal'),
            retentionModalClose: document.getElementById('aipkit_stats_retention_modal_close'),
            userSearchInput: document.getElementById('aipkit_stats_user_search'),
            userTableBody: document.getElementById('aipkit_stats_user_table_body'),
            userTableFoot: document.querySelector('#aipkit_stats_user_table tfoot'),
            userPagination: document.getElementById('aipkit_stats_user_pagination'),
            userNoResults: document.getElementById('aipkit_stats_user_no_results'),
            shortcodeSnippet: document.getElementById('aipkit_stats_shortcode_snippet'),
            shortcodeOptions: container.querySelectorAll('.aipkit_stats_shortcode_option'),
            shortcodeTextOptions: container.querySelectorAll('.aipkit_stats_shortcode_text_option'),
            customerDashboardUrl: container.querySelector('[name="cfg_dashboard_url"]'),
            customerBuyCreditsUrl: container.querySelector('[name="cfg_buycredits_url"]'),
            pricingOpen: document.getElementById('aipkit_stats_pricing_open'),
            pricingModal: document.getElementById('aipkit_stats_pricing_modal'),
            pricingModalClose: document.getElementById('aipkit_stats_pricing_modal_close'),
            pricingModalTitle: document.getElementById('aipkit_stats_pricing_modal_title'),
            pricingForm: document.getElementById('aipkit_stats_pricing_form'),
            pricingRuleId: document.getElementById('aipkit_stats_pricing_rule_id'),
            pricingUsageType: document.getElementById('aipkit_stats_pricing_usage_type'),
            pricingModule: document.getElementById('aipkit_stats_pricing_module'),
            pricingProvider: document.getElementById('aipkit_stats_pricing_provider'),
            pricingModel: document.getElementById('aipkit_stats_pricing_model'),
            pricingAiSelection: document.getElementById('aipkit_stats_pricing_ai_selection'),
            pricingOperation: document.getElementById('aipkit_stats_pricing_operation'),
            pricingBillingMethod: document.getElementById('aipkit_stats_pricing_billing_method'),
            pricingEnabled: document.getElementById('aipkit_stats_pricing_enabled'),
            pricingInputRate: document.getElementById('aipkit_stats_pricing_input_rate'),
            pricingOutputRate: document.getElementById('aipkit_stats_pricing_output_rate'),
            pricingUnitRate: document.getElementById('aipkit_stats_pricing_unit_rate'),
            pricingUnitRateLabel: document.getElementById('aipkit_stats_pricing_unit_rate_label'),
            pricingRateHelp: document.getElementById('aipkit_stats_pricing_rate_help'),
            pricingSave: document.getElementById('aipkit_stats_pricing_save'),
            pricingReset: document.getElementById('aipkit_stats_pricing_reset'),
            pricingRulesBody: document.getElementById('aipkit_stats_pricing_rules_body'),
            pricingRateFields: container.querySelectorAll('[data-rate-field]'),
            ledgerAdded: document.getElementById('aipkit_stats_ledger_added'),
            ledgerSpent: document.getElementById('aipkit_stats_ledger_spent'),
            ledgerQuotaOnly: document.getElementById('aipkit_stats_ledger_quota_only'),
            ledgerEntries: document.getElementById('aipkit_stats_ledger_entries'),
            ledgerBody: document.getElementById('aipkit_stats_ledger_body'),
        };

        const moduleLabels = (() => {
            try {
                const raw = container.dataset.moduleLabels || '{}';
                return JSON.parse(raw);
            } catch (error) {
                return {};
            }
        })();

        const isPro = container.dataset.isPro === '1';
        const userCreditsNonce = container.dataset.userCreditsNonce || '';
        const pricingOperationsByModule = {
            chat: [
                { value: 'chat', label: __('Chat', 'gpt3-ai-content-generator') },
                ...(isPro ? [{ value: 'live_voice', label: __('GPT Live voice', 'gpt3-ai-content-generator') }] : []),
            ],
            ai_forms: [
                { value: 'form_submit', label: __('Form submit', 'gpt3-ai-content-generator') },
            ],
            image_generator: [
                { value: 'generate', label: __('Generate', 'gpt3-ai-content-generator') },
                { value: 'edit', label: __('Edit', 'gpt3-ai-content-generator') },
                { value: 'video_generate', label: __('Video', 'gpt3-ai-content-generator') },
            ],
        };
        const pricingBillingMethodsByOperation = {
            live_voice: [{ value: 'per_minute', label: __('Per minute', 'gpt3-ai-content-generator') }],
            chat: [
                { value: 'per_1k_tokens', label: __('Per 1K tokens', 'gpt3-ai-content-generator') },
                { value: 'flat', label: __('Per request', 'gpt3-ai-content-generator') },
            ],
            form_submit: [
                { value: 'per_1k_tokens', label: __('Per 1K tokens', 'gpt3-ai-content-generator') },
                { value: 'flat', label: __('Per request', 'gpt3-ai-content-generator') },
            ],
            generate: [
                { value: 'per_image', label: __('Per image', 'gpt3-ai-content-generator') },
                { value: 'flat', label: __('Per request', 'gpt3-ai-content-generator') },
            ],
            edit: [
                { value: 'per_image', label: __('Per image', 'gpt3-ai-content-generator') },
                { value: 'flat', label: __('Per request', 'gpt3-ai-content-generator') },
            ],
            video_generate: [
                { value: 'per_video', label: __('Per video', 'gpt3-ai-content-generator') },
                { value: 'flat', label: __('Per request', 'gpt3-ai-content-generator') },
            ],
        };

        const getPricingModelCatalog = (moduleKey, operationKey) => {
            if (moduleKey === 'chat' && operationKey === 'live_voice') {
                return { openai: [{ id: 'gpt-live-1', name: 'GPT Live' }] };
            }
            if (moduleKey === 'image_generator') {
                if (operationKey === 'video_generate') {
                    return window.aipkit_dashboard?.imageGeneratorVideoModels || {};
                }
                return window.aipkit_dashboard?.imageGeneratorModels || {};
            }

            return window.aipkit_dashboard?.models || {};
        };

        const getPricingProviderEntries = (catalog) => {
            if (!catalog || typeof catalog !== 'object') {
                return [];
            }

            return Object.keys(catalog)
                .map((key) => normalizeProviderKey(key))
                .filter(Boolean)
                .sort((a, b) => {
                    const aIndex = PRICING_PROVIDER_ORDER.indexOf(a);
                    const bIndex = PRICING_PROVIDER_ORDER.indexOf(b);

                    if (aIndex === -1 && bIndex === -1) {
                        return a.localeCompare(b);
                    }
                    if (aIndex === -1) {
                        return 1;
                    }
                    if (bIndex === -1) {
                        return -1;
                    }

                    return aIndex - bIndex;
                })
                .map((key) => ({
                    key,
                    label: getPricingProviderLabel(key),
                }));
        };

        const appendUniquePricingModelItem = (items, seen, providerKey, model) => {
            if (!model || typeof model !== 'object' || !model.id) {
                return;
            }

            const modelId = String(model.id);
            if (seen.has(modelId)) {
                return;
            }

            seen.add(modelId);
            items.push({
                provider: providerKey,
                id: modelId,
                label: String(model.name || model.id),
            });
        };

        const getPricingModelItems = (moduleKey, providerKey, providerModelsData, recommendedList) => {
            const items = [];
            const seen = new Set();

            if (moduleKey !== 'image_generator' && Array.isArray(recommendedList)) {
                recommendedList.forEach((model) => appendUniquePricingModelItem(items, seen, providerKey, model));
            }

            if (
                moduleKey !== 'image_generator'
                && providerKey === 'openai'
                && providerModelsData
                && typeof providerModelsData === 'object'
                && !Array.isArray(providerModelsData)
            ) {
                Object.keys(providerModelsData).forEach((groupName) => {
                    const groupItems = providerModelsData[groupName];
                    if (Array.isArray(groupItems)) {
                        groupItems.forEach((model) => appendUniquePricingModelItem(items, seen, providerKey, model));
                    }
                });

                return items;
            }

            if (Array.isArray(providerModelsData)) {
                providerModelsData.forEach((model) => appendUniquePricingModelItem(items, seen, providerKey, model));
            }

            return items;
        };

        const findPricingOptionByProviderAndModel = (select, providerKey, modelId) => {
            if (!select || !select.options) {
                return null;
            }

            return Array.from(select.options).find(
                (option) => normalizeProviderKey(option.dataset.provider) === normalizeProviderKey(providerKey)
                    && String(option.dataset.model || '') === String(modelId || '')
            ) || null;
        };

        const findFirstPricingOptionForProvider = (select, providerKey) => {
            if (!select || !select.options) {
                return null;
            }

            return Array.from(select.options).find(
                (option) => option.value && normalizeProviderKey(option.dataset.provider) === normalizeProviderKey(providerKey)
            ) || null;
        };

        const syncPricingAiFields = () => {
            if (!elements.pricingAiSelection || !elements.pricingProvider || !elements.pricingModel) {
                return;
            }

            const selectedOption = elements.pricingAiSelection.selectedOptions && elements.pricingAiSelection.selectedOptions.length
                ? elements.pricingAiSelection.selectedOptions[0]
                : null;

            elements.pricingProvider.value = normalizeProviderKey(selectedOption?.dataset?.provider || '');
            elements.pricingModel.value = String(selectedOption?.dataset?.model || '');
        };

        const appendMissingPricingSelection = (providerKey, modelId) => {
            if (!elements.pricingAiSelection || !providerKey || !modelId) {
                return null;
            }

            const optgroup = document.createElement('optgroup');
            optgroup.label = `${getPricingProviderLabel(providerKey)} ${__('(Saved rule)', 'gpt3-ai-content-generator')}`;

            const option = new Option(
                String(modelId),
                buildPricingSelectionValue(providerKey, modelId)
            );
            option.dataset.provider = normalizeProviderKey(providerKey);
            option.dataset.model = String(modelId);
            option.dataset.providerLabel = getPricingProviderLabel(providerKey);
            optgroup.appendChild(option);
            elements.pricingAiSelection.appendChild(optgroup);

            return option;
        };

        const populatePricingAiSelection = (preferredProvider = '', preferredModel = '') => {
            if (!elements.pricingAiSelection || !elements.pricingProvider || !elements.pricingModel) {
                return;
            }

            const moduleKey = elements.pricingModule?.value || 'chat';
            const operationKey = elements.pricingOperation?.value || 'chat';
            const catalog = getPricingModelCatalog(moduleKey, operationKey);
            const providerEntries = getPricingProviderEntries(catalog);
            const currentProvider = normalizeProviderKey(preferredProvider || elements.pricingProvider.value);
            const currentModel = String(preferredModel || elements.pricingModel.value || '');

            elements.pricingAiSelection.innerHTML = '';

            let firstOption = null;

            providerEntries.forEach((providerEntry) => {
                const providerModelsData = catalog?.[providerEntry.key];
                const recommendedList = moduleKey === 'image_generator' || operationKey === 'live_voice'
                    ? []
                    : (window.aipkit_dashboard?.recommendedModels?.[providerEntry.key] || []);
                const modelItems = getPricingModelItems(
                    moduleKey,
                    providerEntry.key,
                    providerModelsData,
                    recommendedList
                ).filter((item) => operationKey === 'live_voice' || item.id !== 'gpt-live-1');

                if (!modelItems.length) {
                    return;
                }

                const optgroup = document.createElement('optgroup');
                optgroup.label = providerEntry.label;

                modelItems.forEach((modelItem) => {
                    const option = new Option(
                        modelItem.label,
                        buildPricingSelectionValue(modelItem.provider, modelItem.id)
                    );
                    option.dataset.provider = modelItem.provider;
                    option.dataset.model = modelItem.id;
                    option.dataset.providerLabel = providerEntry.label;
                    optgroup.appendChild(option);
                    if (!firstOption) {
                        firstOption = option;
                    }
                });

                elements.pricingAiSelection.appendChild(optgroup);
            });

            let preferredOption = null;

            if (!elements.pricingAiSelection.options.length && currentProvider && currentModel) {
                preferredOption = appendMissingPricingSelection(currentProvider, currentModel);
            }

            if (!elements.pricingAiSelection.options.length) {
                elements.pricingAiSelection.appendChild(
                    new Option(__('No synced models available', 'gpt3-ai-content-generator'), '')
                );
                elements.pricingAiSelection.disabled = true;
                elements.pricingProvider.value = '';
                elements.pricingModel.value = '';
                if (typeof window.aipkit_refreshSettingsSelectPickers === 'function') {
                    window.aipkit_refreshSettingsSelectPickers();
                }
                return;
            }

            preferredOption = preferredOption
                || (currentModel && findPricingOptionByProviderAndModel(elements.pricingAiSelection, currentProvider, currentModel))
                || findFirstPricingOptionForProvider(elements.pricingAiSelection, currentProvider)
                || firstOption;

            if (!preferredOption && currentProvider && currentModel) {
                preferredOption = appendMissingPricingSelection(currentProvider, currentModel);
            }

            elements.pricingAiSelection.disabled = false;

            if (preferredOption) {
                elements.pricingAiSelection.value = preferredOption.value;
            }

            syncPricingAiFields();

            if (typeof window.aipkit_refreshSettingsSelectPickers === 'function') {
                window.aipkit_refreshSettingsSelectPickers();
            }
        };

        const showStatus = (message, type = '') => {
            if (!elements.status) {
                return;
            }
            const statusEl = elements.status;
            const usesTrainingStyles = statusEl.classList.contains("aipkit_training_status");

            statusEl.textContent = message || '';

            statusEl.classList.remove(
                "aipkit_status_error",
                "aipkit_status_success",
                "aipkit_status_warning",
                "aipkit_status_loading"
            );

            if (usesTrainingStyles) {
                statusEl.classList.remove(
                    "is-visible",
                    "is-success",
                    "is-error",
                    "is-warning",
                    "is-loading"
                );
                if (message) {
                    statusEl.classList.add("is-visible");
                }
                if (type) {
                    statusEl.classList.add(`is-${type}`);
                }
                return;
            }

            statusEl.classList.remove("aipkit_form-help-success", "aipkit_form-help-error");
            if (type === "success") {
                statusEl.classList.add("aipkit_form-help-success");
            } else if (type === "error") {
                statusEl.classList.add("aipkit_form-help-error");
            }
        };

        const getStoredValue = (key) => {
            try {
                return localStorage.getItem(key);
            } catch (error) {
                return null;
            }
        };

        const setStoredValue = (key, value) => {
            try {
                if (value === null || value === undefined || value === '') {
                    localStorage.removeItem(key);
                } else {
                    localStorage.setItem(key, String(value));
                }
            } catch (error) {
                // Ignore storage failures.
            }
        };

        const readRequestedTab = () => {
            try {
                const params = new URLSearchParams(window.location.search || '');
                const requestedTab = String(params.get('aipkit_stats_tab') || '').trim();
                if (VALID_TABS.includes(requestedTab)) {
                    params.delete('aipkit_stats_tab');
                    const nextQuery = params.toString();
                    const nextUrl = `${window.location.pathname}${nextQuery ? `?${nextQuery}` : ''}${window.location.hash || ''}`;
                    window.history.replaceState(window.history.state, '', nextUrl);
                    return requestedTab;
                }
            } catch (error) {
                // Open the remembered view when the URL cannot be read.
            }
            return '';
        };

        const storedDays = getStoredValue(DAYS_STORAGE_KEY);
        if (storedDays && VALID_DAY_VALUES.includes(storedDays)) {
            state.days = parseInt(storedDays, 10);
        }
        const storedView = getStoredValue(VIEW_STORAGE_KEY);
        if (storedView && VIEWS.includes(storedView)) {
            state.view = storedView;
        }
        const storedLogsPerPage = parseInt(getStoredValue(LOGS_PER_PAGE_STORAGE_KEY), 10);
        state.perPage = LOGS_PER_PAGE_OPTIONS.includes(storedLogsPerPage)
            ? storedLogsPerPage
            : DEFAULT_PER_PAGE;

        const sprintf = (wp.i18n && wp.i18n.sprintf)
            ? wp.i18n.sprintf
            : (format, ...args) => {
                let index = 0;
                return String(format).replace(/%(\d+\$)?s/g, (match, position) => String(args[position ? parseInt(position, 10) - 1 : index++] ?? ''));
            };
        const PERIOD_LABELS = {
            1: __('Today', 'gpt3-ai-content-generator'),
            7: __('Last 7 days', 'gpt3-ai-content-generator'),
            30: __('Last 30 days', 'gpt3-ai-content-generator'),
            90: __('Last 90 days', 'gpt3-ai-content-generator'),
        };
        // "Used on this site" names the period only in its hover hint: the period picker already shows it.
        const SITE_HINT = [
            __('Successful website actions today, counted in UTC.', 'gpt3-ai-content-generator'),
            /* translators: %s: number of days in the selected period. */
            __('Successful website actions in the last %s days, counted in UTC.', 'gpt3-ai-content-generator'),
        ];
        const setPeriodLabels = () => {
            elements.periodLabels.forEach((label) => {
                label.textContent = PERIOD_LABELS[state.days] || '';
            });
            const siteHint = state.days === 1 ? SITE_HINT[0] : sprintf(SITE_HINT[1], formatNumber(state.days));
            container.querySelectorAll('[data-aipkit-usage-site-hint]').forEach((hint) => { hint.title = siteHint; });
        };
        const EMPTY_TEXT = {
            everything: [__('No conversations, content or website actions today.', 'gpt3-ai-content-generator'), __('No conversations, content or website actions in the last %s days.', 'gpt3-ai-content-generator')],
            conversations: [__('No conversations today.', 'gpt3-ai-content-generator'), __('No conversations in the last %s days.', 'gpt3-ai-content-generator')],
            tasks: [__('No content or task runs today.', 'gpt3-ai-content-generator'), __('No content or task runs in the last %s days.', 'gpt3-ai-content-generator')],
            requests: [__('No AI requests today.', 'gpt3-ai-content-generator'), __('No AI requests in the last %s days.', 'gpt3-ai-content-generator')],
            errors: [__('No errors today.', 'gpt3-ai-content-generator'), __('No errors in the last %s days.', 'gpt3-ai-content-generator')],
        };
        const emptyText = (view) => {
            if (state.search && view !== 'requests') {
                return __('Nothing matches your search.', 'gpt3-ai-content-generator');
            }
            const texts = EMPTY_TEXT[view] || EMPTY_TEXT.everything;
            return state.days === 1 ? texts[0] : sprintf(texts[1], state.days);
        };
        const plainText = (value) => String(value || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
        const truncate = (value, length) => {
            const text = plainText(value);
            return text.length > length ? `${text.slice(0, length - 1).trimEnd()}…` : text;
        };
        const timeHtml = (timestamp) => {
            const ts = Number(timestamp);
            return ts
                ? `<time class="aipkit_stats_relative_time" datetime="${escapeHtml(new Date(ts * 1000).toISOString())}" title="${escapeHtml(formatTimestamp(ts))}">${escapeHtml(formatRelativeTimestamp(ts))}</time>`
                : '—';
        };
        const loadErrorHtml = () => `
            <div class="aipkit_usage_state is-error">
                <p>${escapeHtml(__('Activity couldn’t load. Try again.', 'gpt3-ai-content-generator'))}</p>
                <button type="button" class="aipkit_usage_btn aipkit_usage_btn--small" data-aipkit-usage-retry>${escapeHtml(__('Retry', 'gpt3-ai-content-generator'))}</button>
            </div>
        `;
        const renderLoadError = (body, columns) => {
            if (body) {
                body.innerHTML = `<tr><td colspan="${columns}" class="aipkit_stats_table_placeholder">${loadErrorHtml()}</td></tr>`;
            }
        };
        const renderSkeletonRows = (body, columns, rows = 5) => {
            if (!body) {
                return;
            }
            body.innerHTML = Array.from({ length: rows }, () => `
                <tr class="aipkit_usage_skeleton_row" aria-hidden="true">
                    ${Array.from({ length: columns }, () => '<td><span class="aipkit_usage_skeleton"></span></td>').join('')}
                </tr>
            `).join('');
        };
        const announce = (message) => {
            if (elements.announce) {
                elements.announce.textContent = message || '';
            }
        };

        const formatResetDate = (timestamp) => {
            if (!timestamp) {
                return __('N/A', 'gpt3-ai-content-generator');
            }
            const date = new Date(timestamp * 1000);
            if (Number.isNaN(date.getTime())) {
                return __('N/A', 'gpt3-ai-content-generator');
            }
            return date.toLocaleDateString(undefined, {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
            });
        };

        const buildUserActivityItems = (user, titles) => {
            const items = [];
            const tokensUsed = user?.tokens_used || {};
            const lastReset = user?.last_reset || {};
            const imageUsage = user?.image_usage || { used: 0, last_reset: 0 };
            const aiFormsUsage = user?.ai_forms_usage || { used: 0, last_reset: 0 };

            if ((imageUsage.used || 0) > 0) {
                items.push({
                    type: 'module',
                    scopeType: 'image_generator',
                    scopeId: '',
                    category: __('Image generator', 'gpt3-ai-content-generator'),
                    label: __('Image generator', 'gpt3-ai-content-generator'),
                    used: Number(imageUsage.used || 0),
                    reset: Number(imageUsage.last_reset || 0),
                });
            }

            if ((aiFormsUsage.used || 0) > 0) {
                items.push({
                    type: 'module',
                    scopeType: 'ai_forms',
                    scopeId: '',
                    category: __('AI Forms', 'gpt3-ai-content-generator'),
                    label: __('AI Forms', 'gpt3-ai-content-generator'),
                    used: Number(aiFormsUsage.used || 0),
                    reset: Number(aiFormsUsage.last_reset || 0),
                });
            }

            Object.keys(tokensUsed)
                .map((botId) => ({
                    botId,
                    used: Number(tokensUsed[botId] || 0),
                }))
                .filter((item) => item.used > 0)
                .sort((a, b) => b.used - a.used)
                .forEach((item) => {
                    items.push({
                        type: 'chatbot',
                        scopeType: 'chatbot',
                        scopeId: item.botId,
                        category: '',
                        label: titles[item.botId] || `${__('Chatbot', 'gpt3-ai-content-generator')} #${item.botId}`,
                        used: item.used,
                        reset: Number(lastReset[item.botId] || 0),
                    });
                });

            return items;
        };

        const getLatestResetTimestamp = (items) => {
            if (!Array.isArray(items) || !items.length) {
                return 0;
            }

            return items.reduce((latest, item) => {
                const resetTs = Number(item?.reset || 0);
                return resetTs > latest ? resetTs : latest;
            }, 0);
        };

        const setExpandedUserDetail = (userId) => {
            if (!elements.userTableBody) {
                return;
            }

            const targetUserId = String(userId || '');
            let didExpand = false;

            elements.userTableBody.querySelectorAll('.aipkit_stats_user_detail_row').forEach((row) => {
                row.hidden = true;
            });
            elements.userTableBody.querySelectorAll('[data-user-activity-toggle]').forEach((button) => {
                button.setAttribute('aria-expanded', 'false');
            });

            if (targetUserId) {
                const detailRow = Array.from(elements.userTableBody.querySelectorAll('.aipkit_stats_user_detail_row'))
                    .find((row) => String(row.dataset.userDetailRow || '') === targetUserId);
                const detailBtn = Array.from(elements.userTableBody.querySelectorAll('[data-user-activity-toggle]'))
                    .find((button) => String(button.dataset.userActivityToggle || '') === targetUserId);

                if (detailRow && detailBtn) {
                    detailRow.hidden = false;
                    detailBtn.setAttribute('aria-expanded', 'true');
                    didExpand = true;
                }
            }

            userCreditsState.expandedUserId = didExpand ? targetUserId : '';
        };

        const renderUserCreditsTable = (users, botTitles) => {
            if (!elements.userTableBody) {
                return;
            }

            elements.userTableBody.innerHTML = '';

            if (!Array.isArray(users) || users.length === 0) {
                userCreditsState.expandedUserId = '';
                if (elements.userNoResults) {
                    elements.userNoResults.hidden = false;
                }
                return;
            }

            if (elements.userNoResults) {
                elements.userNoResults.hidden = true;
            }

            const titles = botTitles || {};

            users.forEach((user) => {
                const activityItems = buildUserActivityItems(user, titles);
                const latestReset = getLatestResetTimestamp(activityItems);
                const totalUsage = Number(user.total_used_all_bots || 0);
                const row = document.createElement('tr');
                row.className = 'aipkit_stats_user_row';
                row.dataset.userId = user.id;

                const userCell = document.createElement('td');
                userCell.className = 'aipkit_stats_user_identity_cell';
                userCell.innerHTML = `
                    <div class="aipkit_stats_user_identity">
                        <span class="aipkit_stats_user_name">${escapeHtml(user.display_name || __('Unknown user', 'gpt3-ai-content-generator'))}</span>
                        <span class="aipkit_stats_user_email">${escapeHtml(user.email || '')}</span>
                    </div>
                `;
                row.appendChild(userCell);

                const balanceCell = document.createElement('td');
                balanceCell.className = 'aipkit_stats_balance_cell';
                const balanceInput = document.createElement('input');
                balanceInput.type = 'number';
                balanceInput.className = 'aipkit_form-input aipkit_stats_credits_balance_input';
                balanceInput.value = user.token_balance || 0;
                balanceInput.dataset.userId = user.id;
                balanceInput.dataset.originalValue = user.token_balance || 0;
                balanceInput.setAttribute('min', '0');
                balanceInput.setAttribute('step', '1');
                balanceInput.title = __('Press Enter or blur to save changes', 'gpt3-ai-content-generator');
                balanceCell.appendChild(balanceInput);
                row.appendChild(balanceCell);

                const usageCell = document.createElement('td');
                usageCell.className = 'aipkit_stats_usage_summary_cell';
                usageCell.innerHTML = `
                    <div class="aipkit_stats_user_metric">
                        <span class="aipkit_stats_user_metric_value">${escapeHtml(formatNumber(totalUsage))}</span>
                        <span class="aipkit_stats_user_metric_meta">${
                            activityItems.length
                                ? escapeHtml(`${formatNumber(activityItems.length)} ${activityItems.length === 1 ? __('active scope', 'gpt3-ai-content-generator') : __('active scopes', 'gpt3-ai-content-generator')}`)
                                : escapeHtml(__('No periodic usage yet.', 'gpt3-ai-content-generator'))
                        }</span>
                    </div>
                `;
                row.appendChild(usageCell);

                const resetCell = document.createElement('td');
                resetCell.className = 'aipkit_stats_latest_reset_cell';
                resetCell.innerHTML = latestReset
                    ? `
                        <div class="aipkit_stats_user_metric">
                            <span class="aipkit_stats_user_metric_value">${escapeHtml(formatResetDate(latestReset))}</span>
                            <span class="aipkit_stats_user_metric_meta">${escapeHtml(__('Most recent', 'gpt3-ai-content-generator'))}</span>
                        </div>
                    `
                    : `<span class="aipkit_stats_user_metric_empty">${escapeHtml(__('No resets yet', 'gpt3-ai-content-generator'))}</span>`;
                row.appendChild(resetCell);

                const actionsCell = document.createElement('td');
                actionsCell.className = 'aipkit_stats_user_actions_cell';
                const actionsWrap = document.createElement('div');
                actionsWrap.className = 'aipkit_stats_user_actions';

                if (activityItems.length) {
                    const detailsBtn = document.createElement('button');
                    detailsBtn.type = 'button';
                    detailsBtn.className = 'aipkit_stats_icon_action aipkit_stats_user_details_btn';
                    detailsBtn.innerHTML = '<span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>';
                    detailsBtn.title = __('View usage details', 'gpt3-ai-content-generator');
                    detailsBtn.setAttribute('aria-label', __('View usage details', 'gpt3-ai-content-generator'));
                    detailsBtn.setAttribute('aria-expanded', 'false');
                    detailsBtn.dataset.userActivityToggle = String(user.id);
                    actionsWrap.appendChild(detailsBtn);

                    const resetAllBtn = document.createElement('button');
                    resetAllBtn.type = 'button';
                    resetAllBtn.className = 'aipkit_stats_icon_action aipkit_stats_user_reset_all_btn';
                    resetAllBtn.innerHTML = '<span class="dashicons dashicons-update-alt" aria-hidden="true"></span>';
                    resetAllBtn.title = __('Reset all usage', 'gpt3-ai-content-generator');
                    resetAllBtn.setAttribute('aria-label', __('Reset all usage', 'gpt3-ai-content-generator'));
                    resetAllBtn.dataset.userResetAllUsage = '1';
                    resetAllBtn.dataset.userId = String(user.id);
                    resetAllBtn.dataset.userName = user.display_name || '';
                    actionsWrap.appendChild(resetAllBtn);
                }

                if (user.purchase_history && user.purchase_history.length) {
                    const historyBtn = document.createElement('button');
                    historyBtn.type = 'button';
                    historyBtn.className = 'aipkit_stats_icon_action aipkit_stats_purchase_history_btn';
                    historyBtn.title = __('View purchase history', 'gpt3-ai-content-generator');
                    historyBtn.setAttribute('aria-label', __('View purchase history', 'gpt3-ai-content-generator'));
                    historyBtn.innerHTML = '<span class="dashicons dashicons-backup" aria-hidden="true"></span>';
                    historyBtn.dataset.userName = user.display_name;
                    historyBtn.dataset.history = JSON.stringify(user.purchase_history);
                    actionsWrap.appendChild(historyBtn);
                }

                if (actionsWrap.childElementCount > 0) {
                    actionsCell.appendChild(actionsWrap);
                }
                row.appendChild(actionsCell);

                elements.userTableBody.appendChild(row);

                if (activityItems.length) {
                    const detailRow = document.createElement('tr');
                    detailRow.className = 'aipkit_stats_user_detail_row';
                    detailRow.dataset.userDetailRow = String(user.id);
                    detailRow.hidden = true;

                    const detailCell = document.createElement('td');
                    detailCell.colSpan = 5;
                    detailCell.innerHTML = `
                        <div class="aipkit_stats_user_detail_panel">
                            <div class="aipkit_stats_user_detail_intro">
                                <div class="aipkit_stats_user_detail_title">${escapeHtml(__('Periodic usage details', 'gpt3-ai-content-generator'))}</div>
                                <div class="aipkit_stats_user_detail_copy">${escapeHtml(__('Review per-module usage and the latest reset recorded for each scope.', 'gpt3-ai-content-generator'))}</div>
                            </div>
                            <div class="aipkit_stats_user_activity_table_wrap">
                                <table class="aipkit_stats_user_activity_table">
                                    <thead>
                                        <tr>
                                            <th>${escapeHtml(__('Scope', 'gpt3-ai-content-generator'))}</th>
                                            <th>${escapeHtml(__('Used', 'gpt3-ai-content-generator'))}</th>
                                            <th>${escapeHtml(__('Last reset', 'gpt3-ai-content-generator'))}</th>
                                            <th>${escapeHtml(__('Action', 'gpt3-ai-content-generator'))}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${activityItems.map((item) => `
                                            <tr>
                                                <td>
                                                    <div class="aipkit_stats_user_activity_scope">
                                                        <span class="aipkit_stats_user_activity_scope_label">${escapeHtml(item.label)}</span>
                                                        ${item.category && item.category !== item.label
                                                            ? `<span class="aipkit_stats_user_activity_scope_kind">${escapeHtml(item.category)}</span>`
                                                            : ''}
                                                    </div>
                                                </td>
                                                <td class="aipkit_stats_user_activity_used">${escapeHtml(formatNumber(item.used))}</td>
                                                <td class="aipkit_stats_user_activity_reset">${escapeHtml(item.reset ? formatResetDate(item.reset) : __('No reset recorded', 'gpt3-ai-content-generator'))}</td>
                                                <td class="aipkit_stats_user_activity_action_cell">
                                                    <button
                                                        type="button"
                                                        class="aipkit_stats_icon_action aipkit_stats_user_activity_reset_btn"
                                                        data-user-reset-usage="1"
                                                        data-user-id="${escapeHtml(user.id)}"
                                                        data-scope-type="${escapeHtml(item.scopeType || '')}"
                                                        data-scope-id="${escapeHtml(item.scopeId || '')}"
                                                        data-scope-label="${escapeHtml(item.label || '')}"
                                                        aria-label="${escapeHtml(__('Reset usage', 'gpt3-ai-content-generator'))}"
                                                        title="${escapeHtml(__('Reset usage', 'gpt3-ai-content-generator'))}"
                                                    ><span class="dashicons dashicons-update-alt" aria-hidden="true"></span></button>
                                                </td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    `;
                    detailRow.appendChild(detailCell);
                    elements.userTableBody.appendChild(detailRow);
                }
            });

            if (userCreditsState.expandedUserId) {
                setExpandedUserDetail(userCreditsState.expandedUserId);
            }
        };

        const renderUserCreditsPagination = (paginationData) => {
            if (!elements.userPagination || !elements.userTableFoot) {
                return;
            }
            elements.userPagination.innerHTML = '';
            const totalUsers = Number(paginationData?.total_users || 0);
            const totalPages = Number(paginationData?.total_pages || 0);
            const currentPage = Number(paginationData?.current_page || 1);

            if (!totalUsers) {
                elements.userTableFoot.hidden = true;
                return;
            }

            const countSpan = document.createElement('span');
            countSpan.className = 'aipkit_pagination-count';
            countSpan.textContent = `${totalUsers.toLocaleString()} ${__('users', 'gpt3-ai-content-generator')}`;
            elements.userPagination.appendChild(countSpan);

            if (totalPages <= 1) {
                elements.userTableFoot.hidden = true;
                return;
            }

            const linksSpan = document.createElement('span');
            linksSpan.className = 'aipkit_pagination-links';

            const prevBtn = document.createElement('button');
            prevBtn.type = 'button';
            prevBtn.className = 'aipkit_btn aipkit_btn-secondary aipkit_btn-small';
            prevBtn.textContent = `\u00AB ${__('Previous', 'gpt3-ai-content-generator')}`;
            prevBtn.disabled = currentPage <= 1;
            prevBtn.addEventListener('click', () => fetchUserCredits(currentPage - 1, userCreditsState.search));

            const currentSpan = document.createElement('span');
            currentSpan.className = 'aipkit_pagination-current';
            currentSpan.textContent = `${__('Page', 'gpt3-ai-content-generator')} ${currentPage} ${__('of', 'gpt3-ai-content-generator')} ${totalPages}`;

            const nextBtn = document.createElement('button');
            nextBtn.type = 'button';
            nextBtn.className = 'aipkit_btn aipkit_btn-secondary aipkit_btn-small';
            nextBtn.textContent = `${__('Next', 'gpt3-ai-content-generator')} \u00BB`;
            nextBtn.disabled = currentPage >= totalPages;
            nextBtn.addEventListener('click', () => fetchUserCredits(currentPage + 1, userCreditsState.search));

            linksSpan.appendChild(prevBtn);
            linksSpan.appendChild(currentSpan);
            linksSpan.appendChild(nextBtn);
            elements.userPagination.appendChild(linksSpan);
            elements.userTableFoot.hidden = false;
        };

        const fetchUserCredits = async (page = 1, search = '') => {
            if (!elements.userTableBody || typeof window.aipkit_apiRequest !== 'function') {
                return;
            }
            renderTablePlaceholder(elements.userTableBody, 5, __('Loading user credits...', 'gpt3-ai-content-generator'));
            if (elements.userNoResults) {
                elements.userNoResults.hidden = true;
            }
            userCreditsState.page = page;
            userCreditsState.search = search;

            try {
                const response = await window.aipkit_apiRequest('aipkit_get_user_credits_data', {
                    page,
                    search,
                });
                userCreditsState.botTitles = response.bot_titles || {};
                renderUserCreditsTable(response.users_data || [], userCreditsState.botTitles);
                renderUserCreditsPagination(response.pagination || {});
                state.creditsLoaded = true;
            } catch (error) {
                state.creditsLoaded = false;
                if (elements.userTableBody) {
                    renderTablePlaceholder(elements.userTableBody, 5, error.message || __('Error loading user credits.', 'gpt3-ai-content-generator'), true);
                }
                if (elements.userNoResults) {
                    elements.userNoResults.hidden = true;
                }
                if (elements.userTableFoot) {
                    elements.userTableFoot.hidden = true;
                }
            }
        };

        const updateShortcodeSnippet = () => {
            if (!elements.shortcodeSnippet || !elements.shortcodeOptions) {
                return;
            }
            let showChatbot = true;
            let showAiForms = true;
            let showImageGenerator = true;
            let showBuyCredits = true;
            let showPurchaseHistory = true;

            elements.shortcodeOptions.forEach((option) => {
                if (option.name === 'cfg_show_chatbot') {
                    showChatbot = option.checked;
                }
                if (option.name === 'cfg_show_aiforms') {
                    showAiForms = option.checked;
                }
                if (option.name === 'cfg_show_imagegenerator') {
                    showImageGenerator = option.checked;
                }
                if (option.name === 'cfg_show_buycredits') {
                    showBuyCredits = option.checked;
                }
                if (option.name === 'cfg_show_purchasehistory') {
                    showPurchaseHistory = option.checked;
                }
            });

            const attributes = [];
            if (!showChatbot) {
                attributes.push('chatbot="false"');
            }
            if (!showAiForms) {
                attributes.push('aiforms="false"');
            }
            if (!showImageGenerator) {
                attributes.push('imagegenerator="false"');
            }
            if (!showBuyCredits) {
                attributes.push('buycredits="false"');
            }
            if (!showPurchaseHistory) {
                attributes.push('purchasehistory="false"');
            }

            if (elements.shortcodeTextOptions && elements.shortcodeTextOptions.length) {
                elements.shortcodeTextOptions.forEach((input) => {
                    const value = normalizeShortcodeAttrValue(input.value);
                    const defaultValue = normalizeShortcodeAttrValue(input.dataset.defaultValue);

                    if (!value || value === defaultValue) {
                        return;
                    }

                    if (input.name === 'cfg_dashboard_title') {
                        attributes.push(`title="${value}"`);
                    }
                    if (input.name === 'cfg_dashboard_intro') {
                        attributes.push(`intro="${value}"`);
                    }
                    if (input.name === 'cfg_buycredits_label') {
                        attributes.push(`buycreditslabel="${value}"`);
                    }
                    if (input.name === 'cfg_buycredits_url') {
                        attributes.push(`buycreditsurl="${value}"`);
                    }
                });
            }

            let shortcode = '[aipkit_token_usage';
            if (attributes.length) {
                shortcode += ` ${attributes.join(' ')}`;
            }
            shortcode += ']';
            elements.shortcodeSnippet.dataset.shortcode = shortcode;
            elements.shortcodeSnippet.title = shortcode;
            const shortcodeText = elements.shortcodeSnippet.querySelector('.aipkit_stats_shortcode_text');
            if (shortcodeText) {
                shortcodeText.textContent = shortcode;
            } else {
                elements.shortcodeSnippet.textContent = shortcode;
            }
        };

        const openPurchaseHistoryModal = (historyData, userName) => {
            const historyItems = Array.isArray(historyData) ? historyData : [];
            const safeUserName = String(userName || '').trim();
            const titleId = `aipkit_stats_purchase_modal_title_${Date.now()}`;
            const descriptionId = `aipkit_stats_purchase_modal_description_${Date.now()}`;
            const modalOverlay = document.createElement('div');
            modalOverlay.className = 'aipkit-modal-overlay aipkit_stats_purchase_history_modal aipkit-active';
            modalOverlay.setAttribute('aria-hidden', 'false');

            const buildHistoryHtml = () => {
                if (!historyItems.length) {
                    return `<div class="aipkit_stats_purchase_empty">${escapeHtml(__('No purchase history found for this user.', 'gpt3-ai-content-generator'))}</div>`;
                }
                return historyItems
                    .map((purchase) => {
                        const date = purchase?.date?.date ? new Date(purchase.date.date) : null;
                        const formattedDate = date && !Number.isNaN(date.getTime())
                            ? date.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
                            : '';
                        const productsHtml = (purchase.products || [])
                            .map((product) => `
                                <div class="aipkit_stats_purchase_product">
                                    <span class="aipkit_stats_purchase_product_name">${escapeHtml(product.name)}</span>
                                    <span class="aipkit_stats_purchase_product_meta">
                                        ${product.quantity > 1 ? `${Number(product.quantity)}x ` : ''}
                                        ${formatNumber(product.tokens_per_item)} ${escapeHtml(__('credits', 'gpt3-ai-content-generator'))}
                                    </span>
                                </div>
                            `)
                            .join('');
                        return `
                            <div class="aipkit_stats_purchase_item">
                                <div class="aipkit_stats_purchase_header">
                                    <div class="aipkit_stats_purchase_date">${escapeHtml(formattedDate)}</div>
                                    <div class="aipkit_stats_purchase_summary">
                                        <span class="aipkit_stats_purchase_tokens">+${formatNumber(purchase.tokens_granted)}</span>
                                        <span class="aipkit_stats_purchase_amount">${escapeHtml(purchase.total_amount)} ${escapeHtml(purchase.currency)}</span>
                                    </div>
                                </div>
                                <div class="aipkit_stats_purchase_order">${escapeHtml(__('Order #', 'gpt3-ai-content-generator'))}${escapeHtml(purchase.order_id)}</div>
                                ${productsHtml ? `<div class="aipkit_stats_purchase_products">${productsHtml}</div>` : ''}
                            </div>
                        `;
                    })
                    .join('');
            };

            modalOverlay.innerHTML = `
                <div class="aipkit-modal-content aipkit-modal-shell aipkit-modal-shell--compact aipkit_stats_purchase_modal_content" role="dialog" aria-modal="true" aria-labelledby="${titleId}" aria-describedby="${descriptionId}">
                    <div class="aipkit-modal-header aipkit-modal-shell-header">
                        <div class="aipkit-modal-shell-intro">
                            <h2 class="aipkit-modal-shell-title" id="${titleId}">${escapeHtml(__('Purchase history', 'gpt3-ai-content-generator'))}</h2>
                            <p class="aipkit-modal-shell-copy" id="${descriptionId}">
                                ${safeUserName
                                    ? `${escapeHtml(__('Review credit purchases for', 'gpt3-ai-content-generator'))} ${escapeHtml(safeUserName)}.`
                                    : escapeHtml(__('Review credit purchases for this user.', 'gpt3-ai-content-generator'))}
                            </p>
                        </div>
                        <button class="aipkit-modal-close-btn aipkit-modal-shell-close" type="button" aria-label="${escapeHtml(__('Close', 'gpt3-ai-content-generator'))}">
                            <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                        </button>
                    </div>
                    <div class="aipkit-modal-body aipkit-modal-shell-body aipkit_stats_purchase_modal_body">
                        <div class="aipkit_stats_purchase_history_list">${buildHistoryHtml()}</div>
                    </div>
                </div>
            `;

            const closeModal = () => {
                modalOverlay.classList.remove('aipkit-active');
                modalOverlay.setAttribute('aria-hidden', 'true');
                window.setTimeout(() => {
                    if (modalOverlay.parentNode) {
                        modalOverlay.parentNode.removeChild(modalOverlay);
                    }
                }, 200);
            };

            modalOverlay.addEventListener('click', (event) => {
                if (event.target === modalOverlay) {
                    closeModal();
                }
            });
            modalOverlay.querySelector('.aipkit-modal-close-btn')?.addEventListener('click', closeModal);

            container.appendChild(modalOverlay);
        };

        const handleBalanceUpdate = (input) => {
            if (!input) {
                return;
            }
            const userId = input.dataset.userId;
            const originalValue = String(input.dataset.originalValue || input.defaultValue || '0');
            const rawValue = String(input.value || '').trim();
            const newValue = rawValue === '' ? '0' : rawValue;

            if (String(newValue) === String(originalValue)) {
                if (rawValue === '') {
                    input.value = newValue;
                }
                return;
            }

            if (rawValue === '') {
                input.value = newValue;
            }

            if (!userCreditsNonce) {
                showStatus(__('Failed to save credit balance.', 'gpt3-ai-content-generator'), 'error');
                window.setTimeout(() => showStatus(''), 2500);
                return;
            }

            clearTimeout(balanceSaveTimer);
            balanceSaveTimer = setTimeout(() => {
                showStatus(__('Saving credit balance...', 'gpt3-ai-content-generator'), 'loading');
                window
                    .aipkit_apiRequest('aipkit_admin_update_token_balance', {
                        _ajax_nonce: userCreditsNonce,
                        user_id: userId,
                        balance: newValue,
                    })
                    .then((response) => {
                        showStatus(response.message || __('Credit balance updated successfully.', 'gpt3-ai-content-generator'), 'success');
                        input.value = response.new_balance;
                        input.dataset.originalValue = response.new_balance;
                        input.defaultValue = response.new_balance;
                        window.setTimeout(() => {
                            showStatus('');
                        }, 2000);
                    })
                    .catch((error) => {
                        showStatus(error.message || __('Failed to save credit balance.', 'gpt3-ai-content-generator'), 'error');
                        input.value = originalValue;
                        window.setTimeout(() => {
                            showStatus('');
                        }, 2500);
                    });
            }, 400);
        };

        const handleUsageReset = async (button) => {
            if (!button || button.disabled || typeof window.aipkit_apiRequest !== 'function') {
                return;
            }

            const userId = button.dataset.userId || '';
            const scopeType = button.dataset.scopeType || '';
            const scopeId = button.dataset.scopeId || '';
            const scopeLabel = button.dataset.scopeLabel || __('this scope', 'gpt3-ai-content-generator');

            if (!userId || !scopeType || !userCreditsNonce) {
                return;
            }

            const confirmed = await confirmDestructiveAction(
                `${__('This clears the current periodic usage for', 'gpt3-ai-content-generator')} ${scopeLabel} ${__('and records a new reset time. This cannot be undone.', 'gpt3-ai-content-generator')}`,
                {
                    title: __('Reset periodic usage', 'gpt3-ai-content-generator'),
                    confirmText: __('Reset usage', 'gpt3-ai-content-generator'),
                }
            );
            if (!confirmed) {
                return;
            }

            const originalContent = button.innerHTML;
            button.disabled = true;
            button.classList.add('is-resetting');
            button.setAttribute('aria-label', __('Resetting usage...', 'gpt3-ai-content-generator'));
            userCreditsState.expandedUserId = String(userId);

            try {
                await window.aipkit_apiRequest('aipkit_admin_reset_usage_scope', {
                    _ajax_nonce: userCreditsNonce,
                    user_id: userId,
                    scope_type: scopeType,
                    scope_id: scopeId,
                });
                await fetchUserCredits(userCreditsState.page, userCreditsState.search);
            } catch (error) {
                button.disabled = false;
                button.classList.remove('is-resetting');
                button.innerHTML = originalContent;
                button.setAttribute('aria-label', __('Reset usage', 'gpt3-ai-content-generator'));
                showStatus(error.message || __('Failed to reset usage.', 'gpt3-ai-content-generator'), 'error');
                window.setTimeout(() => showStatus(''), 2500);
            }
        };

        const handleResetAllUsage = async (button) => {
            if (!button || button.disabled || typeof window.aipkit_apiRequest !== 'function') {
                return;
            }

            const userId = button.dataset.userId || '';
            const userName = button.dataset.userName || __('this user', 'gpt3-ai-content-generator');

            if (!userId || !userCreditsNonce) {
                return;
            }

            const confirmed = await confirmDestructiveAction(
                `${__('This clears all current periodic usage for', 'gpt3-ai-content-generator')} ${userName} ${__('and records new reset times. This cannot be undone.', 'gpt3-ai-content-generator')}`,
                {
                    title: __('Reset all periodic usage', 'gpt3-ai-content-generator'),
                    confirmText: __('Reset all usage', 'gpt3-ai-content-generator'),
                }
            );
            if (!confirmed) {
                return;
            }

            const originalContent = button.innerHTML;
            button.disabled = true;
            button.classList.add('is-resetting');
            button.setAttribute('aria-label', __('Resetting usage...', 'gpt3-ai-content-generator'));
            userCreditsState.expandedUserId = '';

            try {
                await window.aipkit_apiRequest('aipkit_admin_reset_usage_scope', {
                    _ajax_nonce: userCreditsNonce,
                    user_id: userId,
                    scope_type: 'all',
                    scope_id: '',
                });
                await fetchUserCredits(userCreditsState.page, userCreditsState.search);
            } catch (error) {
                button.disabled = false;
                button.classList.remove('is-resetting');
                button.innerHTML = originalContent;
                button.setAttribute('aria-label', __('Reset all usage', 'gpt3-ai-content-generator'));
                showStatus(error.message || __('Failed to reset usage.', 'gpt3-ai-content-generator'), 'error');
                window.setTimeout(() => showStatus(''), 2500);
            }
        };

        const getPricingModuleLabel = (module) => {
            if (module === 'chat') {
                return __('Chatbot', 'gpt3-ai-content-generator');
            }
            if (module === 'ai_forms') {
                return __('AI Forms', 'gpt3-ai-content-generator');
            }
            if (module === 'image_generator') {
                return __('Image generator', 'gpt3-ai-content-generator');
            }
            return getModuleLabel(module);
        };

        const populatePricingUsageTypeOptions = (selectedValue) => {
            if (!elements.pricingUsageType || !elements.pricingModule) {
                return;
            }

            let firstValue = '';
            let nextValue = String(selectedValue || '');
            let hasSelectedValue = false;

            elements.pricingUsageType.innerHTML = Object.keys(pricingOperationsByModule)
                .map((moduleKey) => {
                    const options = pricingOperationsByModule[moduleKey] || [];
                    if (!options.length) {
                        return '';
                    }

                    const optionMarkup = options.map((option) => {
                        const optionValue = buildPricingUsageTypeValue(moduleKey, option.value);
                        if (!firstValue) {
                            firstValue = optionValue;
                        }
                        if (optionValue === nextValue) {
                            hasSelectedValue = true;
                        }

                        return `<option value="${escapeHtml(optionValue)}">${escapeHtml(option.label)}</option>`;
                    }).join('');

                    return `<optgroup label="${escapeHtml(getPricingModuleLabel(moduleKey))}">${optionMarkup}</optgroup>`;
                })
                .join('');

            if (!hasSelectedValue) {
                nextValue = firstValue;
            }

            if (!nextValue) {
                return;
            }

            elements.pricingUsageType.value = nextValue;

            const parsedValue = parsePricingUsageTypeValue(nextValue);
            elements.pricingModule.value = parsedValue.module;
            populatePricingOperationOptions(parsedValue.operation, false);
        };

        const populatePricingOperationOptions = (selectedOperation, syncUsageType = true) => {
            if (!elements.pricingModule || !elements.pricingOperation) {
                return;
            }
            const moduleKey = elements.pricingModule.value || 'chat';
            const options = pricingOperationsByModule[moduleKey] || [];
            const fallback = options[0]?.value || '';
            const nextValue = options.some((option) => option.value === selectedOperation)
                ? selectedOperation
                : fallback;

            elements.pricingOperation.innerHTML = options
                .map((option) => `<option value="${escapeHtml(option.value)}"${option.value === nextValue ? ' selected' : ''}>${escapeHtml(option.label)}</option>`)
                .join('');

            if (syncUsageType && elements.pricingUsageType) {
                elements.pricingUsageType.value = buildPricingUsageTypeValue(moduleKey, nextValue);
            }

            populatePricingBillingMethodOptions();
            populatePricingAiSelection();
        };

        const populatePricingBillingMethodOptions = (selectedBillingMethod) => {
            if (!elements.pricingOperation || !elements.pricingBillingMethod) {
                return;
            }
            const operation = elements.pricingOperation.value || 'chat';
            const options = pricingBillingMethodsByOperation[operation] || [{ value: 'flat', label: __('Per request', 'gpt3-ai-content-generator') }];
            const fallback = options[0]?.value || 'flat';
            const nextValue = options.some((option) => option.value === selectedBillingMethod)
                ? selectedBillingMethod
                : fallback;

            elements.pricingBillingMethod.innerHTML = options
                .map((option) => `<option value="${escapeHtml(option.value)}"${option.value === nextValue ? ' selected' : ''}>${escapeHtml(option.label)}</option>`)
                .join('');

            const billingMethod = elements.pricingBillingMethod.value || nextValue;
            const unitRatePresentation = {
                per_minute: {
                    label: __('Credits per minute', 'gpt3-ai-content-generator'),
                    placeholder: '1.00',
                    help: __('Voice time includes silence and a 15-second minimum. Charges use elapsed seconds, rounded up to whole credits per session. Reasoning uses the model’s Chat rule separately. Without a voice rule, duration is logged without a credit charge.', 'gpt3-ai-content-generator'),
                },
                flat: {
                    label: __('Rate per request', 'gpt3-ai-content-generator'),
                    placeholder: '1.00',
                    help: __('Charged once per completed request, regardless of its length.', 'gpt3-ai-content-generator'),
                },
                per_image: {
                    label: __('Rate per image', 'gpt3-ai-content-generator'),
                    placeholder: '1.00',
                    help: __('Charged for each generated or edited image.', 'gpt3-ai-content-generator'),
                },
                per_video: {
                    label: __('Rate per video', 'gpt3-ai-content-generator'),
                    placeholder: '5.00',
                    help: __('Charged for each generated video.', 'gpt3-ai-content-generator'),
                },
            };
            if (elements.pricingRateFields) {
                elements.pricingRateFields.forEach((field) => {
                    const fieldName = field.getAttribute('data-rate-field');
                    let shouldShow = false;
                    if (billingMethod === 'per_1k_tokens') {
                        shouldShow = fieldName === 'input_rate' || fieldName === 'output_rate';
                    } else if (['flat', 'per_image', 'per_video', 'per_minute'].includes(billingMethod)) {
                        shouldShow = fieldName === 'unit_rate';
                    }
                    field.hidden = !shouldShow;
                });
            }
            if (billingMethod === 'per_1k_tokens') {
                if (elements.pricingRateHelp) {
                    elements.pricingRateHelp.textContent = __('Set the credits charged per 1,000 input and output tokens.', 'gpt3-ai-content-generator');
                }
                return;
            }

            const unitPresentation = unitRatePresentation[billingMethod] || unitRatePresentation.flat;
            if (elements.pricingUnitRateLabel) {
                elements.pricingUnitRateLabel.textContent = unitPresentation.label;
            }
            if (elements.pricingUnitRate) {
                elements.pricingUnitRate.placeholder = unitPresentation.placeholder;
            }
            if (elements.pricingRateHelp) {
                elements.pricingRateHelp.textContent = unitPresentation.help;
            }
        };

        const resetPricingForm = () => {
            if (!elements.pricingForm) {
                return;
            }
            if (elements.pricingRuleId) {
                elements.pricingRuleId.value = '';
            }
            if (elements.pricingModule) {
                elements.pricingModule.value = 'chat';
            }
            if (elements.pricingProvider) {
                elements.pricingProvider.value = '';
            }
            if (elements.pricingModel) {
                elements.pricingModel.value = '';
            }
            if (elements.pricingEnabled) {
                elements.pricingEnabled.value = '1';
            }
            if (elements.pricingInputRate) {
                elements.pricingInputRate.value = '';
            }
            if (elements.pricingOutputRate) {
                elements.pricingOutputRate.value = '';
            }
            if (elements.pricingUnitRate) {
                elements.pricingUnitRate.value = '';
            }
            populatePricingUsageTypeOptions(buildPricingUsageTypeValue('chat', 'chat'));
            if (elements.pricingModalTitle) {
                elements.pricingModalTitle.textContent = __('New rule', 'gpt3-ai-content-generator');
            }
            if (elements.pricingSave) {
                elements.pricingSave.textContent = __('Save rule', 'gpt3-ai-content-generator');
            }
        };

        const closePricingModal = (shouldReset = true) => {
            if (pricingModalResetTimer) {
                window.clearTimeout(pricingModalResetTimer);
                pricingModalResetTimer = null;
            }

            if (elements.pricingModal) {
                elements.pricingModal.classList.remove('aipkit-active');
                elements.pricingModal.setAttribute('aria-hidden', 'true');
            }

            if (shouldReset) {
                pricingModalResetTimer = window.setTimeout(() => {
                    pricingModalResetTimer = null;
                    if (!elements.pricingModal || elements.pricingModal.classList.contains('aipkit-active')) {
                        return;
                    }
                    resetPricingForm();
                }, 220);
            }
        };

        const openPricingModal = (mode = 'new', rule = null) => {
            if (!elements.pricingModal || !elements.pricingForm) {
                return;
            }

            if (pricingModalResetTimer) {
                window.clearTimeout(pricingModalResetTimer);
                pricingModalResetTimer = null;
            }

            if (mode === 'edit' && rule) {
                fillPricingForm(rule);
                if (elements.pricingModalTitle) {
                    elements.pricingModalTitle.textContent = __('Edit rule', 'gpt3-ai-content-generator');
                }
                if (elements.pricingSave) {
                    elements.pricingSave.textContent = __('Update rule', 'gpt3-ai-content-generator');
                }
            } else {
                resetPricingForm();
            }

            elements.pricingModal.classList.add('aipkit-active');
            elements.pricingModal.setAttribute('aria-hidden', 'false');

            window.setTimeout(() => {
                elements.pricingUsageType?.focus();
            }, 0);
        };

        const closeRetentionModal = () => {
            if (!elements.retentionModal) {
                return;
            }

            elements.retentionModal.classList.remove('aipkit-active');
            elements.retentionModal.setAttribute('aria-hidden', 'true');
            elements.tableMenuTrigger?.focus({ preventScroll: true });
        };

        const openRetentionModal = () => {
            if (!elements.retentionModal) {
                return;
            }

            elements.retentionModal.classList.add('aipkit-active');
            elements.retentionModal.setAttribute('aria-hidden', 'false');
            // The paid controls (lib) listen for this event to reset and refresh their state.
            elements.retentionModal.dispatchEvent(new CustomEvent('aipkit:usage-retention-open', { bubbles: true }));
            fetchLogStorage();

            window.setTimeout(() => {
                const focusTarget = elements.retentionModal.querySelector('[data-aipkit-retention-focus]') || elements.retentionModalClose;
                focusTarget?.focus();
            }, 0);
        };

        // Shared by the free promotion and the paid controls: how many logs are stored and how old they are.
        const fetchLogStorage = async () => {
            const targets = elements.retentionModal?.querySelectorAll('[data-aipkit-log-storage]') || [];
            if (!targets.length || typeof window.aipkit_apiRequest !== 'function') {
                return;
            }
            try {
                const storage = await window.aipkit_apiRequest('aipkit_stats_get_log_storage', {});
                targets.forEach((target) => {
                    const field = target.dataset.aipkitLogStorage;
                    target.textContent = String(storage?.[field] || '');
                    target.hidden = !target.textContent;
                });
            } catch (error) {
                targets.forEach((target) => {
                    target.hidden = true;
                });
            }
        };

        const formatPricingRateValue = (value) => {
            const numericValue = Number(value || 0);
            if (!Number.isFinite(numericValue)) {
                return '0';
            }

            return numericValue.toFixed(6).replace(/\.?0+$/, '');
        };

        const formatPricingRuleRate = (rule) => {
            const method = rule?.billing_method || 'flat';
            if (method === 'per_1k_tokens') {
                const inputRate = formatPricingRateValue(rule?.input_rate || 0);
                const outputRate = formatPricingRateValue(rule?.output_rate || 0);
                return `${escapeHtml(__('Input', 'gpt3-ai-content-generator'))}: ${escapeHtml(inputRate)} / ${escapeHtml(__('Output', 'gpt3-ai-content-generator'))}: ${escapeHtml(outputRate)}`;
            }
            const unitRate = formatPricingRateValue(rule?.unit_rate || 0);
            const methodLabel = {
                flat: __('Per request', 'gpt3-ai-content-generator'),
                per_image: __('Per image', 'gpt3-ai-content-generator'),
                per_video: __('Per video', 'gpt3-ai-content-generator'),
                per_minute: __('Per minute', 'gpt3-ai-content-generator'),
            }[method] || method.replace(/_/g, ' ');
            return `${escapeHtml(methodLabel)}: ${escapeHtml(unitRate)}`;
        };

        const buildPricingRulePayload = (rule = {}) => {
            const billingMethod = rule.billing_method || elements.pricingBillingMethod?.value || '';
            const usesTokenRates = billingMethod === 'per_1k_tokens';
            const usesUnitRate = ['flat', 'per_image', 'per_video', 'per_minute'].includes(billingMethod);

            return {
                id: rule.id || elements.pricingRuleId?.value || '',
                module: rule.module || elements.pricingModule?.value || 'chat',
                provider: normalizeProviderKey(rule.provider || elements.pricingProvider?.value || ''),
                model: rule.model || elements.pricingModel?.value || '',
                operation: rule.operation || elements.pricingOperation?.value || '',
                billing_method: billingMethod,
                input_rate: usesTokenRates ? (rule.input_rate ?? elements.pricingInputRate?.value ?? '') : '',
                output_rate: usesTokenRates ? (rule.output_rate ?? elements.pricingOutputRate?.value ?? '') : '',
                unit_rate: usesUnitRate ? (rule.unit_rate ?? elements.pricingUnitRate?.value ?? '') : '',
                enabled: String(rule.enabled ?? elements.pricingEnabled?.value ?? '1') === '1' ? '1' : '0',
            };
        };

        const renderPricingRules = (rules) => {
            if (!elements.pricingRulesBody) {
                return;
            }

            state.pricingRules = Array.isArray(rules) ? rules : [];
            if (!state.pricingRules.length) {
                renderTablePlaceholder(elements.pricingRulesBody, 5, __('No pricing rules saved yet.', 'gpt3-ai-content-generator'));
                return;
            }

            elements.pricingRulesBody.innerHTML = state.pricingRules.map((rule) => {
                const providerModel = `
                    <div class="aipkit_stats_pricing_rule_title">${escapeHtml(rule.model || '')}</div>
                    <div class="aipkit_stats_pricing_rule_meta">${escapeHtml(rule.provider || '')}</div>
                `;
                const pricingMeta = `
                    <div class="aipkit_stats_pricing_rule_title">${escapeHtml(rule.operation === 'live_voice' ? __('GPT Live voice', 'gpt3-ai-content-generator') : rule.operation || '')}</div>
                    <div class="aipkit_stats_pricing_rule_meta">${formatPricingRuleRate(rule)}</div>
                `;
                const isEnabled = Number(rule.enabled) === 1;
                const statusClass = isEnabled ? 'is-enabled' : 'is-disabled';
                const statusLabel = isEnabled
                    ? __('Enabled', 'gpt3-ai-content-generator')
                    : __('Disabled', 'gpt3-ai-content-generator');
                const statusActionLabel = isEnabled
                    ? __('Disable pricing rule', 'gpt3-ai-content-generator')
                    : __('Enable pricing rule', 'gpt3-ai-content-generator');
                return `
                    <tr data-rule-id="${escapeHtml(rule.id)}">
                        <td>${escapeHtml(getPricingModuleLabel(rule.module))}</td>
                        <td>${providerModel}</td>
                        <td>${pricingMeta}</td>
                        <td>
                            <div class="aipkit_stats_pricing_status_cell">
                                <button
                                    type="button"
                                    class="aipkit_stats_status_pill ${statusClass}"
                                    data-pricing-toggle="enabled"
                                    data-rule-id="${escapeHtml(rule.id)}"
                                    aria-pressed="${isEnabled ? 'true' : 'false'}"
                                    aria-label="${escapeHtml(statusActionLabel)}"
                                    title="${escapeHtml(statusActionLabel)}"
                                >${escapeHtml(statusLabel)}</button>
                            </div>
                        </td>
                        <td>
                            <div class="aipkit_stats_pricing_actions_inline">
                                <button type="button" class="aipkit_stats_icon_action" data-pricing-action="edit" data-rule-id="${escapeHtml(rule.id)}" aria-label="${escapeHtml(__('Edit pricing rule', 'gpt3-ai-content-generator'))}" title="${escapeHtml(__('Edit', 'gpt3-ai-content-generator'))}"><span class="dashicons dashicons-edit" aria-hidden="true"></span></button>
                                <button type="button" class="aipkit_stats_icon_action aipkit_stats_icon_action--danger" data-pricing-action="delete" data-rule-id="${escapeHtml(rule.id)}" aria-label="${escapeHtml(__('Delete pricing rule', 'gpt3-ai-content-generator'))}" title="${escapeHtml(__('Delete', 'gpt3-ai-content-generator'))}"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>
                            </div>
                        </td>
                    </tr>
                `;
            }).join('');
        };

        const updatePricingRuleStatusUi = (ruleId, enabled) => {
            if (!elements.pricingRulesBody) {
                return;
            }

            const row = Array.from(elements.pricingRulesBody.querySelectorAll('tr[data-rule-id]'))
                .find((tableRow) => String(tableRow.getAttribute('data-rule-id')) === String(ruleId));

            if (!row) {
                return;
            }

            const statusControl = row.querySelector('[data-pricing-toggle="enabled"]');
            if (statusControl) {
                const isEnabled = !!enabled;
                const actionLabel = isEnabled
                    ? __('Disable pricing rule', 'gpt3-ai-content-generator')
                    : __('Enable pricing rule', 'gpt3-ai-content-generator');
                statusControl.classList.toggle('is-enabled', isEnabled);
                statusControl.classList.toggle('is-disabled', !isEnabled);
                statusControl.setAttribute('aria-pressed', isEnabled ? 'true' : 'false');
                statusControl.setAttribute('aria-label', actionLabel);
                statusControl.title = actionLabel;
                statusControl.textContent = isEnabled
                    ? __('Enabled', 'gpt3-ai-content-generator')
                    : __('Disabled', 'gpt3-ai-content-generator');
            }
        };

        const renderLedgerSummary = (summary) => {
            if (elements.ledgerAdded) {
                elements.ledgerAdded.textContent = formatNumber(summary?.credits_added || 0);
            }
            if (elements.ledgerSpent) {
                elements.ledgerSpent.textContent = formatNumber(summary?.credits_spent || 0);
            }
            if (elements.ledgerQuotaOnly) {
                elements.ledgerQuotaOnly.textContent = formatNumber(summary?.quota_only_usage_count || 0);
            }
            if (elements.ledgerEntries) {
                elements.ledgerEntries.textContent = formatNumber(summary?.total_entries || 0);
            }
        };

        const humanizeLedgerKey = (value) => String(value || '')
            .trim()
            .replace(/[_-]+/g, ' ')
            .replace(/\s+/g, ' ')
            .replace(/^\w/, (letter) => letter.toUpperCase());

        const getLedgerTypePresentation = (entry) => {
            const entryType = String(entry?.entry_type || '').toLowerCase();
            const delta = Number(entry?.credits_delta || 0);

            if (entryType === 'usage') {
                const qualifier = humanizeLedgerKey(entry?.operation || entry?.module || '');
                return {
                    className: 'is-usage',
                    label: qualifier
                        ? `${__('Usage', 'gpt3-ai-content-generator')} · ${qualifier}`
                        : __('Usage', 'gpt3-ai-content-generator'),
                };
            }

            if (entryType === 'purchase' || delta > 0) {
                return {
                    className: 'is-credit',
                    label: __('Credit added', 'gpt3-ai-content-generator'),
                };
            }

            if (delta < 0) {
                return {
                    className: 'is-debit',
                    label: __('Balance debited', 'gpt3-ai-content-generator'),
                };
            }

            return {
                className: 'is-neutral',
                label: humanizeLedgerKey(entryType) || __('Activity', 'gpt3-ai-content-generator'),
            };
        };

        const getLedgerContextPresentation = (entry) => {
            const model = String(entry?.model || '').trim();
            if (model) {
                return {
                    title: model,
                    meta: [
                        entry?.module ? getPricingModuleLabel(entry.module) : '',
                        entry?.provider ? getPricingProviderLabel(entry.provider) : '',
                    ].filter(Boolean).join(' · '),
                };
            }

            const entryType = String(entry?.entry_type || '').toLowerCase();
            if (entryType === 'purchase') {
                return {
                    title: __('WooCommerce top-up', 'gpt3-ai-content-generator'),
                    meta: '',
                };
            }

            if (entryType === 'admin_adjustment') {
                return {
                    title: __('Manual balance adjustment', 'gpt3-ai-content-generator'),
                    meta: '',
                };
            }

            return {
                title: entry?.module
                    ? getPricingModuleLabel(entry.module)
                    : humanizeLedgerKey(entry?.reference_type) || __('System activity', 'gpt3-ai-content-generator'),
                meta: '',
            };
        };

        const renderLedgerActivity = (entries) => {
            if (!elements.ledgerBody) {
                return;
            }

            const rows = Array.isArray(entries) ? entries : [];
            if (!rows.length) {
                renderTablePlaceholder(elements.ledgerBody, 6, __('No ledger activity found for this period.', 'gpt3-ai-content-generator'));
                return;
            }

            elements.ledgerBody.innerHTML = rows.map((entry) => {
                const delta = Number(entry.credits_delta || 0);
                const deltaClass = delta > 0 ? ' is-positive' : (delta < 0 ? ' is-negative' : '');
                const type = getLedgerTypePresentation(entry);
                const context = getLedgerContextPresentation(entry);
                const isUsage = String(entry.entry_type || '').toLowerCase() === 'usage';
                return `
                    <tr>
                        <td>${escapeHtml(formatRelativeTimestamp(entry.created_at_ts || 0))}</td>
                        <td>${escapeHtml(entry.actor_label || '')}</td>
                        <td>
                            <span class="aipkit_stats_ledger_type ${escapeHtml(type.className)}">${escapeHtml(type.label)}</span>
                        </td>
                        <td>
                            <div class="aipkit_stats_ledger_context_title">${escapeHtml(context.title)}</div>
                            ${context.meta ? `<div class="aipkit_stats_ledger_meta">${escapeHtml(context.meta)}</div>` : ''}
                            ${entry.billing_method === 'unpriced' ? `<div class="aipkit_stats_ledger_meta">${escapeHtml(__('No pricing rule — no credit charge', 'gpt3-ai-content-generator'))}</div>` : ''}
                        </td>
                        <td><span class="aipkit_stats_ledger_delta${deltaClass}">${escapeHtml(delta > 0 ? `+${formatNumber(delta)}` : formatNumber(delta))}</span></td>
                        <td>${isUsage ? escapeHtml(entry.operation === 'live_voice'
                            ? `${formatNumber(entry.usage_total_units || 0)} ${__('seconds', 'gpt3-ai-content-generator')}`
                            : formatNumber(entry.usage_total_units || 0)) : '&mdash;'}</td>
                    </tr>
                `;
            }).join('');
        };

        const requestFeature = (request) => {
            const labels = {
                text_chat: __('Text', 'gpt3-ai-content-generator'),
                embedding: __('Embeddings', 'gpt3-ai-content-generator'),
                image_generate: __('Image generation', 'gpt3-ai-content-generator'),
                image_edit: __('Image editing', 'gpt3-ai-content-generator'),
                speech_generate: __('Speech', 'gpt3-ai-content-generator'),
                transcribe: __('Transcription', 'gpt3-ai-content-generator'),
                chat: __('Chatbot', 'gpt3-ai-content-generator'),
            };
            const key = String(request.feature || '');
            return labels[key] || moduleLabels[key] || key.replace(/_/g, ' ') || __('AI request', 'gpt3-ai-content-generator');
        };

        const requestUsage = (request) => {
            const input = request.inputUnits;
            const output = request.outputUnits;
            const unit = String(request.unit || 'token');
            if (input !== null && output !== null && unit === 'token' && (Number(input) > 0 || Number(output) > 0)) {
                const cached = Number(request.cachedUnits || 0);
                return `${formatNumber(input)} ${__('in', 'gpt3-ai-content-generator')}${cached > 0 ? ` (${formatNumber(cached)} ${__('cached', 'gpt3-ai-content-generator')})` : ''} · ${formatNumber(output)} ${__('out', 'gpt3-ai-content-generator')}`;
            }
            const amount = Number(output) > 0 ? output : request.totalUnits;
            if (amount === null || amount === undefined) return '—';
            const unitLabel = unit === 'image' ? __('images', 'gpt3-ai-content-generator')
                : unit === 'character' ? __('characters', 'gpt3-ai-content-generator')
                    : unit === 'second' ? __('seconds', 'gpt3-ai-content-generator')
                        : __('tokens', 'gpt3-ai-content-generator');
            return `${formatNumber(amount)} ${unitLabel}`;
        };

        const requestCloudCredits = (request) => {
            if (request.source !== 'cloud' || request.chargedUnits === null || request.chargedUnits === undefined) return '';
            const units = Number(request.chargedUnits);
            if (!Number.isFinite(units) || units < 0) return '';
            if (units > 0 && units < 100) return '<0.1';
            return (Math.floor(units / 100) / 10).toLocaleString(undefined, { maximumFractionDigits: 1 });
        };

        const requestExactCredits = (request) => {
            if (request.source !== 'cloud' || request.chargedUnits === null || request.chargedUnits === undefined) return '';
            const units = Number(request.chargedUnits);
            if (!Number.isFinite(units) || units < 0) return '';
            return `${__('Exact credits used:', 'gpt3-ai-content-generator')} ${units / 1000}`;
        };

        const syncRequestSelection = () => {
            const count = state.selectedRequestIds.size;
            elements.requestsHeader.hidden = count > 0;
            elements.requestsSelection.hidden = count === 0;
            elements.requestsCount.textContent = `${formatNumber(count)} ${count === 1
                ? __('request selected', 'gpt3-ai-content-generator') : __('requests selected', 'gpt3-ai-content-generator')}`;
            const boxes = elements.requestsBody.querySelectorAll('[data-request-id]');
            boxes.forEach((box) => { box.checked = state.selectedRequestIds.has(Number(box.dataset.requestId)); });
            elements.requestsSelectAll.checked = boxes.length > 0 && count === boxes.length;
            elements.requestsSelectAll.indeterminate = count > 0 && count < boxes.length;
        };

        const fetchRequests = async (page = 1) => {
            if (!elements.requestsBody || typeof window.aipkit_apiRequest !== 'function') return;
            const sequence = ++state.requestsSequence;
            state.requestsPage = page;
            state.selectedRequestIds.clear();
            syncRequestSelection();
            renderSkeletonRows(elements.requestsBody, 5);
            if (elements.requestsPagination) elements.requestsPagination.innerHTML = '';
            try {
                const response = await window.aipkit_apiRequest('aipkit_stats_get_requests', {
                    days: state.days,
                    page,
                    source: state.requestsSource,
                });
                if (sequence !== state.requestsSequence) return;
                const requests = Array.isArray(response.requests) ? response.requests : [];
                if (!requests.length) {
                    renderTablePlaceholder(elements.requestsBody, 5, page > 1 ? __('No more requests.', 'gpt3-ai-content-generator') : emptyText('requests'));
                } else {
                    elements.requestsBody.innerHTML = requests.map((request) => {
                        const provider = String(request.providerLabel || request.provider || __('Unknown provider', 'gpt3-ai-content-generator'));
                        const model = String(request.modelLabel || request.model || '');
                        const time = Date.parse(String(request.createdAt || ''));
                        const cloudCredits = requestCloudCredits(request);
                        const exactCredits = requestExactCredits(request);
                        return `<tr>
                            <td class="aipkit_stats_log_select_cell"><input type="checkbox" class="aipkit_stats_log_checkbox" data-request-id="${Number(request.id)}" aria-label="${escapeHtml(__('Select request', 'gpt3-ai-content-generator'))}" /></td>
                            <td>${Number.isFinite(time) ? timeHtml(time / 1000) : '—'}</td>
                            <td>${escapeHtml(requestFeature(request))}</td>
                            <td><div class="aipkit_stats_ledger_context_title">${escapeHtml(model || provider)}</div>${model ? `<div class="aipkit_stats_ledger_meta">${escapeHtml(provider)}</div>` : ''}</td>
                            <td><div>${escapeHtml(requestUsage(request))}</div>${cloudCredits !== '' ? `<div class="aipkit_stats_ledger_meta" title="${escapeHtml(exactCredits)}">${escapeHtml(__('Credits used:', 'gpt3-ai-content-generator'))} ${escapeHtml(cloudCredits)}</div>` : ''}</td>
                        </tr>`;
                    }).join('');
                }
                if (elements.requestsPagination && typeof window.aipkit_renderLogsPagination === 'function') {
                    window.aipkit_renderLogsPagination({
                        cursor_mode: true,
                        current_page: page,
                        has_previous: page > 1,
                        has_more: Boolean(response.hasMore),
                        item_count: requests.length,
                    }, elements.requestsPagination, fetchRequests, { compactControls: true });
                    if (elements.requestsPagination.hasChildNodes()) {
                        const count = document.createElement('span');
                        count.className = 'aipkit_pagination-count';
                        count.textContent = `${formatNumber(requests.length)} ${requests.length === 1
                            ? __('request on this page', 'gpt3-ai-content-generator')
                            : __('requests on this page', 'gpt3-ai-content-generator')}`;
                        elements.requestsPagination.prepend(count);
                    }
                }
                syncRequestSelection();
            } catch (error) {
                if (sequence !== state.requestsSequence) return;
                renderLoadError(elements.requestsBody, 5);
            }
        };

        if (elements.requestsBody) {
            elements.requestsBody.addEventListener('change', (event) => {
                const box = event.target.closest('[data-request-id]');
                if (!box) return;
                const id = Number(box.dataset.requestId);
                if (box.checked) state.selectedRequestIds.add(id);
                else state.selectedRequestIds.delete(id);
                syncRequestSelection();
            });
            elements.requestsSelectAll.addEventListener('change', () => {
                const selected = elements.requestsSelectAll.checked;
                state.selectedRequestIds.clear();
                if (selected) elements.requestsBody.querySelectorAll('[data-request-id]').forEach((box) => state.selectedRequestIds.add(Number(box.dataset.requestId)));
                syncRequestSelection();
            });
            elements.requestsClear.addEventListener('click', () => {
                state.selectedRequestIds.clear(); syncRequestSelection();
            });
            elements.requestsDelete.addEventListener('click', async () => {
                if (state.deletingRequests || !state.selectedRequestIds.size) return;
                const ids = Array.from(state.selectedRequestIds);
                state.deletingRequests = true;
                elements.requestsDelete.disabled = true;
                try {
                    const confirmed = await confirmDestructiveAction(
                        __('Delete the selected request history? Credit balances and conversations will not change.', 'gpt3-ai-content-generator'),
                        { title: __('Delete requests', 'gpt3-ai-content-generator'), confirmText: __('Delete', 'gpt3-ai-content-generator') }
                    );
                    if (!confirmed) return;
                    await window.aipkit_apiRequest('aipkit_stats_delete_requests', { request_ids: ids });
                    await fetchRequests(1);
                    fetchOverview();
                } catch (error) {
                    showStatus(error.message || __('Could not delete request history.', 'gpt3-ai-content-generator'), 'error');
                } finally {
                    state.deletingRequests = false;
                    syncRequestSelection();
                }
            });
        }

        const fetchPricingManagement = async () => {
            if (typeof window.aipkit_apiRequest !== 'function') {
                return;
            }

            renderTablePlaceholder(elements.pricingRulesBody, 5, __('Loading pricing rules...', 'gpt3-ai-content-generator'));
            renderTablePlaceholder(elements.ledgerBody, 6, __('Loading ledger activity...', 'gpt3-ai-content-generator'));

            try {
                const response = await window.aipkit_apiRequest('aipkit_stats_get_pricing_management', {
                    days: parseInt(elements.ledgerDays?.value, 10) || DEFAULT_DAYS,
                });
                renderPricingRules(response.pricing_rules || []);
                renderLedgerSummary(response.ledger_summary || {});
                renderLedgerActivity(response.recent_activity || []);
                state.pricingManagementLoaded = true;
            } catch (error) {
                state.pricingManagementLoaded = false;
                renderTablePlaceholder(elements.pricingRulesBody, 5, error.message || __('Failed to load pricing rules.', 'gpt3-ai-content-generator'));
                renderTablePlaceholder(elements.ledgerBody, 6, error.message || __('Failed to load ledger activity.', 'gpt3-ai-content-generator'));
            }
        };

        const ensureTabData = (tabKey) => {
            if (tabKey === 'balances') {
                if (!state.creditsLoaded) {
                    fetchUserCredits(userCreditsState.page, userCreditsState.search);
                }
                return;
            }
            if ((tabKey === 'pricing' || tabKey === 'activity') && !state.pricingManagementLoaded) {
                fetchPricingManagement();
            }
        };

        // Visitor billing sections inside their drawer.
        const activateTab = (tabKey) => {
            const nextTab = BILLING_TABS.includes(tabKey) ? tabKey : 'pricing';
            state.activeTab = nextTab;

            elements.tabButtons.forEach((button) => {
                const isActive = button.dataset.aipkitStatsTab === nextTab;
                button.classList.toggle('aipkit_active', isActive);
                button.setAttribute('aria-selected', isActive ? 'true' : 'false');
                button.tabIndex = isActive ? 0 : -1;
            });

            elements.tabPanels.forEach((panel) => {
                panel.hidden = panel.dataset.aipkitStatsPanel !== nextTab;
            });

            ensureTabData(nextTab);
        };

        // One drawer at a time: focus moves in, stays inside, and returns to the opener.
        const globalListeners = new AbortController();
        const listenGlobal = (target, event, handler, options = {}) => {
            target.addEventListener(event, handler, { ...options, signal: globalListeners.signal });
        };
        listenGlobal(document, 'keydown', (event) => {
            if (event.key === 'Escape') closeHints();
        });
        // A click outside the Dashboard closes an open renewal hint.
        listenGlobal(document, 'click', (event) => {
            if (!container.contains(event.target)) closeHints();
        });
        // A click elsewhere closes the account menu (not while changing the email or answering a confirmation).
        listenGlobal(document, 'click', (event) => {
            if (accountMenu.popover()?.hidden !== false || accountMenu.editing()) return;
            if (event.target.closest?.('[data-account-menu], [role="dialog"], [role="alertdialog"]')) return;
            accountMenu.toggle(false);
        });
        listenGlobal(window, 'aipkit:module-rendered', () => {
            if (!container.isConnected) {
                globalListeners.abort();
                window.removeEventListener('aipkit:cloud-connection-changed', handleCloudConnectionChanged);
                ++state.overviewSequence;
                ++state.logsSequence;
                ++state.requestsSequence;
                ++state.detailSequence;
                window.clearTimeout(searchTimer);
                window.clearTimeout(userSearchTimer);
                window.clearTimeout(pricingModalResetTimer);
                window.clearTimeout(settingsSaveTimer);
                closeDrawer(false);
            }
        });

        const DRAWER_BODY_CLASS = 'aipkit_usage_drawer_open';
        const FOCUSABLE_SELECTOR = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
        const drawerState = { open: null, opener: null };
        // Set once the helpers it needs exist (see createWebsiteActivity below); null when the Website view isn't shown.
        let websiteActivity = null;

        const openDrawer = (overlay, opener = null) => {
            if (!overlay) {
                return;
            }
            if (drawerState.open && drawerState.open !== overlay) {
                closeDrawer(false);
            }
            drawerState.open = overlay;
            drawerState.opener = opener || (document.activeElement instanceof HTMLElement ? document.activeElement : null);
            overlay.hidden = false;
            document.body.classList.add(DRAWER_BODY_CLASS);
            window.requestAnimationFrame(() => {
                overlay.classList.add('is-open');
                const target = overlay.querySelector('[data-aipkit-usage-drawer-close]') || overlay.querySelector('[role="dialog"]');
                target?.focus({ preventScroll: true });
            });
        };

        const closeDrawer = (restoreFocus = true) => {
            const overlay = drawerState.open;
            if (!overlay) {
                return;
            }
            const opener = drawerState.opener;
            drawerState.open = null;
            drawerState.opener = null;
            overlay.classList.remove('is-open');
            overlay.hidden = true;
            document.body.classList.remove(DRAWER_BODY_CLASS);
            if (overlay === elements.detailDrawer) {
                state.detailSequence++;
                state.selectedLogId = null;
                state.currentDetail = null;
                markActiveRow();
                websiteActivity?.clearActive();
            }
            if (!restoreFocus) {
                return;
            }
            if (opener && opener.isConnected) {
                opener.focus({ preventScroll: true });
            } else {
                container.querySelector('.aipkit_usage_chip[aria-pressed="true"]')?.focus({ preventScroll: true });
            }
        };

        // Two cards, AI credits and Website actions (left | used here), each hidden when it has nothing to show.
        // With one card left, the period summary fills the second slot; with none, it is the only card.
        const syncCards = () => {
            const hasCloud = Boolean(elements.aiCard?.querySelector('#aipkit_usage_cloud_credits'));
            const usageHalf = elements.websiteCard?.querySelector('[data-aipkit-website-usage="usage"]');
            const hasUsed = Boolean(elements.websiteCard?.querySelector('[data-website-breakdown]:not([hidden])'));
            const historyPending = Boolean(elements.websiteCard?.querySelector('[data-website-breakdown-pending]'));
            // Turned off, the server renders the left half as the setup invitation, or hidden during a Cloud outage.
            const hasLeft = Boolean(usageHalf && !usageHalf.hidden);
            if (elements.refresh) elements.refresh.hidden = !(hasCloud || hasUsed);
            if (elements.aiCard) elements.aiCard.hidden = !hasCloud;
            if (elements.websiteCard) elements.websiteCard.hidden = !(hasLeft || hasUsed);
            const shown = (hasCloud ? 1 : 0) + (hasLeft || hasUsed ? 1 : 0);
            if (elements.summaryCard) {
                elements.summaryCard.hidden = historyPending || shown >= 2;
            }
            if (elements.cards) {
                elements.cards.dataset.cards = String(historyPending || shown >= 2 ? shown : shown + 1);
                // Beside the website card, AI credits takes a third of the row.
                elements.cards.dataset.layout = hasCloud && (hasLeft || hasUsed) ? 'credits-website' : '';
            }
        };

        listenGlobal(window, 'aipkit:website-usage-changed', syncCards);

        const handleCloudConnectionChanged = (event) => {
            if (!container.isConnected) {
                window.removeEventListener('aipkit:cloud-connection-changed', handleCloudConnectionChanged);
                return;
            }
            const connected = Boolean(event.detail?.connected);
            const template = document.createElement('template');
            template.innerHTML = String(event.detail?.usageHtml || '');
            const account = template.content.querySelector('#aipkit_usage_cloud_credits');
            if (connected && !account) return;
            const currentAccount = container.querySelector('#aipkit_usage_cloud_credits');
            const refreshingBalances = ['balance', 'refresh'].includes(event.detail?.action);
            if (currentAccount && account) currentAccount.replaceWith(account);
            else if (currentAccount) currentAccount.remove();
            else if (account && elements.aiCard) elements.aiCard.prepend(account);
            // The account line above the cards follows the same response (connected, confirming, reconnect, or Connect).
            const line = template.content.querySelector('#aipkit_usage_cloud_account');
            const currentLine = container.querySelector('#aipkit_usage_cloud_account');
            const editingEmail = refreshingBalances
                && currentLine?.querySelector('[data-cloud-email-editor]:not([hidden])');
            // A balance read keeps the account menu open if it was; an account change closes it.
            const menuOpen = refreshingBalances
                && currentLine?.querySelector('[data-account-popover]:not([hidden])');
            if (currentLine && line && !editingEmail) currentLine.replaceWith(line);
            else if (currentLine && !line) currentLine.remove();
            else if (!currentLine && line && elements.header) elements.header.append(line);
            if (menuOpen && line && !editingEmail) {
                line.querySelector('[data-account-popover]')?.removeAttribute('hidden');
                line.querySelector('[data-account-chip]')?.setAttribute('aria-expanded', 'true');
            }
            syncCards();

            if (event.detail?.action === 'balance') return;

            const headerHint = container.querySelector('.aipkit_stats_header_hint');
            if (headerHint) headerHint.textContent = account
                ? __('Your balances and what happened on this site.', 'gpt3-ai-content-generator')
                : __('What happened on this site.', 'gpt3-ai-content-generator');
            fetchOverview();
            if (state.view === 'requests') fetchRequests(1);
            if (!account) {
                elements.periodToggle?.focus({ preventScroll: true });
            } else if (event.detail?.action === 'refresh' && !editingEmail) {
                // The refresh button is re-enabled right after this response is applied; focus it then.
                queueMicrotask(() => elements.refresh?.focus({ preventScroll: true }));
            }
        };
        if (window.aipkit_statsCloudConnectionHandler) {
            window.removeEventListener('aipkit:cloud-connection-changed', window.aipkit_statsCloudConnectionHandler);
        }
        window.aipkit_statsCloudConnectionHandler = handleCloudConnectionChanged;
        listenGlobal(window, 'aipkit:cloud-connection-changed', handleCloudConnectionChanged);

        const fillPricingForm = (rule) => {
            if (!rule || !elements.pricingForm) {
                return;
            }
            if (elements.pricingRuleId) {
                elements.pricingRuleId.value = rule.id || '';
            }
            if (elements.pricingModule) {
                elements.pricingModule.value = rule.module || 'chat';
            }
            if (elements.pricingProvider) {
                elements.pricingProvider.value = normalizeProviderKey(rule.provider || '');
            }
            if (elements.pricingModel) {
                elements.pricingModel.value = rule.model || '';
            }
            if (elements.pricingEnabled) {
                elements.pricingEnabled.value = Number(rule.enabled) === 1 ? '1' : '0';
            }
            if (elements.pricingInputRate) {
                elements.pricingInputRate.value = rule.input_rate ?? '';
            }
            if (elements.pricingOutputRate) {
                elements.pricingOutputRate.value = rule.output_rate ?? '';
            }
            if (elements.pricingUnitRate) {
                elements.pricingUnitRate.value = rule.unit_rate ?? '';
            }
            populatePricingUsageTypeOptions(
                buildPricingUsageTypeValue(rule.module || 'chat', rule.operation || '')
            );
            populatePricingBillingMethodOptions(rule.billing_method || '');
            populatePricingAiSelection(rule.provider || '', rule.model || '');
        };

        const savePricingRule = async () => {
            if (typeof window.aipkit_apiRequest !== 'function' || !elements.pricingForm) {
                return;
            }
            const payload = buildPricingRulePayload();

            showStatus(__('Saving pricing rule...', 'gpt3-ai-content-generator'), 'loading');
            try {
                const response = await window.aipkit_apiRequest('aipkit_stats_save_pricing_rule', payload);
                await fetchPricingManagement();
                closePricingModal(true);
                showStatus(response.message || __('Pricing rule saved.', 'gpt3-ai-content-generator'), 'success');
                window.setTimeout(() => showStatus(''), 2000);
            } catch (error) {
                showStatus(error.message || __('Failed to save pricing rule.', 'gpt3-ai-content-generator'), 'error');
            }
        };

        const togglePricingRuleStatus = async (ruleId, nextEnabled, statusControl) => {
            if (!ruleId || typeof window.aipkit_apiRequest !== 'function') {
                return;
            }

            const rule = state.pricingRules.find((item) => String(item.id) === String(ruleId));
            if (!rule) {
                updatePricingRuleStatusUi(ruleId, !nextEnabled);
                return;
            }

            if (statusControl) {
                statusControl.disabled = true;
            }

            try {
                await window.aipkit_apiRequest('aipkit_stats_save_pricing_rule', buildPricingRulePayload({
                    ...rule,
                    enabled: nextEnabled ? '1' : '0',
                }));
                rule.enabled = nextEnabled ? 1 : 0;
                updatePricingRuleStatusUi(ruleId, nextEnabled);
                if (elements.pricingRuleId && String(elements.pricingRuleId.value) === String(ruleId) && elements.pricingEnabled) {
                    elements.pricingEnabled.value = nextEnabled ? '1' : '0';
                }
            } catch (error) {
                updatePricingRuleStatusUi(ruleId, !nextEnabled);
                showStatus(error.message || __('Failed to update pricing rule.', 'gpt3-ai-content-generator'), 'error');
            } finally {
                if (statusControl) {
                    statusControl.disabled = false;
                }
            }
        };

        const deletePricingRule = async (ruleId) => {
            if (!ruleId || typeof window.aipkit_apiRequest !== 'function') {
                return;
            }
            const confirmed = await confirmDestructiveAction(
                __('This permanently deletes the selected pricing rule. This cannot be undone.', 'gpt3-ai-content-generator'),
                {
                    title: __('Delete pricing rule', 'gpt3-ai-content-generator'),
                    confirmText: __('Delete', 'gpt3-ai-content-generator'),
                }
            );
            if (!confirmed) {
                return;
            }

            showStatus(__('Deleting pricing rule...', 'gpt3-ai-content-generator'), 'loading');
            try {
                const response = await window.aipkit_apiRequest('aipkit_stats_delete_pricing_rule', {
                    id: ruleId,
                });
                if (elements.pricingRuleId && String(elements.pricingRuleId.value) === String(ruleId)) {
                    closePricingModal(true);
                }
                await fetchPricingManagement();
                showStatus(response.message || __('Pricing rule deleted.', 'gpt3-ai-content-generator'), 'success');
                window.setTimeout(() => showStatus(''), 2000);
            } catch (error) {
                showStatus(error.message || __('Failed to delete pricing rule.', 'gpt3-ai-content-generator'), 'error');
            }
        };

        const getModuleLabel = (moduleKey) => {
            if (!moduleKey) {
                return moduleLabels.chatbot || 'Chatbot';
            }
            if (moduleKey === 'chat') {
                return moduleLabels.chatbot || 'Chatbot';
            }
            if (moduleLabels[moduleKey]) {
                return moduleLabels[moduleKey];
            }
            return moduleKey.replace(/[_-]/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
        };

        const isConversationLog = (log) => CONVERSATION_MODULES.includes(String(log?.module || ''));

        const getLogStatus = (log) => {
            if (log?.error_message) {
                return { label: __('Failed', 'gpt3-ai-content-generator'), tone: 'is-error' };
            }
            if (log?.blocked_message) {
                return { label: __('Blocked', 'gpt3-ai-content-generator'), tone: 'is-warning' };
            }
            if (!isConversationLog(log)) {
                return { label: __('Done', 'gpt3-ai-content-generator'), tone: 'is-ok' };
            }
            return String(log?.last_message_role || '') === 'user'
                ? { label: __('No reply', 'gpt3-ai-content-generator'), tone: 'is-muted' }
                : { label: __('Answered', 'gpt3-ai-content-generator'), tone: 'is-ok' };
        };

        // Signed-in users by name; automations without a user ran on a schedule; everyone else is a visitor.
        const getLogWho = (log) => {
            if (Number(log?.user_id) > 0 && String(log?.is_guest) !== '1') {
                return String(log.user_display_name || __('(Deleted User)', 'gpt3-ai-content-generator'));
            }
            if (AUTOMATION_MODULES.includes(String(log?.module || ''))) {
                return __('Schedule', 'gpt3-ai-content-generator');
            }
            return log?.ip_masked
                ? `${__('Visitor', 'gpt3-ai-content-generator')} · ${log.ip_masked}`
                : __('Visitor', 'gpt3-ai-content-generator');
        };

        const describeLog = (log) => {
            const moduleKey = String(log?.module || '');
            const who = getLogWho(log);
            const preview = truncate(log?.last_message_content, 90);
            const source = isConversationLog(log) ? (log.bot_name || getModuleLabel('chatbot')) : getModuleLabel(moduleKey);
            const reason = (message) => truncate(String(message).replace(/^Error generating image:\s*/i, ''), 90);
            if (log?.error_message) {
                return sprintf(__('%1$s request failed: %2$s', 'gpt3-ai-content-generator'), source, reason(log.error_message));
            }
            if (log?.blocked_message && !isConversationLog(log)) {
                return sprintf(__('%1$s request blocked: %2$s', 'gpt3-ai-content-generator'), source, reason(log.blocked_message));
            }
            if (isConversationLog(log)) {
                return sprintf(__('%1$s asked “%2$s”', 'gpt3-ai-content-generator'), who, truncate(log.first_message_content || log.last_message_content, 90));
            }
            switch (moduleKey) {
                case 'image_generator': {
                    const prompt = String(log.last_message_content || '').match(/prompt:\s*"(.*?)(?:\.\.\.)?"\]?\s*$/);
                    return prompt
                        ? sprintf(__('%1$s generated an image “%2$s”', 'gpt3-ai-content-generator'), who, truncate(prompt[1], 80))
                        : sprintf(__('%s generated an image', 'gpt3-ai-content-generator'), who);
                }
                case 'ai_forms':
                    return sprintf(__('%s submitted a form', 'gpt3-ai-content-generator'), who);
                case 'content_writer':
                    return sprintf(__('Content Writer request “%s”', 'gpt3-ai-content-generator'), preview);
                case 'content_writer_automation':
                case 'autogpt':
                    return sprintf(__('Automation ran “%s”', 'gpt3-ai-content-generator'), preview);
                case 'ai_post_enhancer':
                    return sprintf(__('Content Assistant suggested “%s”', 'gpt3-ai-content-generator'), preview);
                default:
                    return preview
                        ? sprintf(__('%1$s request “%2$s”', 'gpt3-ai-content-generator'), getModuleLabel(moduleKey), preview)
                        : sprintf(__('%s request', 'gpt3-ai-content-generator'), getModuleLabel(moduleKey));
            }
        };

        const formatTokens = (tokens) => `${formatNumber(tokens)} ${__('tokens', 'gpt3-ai-content-generator')}`;
        const formatMessages = (count) => `${formatNumber(count)} ${Number(count) === 1 ? __('message', 'gpt3-ai-content-generator') : __('messages', 'gpt3-ai-content-generator')}`;

        const describeLogMeta = (log) => {
            const tokens = Number(log?.total_conversation_tokens || 0);
            const parts = isConversationLog(log)
                ? [log.bot_name || getModuleLabel('chatbot'), formatMessages(log.message_count || 0)]
                : [getModuleLabel(log?.module), getLogWho(log)];
            if (tokens > 0) {
                parts.push(formatTokens(tokens));
            }
            return parts.filter(Boolean).join(' · ');
        };

        const renderFeedRow = (log) => {
            const status = getLogStatus(log);
            const kind = log.error_message ? 'error' : (isConversationLog(log) ? 'conversation' : 'task');
            const kinds = {
                conversation: ['C', __('Conversation', 'gpt3-ai-content-generator')],
                task: ['T', __('Content or task', 'gpt3-ai-content-generator')],
                error: ['E', __('Error', 'gpt3-ai-content-generator')],
            };
            return `
                <li>
                    <button type="button" class="aipkit_usage_feed_row${state.selectedLogId === Number(log.id) ? ' is-active' : ''}" data-log-id="${escapeHtml(log.id)}">
                        <span class="aipkit_usage_feed_icon is-${kind}" title="${escapeHtml(kinds[kind][1])}" aria-hidden="true">${kinds[kind][0]}</span>
                        <span class="aipkit_usage_feed_main">
                            <span class="aipkit_usage_feed_title"><span class="screen-reader-text">${escapeHtml(kinds[kind][1])}: </span>${escapeHtml(describeLog(log))}</span>
                            <span class="aipkit_usage_feed_meta">${escapeHtml(describeLogMeta(log))}</span>
                        </span>
                        <span class="aipkit_usage_feed_side">
                            <span class="aipkit_usage_pill ${status.tone}">${escapeHtml(status.label)}</span>
                            ${timeHtml(log.last_message_ts)}
                        </span>
                    </button>
                </li>
            `;
        };

        const markActiveRow = () => {
            container.querySelectorAll('tr[data-log-id]').forEach((row) => {
                row.classList.toggle('aipkit_stats_row_active', Number(row.dataset.logId) === state.selectedLogId);
            });
            container.querySelectorAll('.aipkit_usage_feed_row[data-log-id]').forEach((row) => {
                row.classList.toggle('is-active', Number(row.dataset.logId) === state.selectedLogId);
            });
        };

        const syncLogSelectionUi = () => {
            const selectedCount = state.selectedLogIds.size;
            const visibleIds = state.visibleLogs
                .map((log) => Number(log.id))
                .filter(Boolean);
            const visibleSelectedCount = visibleIds.filter((logId) => state.selectedLogIds.has(logId)).length;

            if (elements.tableHeader) {
                elements.tableHeader.hidden = selectedCount > 0;
            }
            if (elements.selectionHeader) {
                elements.selectionHeader.hidden = selectedCount === 0;
            }
            if (elements.selectionCount) {
                elements.selectionCount.textContent = sprintf(__('%s selected', 'gpt3-ai-content-generator'), formatNumber(selectedCount));
            }
            if (elements.selectAllLogs) {
                elements.selectAllLogs.checked = visibleIds.length > 0 && visibleSelectedCount === visibleIds.length;
                elements.selectAllLogs.indeterminate = visibleSelectedCount > 0 && visibleSelectedCount < visibleIds.length;
            }
            if (elements.tableBody) {
                elements.tableBody.querySelectorAll('tr[data-log-id]').forEach((row) => {
                    const logId = Number(row.getAttribute('data-log-id') || 0);
                    const isSelected = state.selectedLogIds.has(logId);
                    row.classList.toggle('aipkit_stats_row_bulk_selected', isSelected);
                    const checkbox = row.querySelector('.aipkit_stats_log_checkbox');
                    if (checkbox) {
                        checkbox.checked = isSelected;
                    }
                });
            }
        };

        const clearSelectedLogs = () => {
            state.selectedLogIds.clear();
            syncLogSelectionUi();
        };

        const renderLogsHeader = () => {
            if (!elements.tableHeader) {
                return;
            }
            const columns = state.view === 'tasks'
                ? [__('When', 'gpt3-ai-content-generator'), __('What', 'gpt3-ai-content-generator'), __('Started by', 'gpt3-ai-content-generator'), __('Result', 'gpt3-ai-content-generator'), __('Tokens', 'gpt3-ai-content-generator')]
                : [__('When', 'gpt3-ai-content-generator'), __('Who', 'gpt3-ai-content-generator'), __('Where', 'gpt3-ai-content-generator'), __('First message', 'gpt3-ai-content-generator'), __('Tokens', 'gpt3-ai-content-generator')];
            elements.tableHeader.innerHTML = `
                <th class="aipkit_stats_log_select_cell">
                    <input type="checkbox" id="aipkit_stats_logs_select_all" aria-label="${escapeHtml(__('Select all on this page', 'gpt3-ai-content-generator'))}" />
                </th>
                ${columns.map((label, index) => `<th${index === columns.length - 1 ? ' class="aipkit_stats_log_tokens_cell"' : ''}>${escapeHtml(label)}</th>`).join('')}
            `;
            elements.tableHeader.parentElement?.parentElement?.classList.toggle('is-tasks', state.view === 'tasks');
            elements.selectAllLogs = elements.tableHeader.querySelector('#aipkit_stats_logs_select_all');
        };

        const renderLogs = (logs) => {
            if (!elements.tableBody) {
                return;
            }
            state.visibleLogs = Array.isArray(logs) ? logs : [];
            if (!state.visibleLogs.length) {
                renderTablePlaceholder(elements.tableBody, 6, emptyText(state.view));
                state.selectedLogIds.clear();
                syncLogSelectionUi();
                return;
            }

            const visibleIds = new Set(state.visibleLogs.map((log) => Number(log.id)).filter(Boolean));
            state.selectedLogIds = new Set(
                Array.from(state.selectedLogIds).filter((logId) => visibleIds.has(logId))
            );

            elements.tableBody.innerHTML = state.visibleLogs.map((log) => {
                const logId = Number(log.id);
                const tokens = Number(log.total_conversation_tokens || 0);
                const isBulkSelected = state.selectedLogIds.has(logId);
                const openButton = (text) => `<button type="button" class="aipkit_usage_row_link" data-log-open>${escapeHtml(text)}</button>`;
                const status = getLogStatus(log);
                const cells = state.view === 'tasks'
                    ? [
                        timeHtml(log.last_message_ts),
                        `${openButton(describeLog(log))}<span class="aipkit_usage_cell_sub">${escapeHtml(getModuleLabel(log.module))}</span>`,
                        escapeHtml(getLogWho(log)),
                        `<span class="aipkit_usage_pill ${status.tone}">${escapeHtml(status.label)}</span>`,
                    ]
                    : [
                        timeHtml(log.last_message_ts),
                        escapeHtml(getLogWho(log)),
                        escapeHtml(log.bot_name || getModuleLabel('chatbot')),
                        `${openButton(truncate(log.first_message_content || log.last_message_content, 110) || '—')}${log.error_message ? `<span class="aipkit_usage_cell_sub is-error">${escapeHtml(__('Failed', 'gpt3-ai-content-generator'))}</span>` : ''}`,
                    ];
                cells.push(tokens > 0 ? escapeHtml(formatNumber(tokens)) : '—');
                return `
                    <tr class="aipkit_stats_row${state.selectedLogId === logId ? ' aipkit_stats_row_active' : ''}${isBulkSelected ? ' aipkit_stats_row_bulk_selected' : ''}" data-log-id="${escapeHtml(log.id)}">
                        <td class="aipkit_stats_log_select_cell">
                            <input type="checkbox" class="aipkit_stats_log_checkbox" aria-label="${escapeHtml(__('Select', 'gpt3-ai-content-generator'))}" ${isBulkSelected ? 'checked' : ''} />
                        </td>
                        ${cells.map((cell, index) => `<td${index === cells.length - 1 ? ' class="aipkit_stats_log_tokens_cell"' : ''}>${cell}</td>`).join('')}
                    </tr>
                `;
            }).join('');
            syncLogSelectionUi();
        };

        const buildFiltersPayload = () => ({
            days: state.days,
            view: ['conversations', 'tasks', 'errors'].includes(state.view) ? state.view : '',
            group: state.view === 'tasks' ? state.group : '',
            bot_id: state.view === 'conversations' ? state.botId : '',
            search: state.search,
        });

        const triggerDownload = (content, filename, type = 'text/csv;charset=utf-8;') => {
            const blob = new Blob([content], { type });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename || 'aipkit-logs.csv';
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
        };

        const confirmDestructiveAction = (message, options = {}) => new Promise((resolve) => {
            if (typeof window.aipkit_showConfirmModal === 'function') {
                window.aipkit_showConfirmModal(message, {
                    title: options.title || __('Confirm deletion', 'gpt3-ai-content-generator'),
                    confirmText: options.confirmText || __('Delete', 'gpt3-ai-content-generator'),
                    cancelText: __('Cancel', 'gpt3-ai-content-generator'),
                    variant: 'danger',
                    onConfirm: () => resolve(true),
                    onCancel: () => resolve(false),
                });
                return;
            }
            resolve(window.confirm(message));
        });

        const deleteSingleLog = async (logId) => {
            const id = Number(logId);
            if (!id) {
                return;
            }
            const isTask = !isConversationLog(state.currentDetail || state.logRows.get(id));
            const confirmed = await confirmDestructiveAction(
                isTask
                    ? __('This permanently deletes this log and its saved requests. This cannot be undone.', 'gpt3-ai-content-generator')
                    : __('This permanently deletes the selected conversation and its saved messages. This cannot be undone.', 'gpt3-ai-content-generator'),
                {
                    title: isTask ? __('Delete log', 'gpt3-ai-content-generator') : __('Delete conversation', 'gpt3-ai-content-generator'),
                    confirmText: __('Delete', 'gpt3-ai-content-generator'),
                }
            );
            if (!confirmed) {
                return;
            }
            showStatus(__('Deleting…', 'gpt3-ai-content-generator'), 'loading');
            try {
                const response = await window.aipkit_apiRequest('aipkit_stats_delete_log', {
                    log_id: id,
                });
                showStatus(response.message || __('Deleted.', 'gpt3-ai-content-generator'), 'success');
                if (state.selectedLogId === id) {
                    closeDrawer(false);
                    container.querySelector('.aipkit_usage_chip[aria-pressed="true"]')?.focus({ preventScroll: true });
                }
                state.selectedLogIds.delete(id);
                state.logRows.delete(id);
                syncLogSelectionUi();
                refreshActivity();
                window.setTimeout(() => showStatus(''), 2000);
            } catch (error) {
                showStatus(error.message || __('The log couldn’t be deleted.', 'gpt3-ai-content-generator'), 'error');
            }
        };

        const deleteSelectedLogs = async () => {
            const logIds = Array.from(state.selectedLogIds).filter(Boolean);
            if (!logIds.length) {
                return;
            }

            const count = logIds.length;
            const confirmed = await confirmDestructiveAction(
                count === 1
                    ? __('This permanently deletes the selected conversation and its saved messages. This cannot be undone.', 'gpt3-ai-content-generator')
                    : __('This permanently deletes the selected conversations and their saved messages. This cannot be undone.', 'gpt3-ai-content-generator'),
                {
                    title: count === 1
                        ? __('Delete conversation', 'gpt3-ai-content-generator')
                        : __('Delete conversations', 'gpt3-ai-content-generator'),
                    confirmText: __('Delete', 'gpt3-ai-content-generator'),
                }
            );
            if (!confirmed) {
                return;
            }

            if (elements.bulkDeleteLogs) {
                elements.bulkDeleteLogs.disabled = true;
            }

            const failedIds = [];
            let deletedCount = 0;
            for (let index = 0; index < logIds.length; index += 1) {
                const logId = logIds[index];
                showStatus(`${__('Deleting', 'gpt3-ai-content-generator')} ${index + 1} ${__('of', 'gpt3-ai-content-generator')} ${count}...`);
                try {
                    await window.aipkit_apiRequest('aipkit_stats_delete_log', {
                        log_id: logId,
                    });
                    deletedCount += 1;
                    if (state.selectedLogId === Number(logId)) {
                        closeDrawer(false);
                    }
                } catch (error) {
                    failedIds.push(logId);
                }
            }

            state.selectedLogIds = new Set(failedIds);
            if (deletedCount === state.visibleLogs.length && state.page > 1) {
                state.page -= 1;
            }
            await fetchLogs();
            fetchOverview();

            if (failedIds.length) {
                showStatus(
                    `${formatNumber(deletedCount)} ${__('deleted', 'gpt3-ai-content-generator')}; ${formatNumber(failedIds.length)} ${__('could not be deleted', 'gpt3-ai-content-generator')}.`,
                    'error'
                );
            } else {
                showStatus(
                    count === 1
                        ? __('Conversation deleted.', 'gpt3-ai-content-generator')
                        : `${formatNumber(deletedCount)} ${__('conversations deleted.', 'gpt3-ai-content-generator')}`,
                    'success'
                );
                window.setTimeout(() => showStatus(''), 2200);
            }

            if (elements.bulkDeleteLogs) {
                elements.bulkDeleteLogs.disabled = false;
            }
        };

        const setLogIpBlocked = async (button) => {
            const logId = Number(button?.getAttribute('data-log-id') || 0);
            const blockAction = button?.getAttribute('data-ip-block-action') || '';
            if (!logId || !['block', 'unblock'].includes(blockAction)) {
                return;
            }

            if (blockAction === 'block') {
                const confirmed = await confirmDestructiveAction(
                    __('Future AI requests from this IP will be rejected. Other visitors sharing the same network may also be affected.', 'gpt3-ai-content-generator'),
                    {
                        title: __('Block this IP?', 'gpt3-ai-content-generator'),
                        confirmText: __('Block IP', 'gpt3-ai-content-generator'),
                    }
                );
                if (!confirmed) {
                    return;
                }
            }

            button.disabled = true;
            showStatus(blockAction === 'block' ? __('Blocking IP...', 'gpt3-ai-content-generator') : __('Unblocking IP...', 'gpt3-ai-content-generator'));
            try {
                const response = await window.aipkit_apiRequest('aipkit_stats_set_ip_block', {
                    log_id: logId,
                    block_action: blockAction,
                });
                showStatus(response.message || (blockAction === 'block' ? __('IP address blocked.', 'gpt3-ai-content-generator') : __('IP address unblocked.', 'gpt3-ai-content-generator')));
                await fetchDetail(logId, false);
                window.setTimeout(() => showStatus(''), 2000);
            } catch (error) {
                button.disabled = false;
                showStatus(error.message || __('The blocked IP list could not be updated.', 'gpt3-ai-content-generator'), 'error');
            }
        };

        const deleteAllLogs = async () => {
            const confirmed = await confirmDestructiveAction(
                __('This permanently deletes every log in the current view and period, including your search. This cannot be undone.', 'gpt3-ai-content-generator'),
                {
                    title: __('Delete filtered logs', 'gpt3-ai-content-generator'),
                    confirmText: __('Delete logs', 'gpt3-ai-content-generator'),
                }
            );
            if (!confirmed) {
                return;
            }
            showStatus(__('Deleting logs…', 'gpt3-ai-content-generator'), 'loading');
            try {
                const response = await window.aipkit_apiRequest('aipkit_stats_delete_logs', buildFiltersPayload());
                showStatus(response.message || __('Logs deleted.', 'gpt3-ai-content-generator'), 'success');
                state.selectedLogIds.clear();
                syncLogSelectionUi();
                closeDrawer(false);
                refreshActivity();
                window.setTimeout(() => showStatus(''), 2500);
            } catch (error) {
                showStatus(error.message || __('The logs couldn’t be deleted.', 'gpt3-ai-content-generator'), 'error');
            }
        };

        const exportLogs = async () => {
            showStatus(__('Preparing export…', 'gpt3-ai-content-generator'), 'loading');
            try {
                const response = await window.aipkit_apiRequest('aipkit_stats_export_logs', buildFiltersPayload());
                if (!response || !response.csv) {
                    throw new Error(__('Export failed.', 'gpt3-ai-content-generator'));
                }
                triggerDownload(response.csv, response.filename);
                showStatus(response.message || __('Export ready.', 'gpt3-ai-content-generator'), 'success');
                window.setTimeout(() => showStatus(''), 2000);
            } catch (error) {
                showStatus(error.message || __('The export couldn’t be prepared.', 'gpt3-ai-content-generator'), 'error');
            }
        };

        const positionTableMenu = () => {
            if (!elements.tableMenu || !elements.tableMenuTrigger || elements.tableMenu.hidden) {
                return;
            }

            const viewportPadding = 16;
            const gap = 10;
            const triggerRect = elements.tableMenuTrigger.getBoundingClientRect();
            const menuRect = elements.tableMenu.getBoundingClientRect();
            const menuWidth = Math.min(menuRect.width || 380, window.innerWidth - (viewportPadding * 2));
            const menuHeight = Math.min(menuRect.height || 0, window.innerHeight - (viewportPadding * 2));

            let left = triggerRect.right - menuWidth;
            left = Math.max(viewportPadding, Math.min(left, window.innerWidth - menuWidth - viewportPadding));

            let top = triggerRect.bottom + gap;
            const canOpenAbove = triggerRect.top - menuHeight - gap >= viewportPadding;
            if (top + menuHeight > window.innerHeight - viewportPadding && canOpenAbove) {
                top = triggerRect.top - menuHeight - gap;
            }
            top = Math.max(viewportPadding, Math.min(top, window.innerHeight - menuHeight - viewportPadding));

            elements.tableMenu.style.setProperty('--aipkit-stats-table-menu-left', `${Math.round(left)}px`);
            elements.tableMenu.style.setProperty('--aipkit-stats-table-menu-top', `${Math.round(top)}px`);
        };

        const resetTableMenuPosition = () => {
            if (!elements.tableMenu) {
                return;
            }

            elements.tableMenu.style.removeProperty('--aipkit-stats-table-menu-left');
            elements.tableMenu.style.removeProperty('--aipkit-stats-table-menu-top');
        };

        const toggleTableMenu = (open, restoreFocus = false) => {
            if (!elements.tableMenu || !elements.tableMenuTrigger) {
                return;
            }
            const nextState = open ?? elements.tableMenu.hidden;
            elements.tableMenu.hidden = !nextState;
            elements.tableMenuTrigger.setAttribute('aria-expanded', nextState ? 'true' : 'false');
            if (nextState) {
                positionTableMenu();
                const focusTarget = Array.from(elements.tableMenu.querySelectorAll('[role="menuitem"]')).find((item) => !item.disabled);
                if (focusTarget && typeof focusTarget.focus === 'function') {
                    window.requestAnimationFrame(() => focusTarget.focus());
                }
            } else {
                resetTableMenuPosition();
                if (restoreFocus) {
                    elements.tableMenuTrigger.focus();
                }
            }
        };

        const renderPagination = (pagination) => {
            if (!elements.pagination) {
                return;
            }
            if (typeof window.aipkit_renderLogsPagination === 'function') {
                window.aipkit_renderLogsPagination(pagination, elements.pagination, (page) => {
                    clearSelectedLogs();
                    state.page = page;
                    fetchLogs();
                }, {
                    itemLabelSingular: state.view === 'conversations' ? __('conversation', 'gpt3-ai-content-generator') : __('item', 'gpt3-ai-content-generator'),
                    itemLabelPlural: state.view === 'conversations' ? __('conversations', 'gpt3-ai-content-generator') : __('items', 'gpt3-ai-content-generator'),
                    compactControls: true,
                    perPage: state.perPage,
                    perPageOptions: LOGS_PER_PAGE_OPTIONS,
                    onPerPageChange: (perPage) => {
                        if (!LOGS_PER_PAGE_OPTIONS.includes(perPage) || perPage === state.perPage) {
                            return;
                        }
                        clearSelectedLogs();
                        state.perPage = perPage;
                        state.page = 1;
                        setStoredValue(LOGS_PER_PAGE_STORAGE_KEY, state.perPage);
                        fetchLogs();
                    },
                });
                return;
            }
            elements.pagination.innerHTML = '';
        };

        const resolveMessageRole = (role) => {
            const normalized = (role || '').toLowerCase();
            if (normalized === 'assistant' || normalized === 'model' || normalized === 'bot') {
                return 'bot';
            }
            if (normalized === 'user') {
                return 'user';
            }
            return normalized || 'unknown';
        };

        const findNextBotMessage = (messages, startIndex) => {
            for (let index = startIndex + 1; index < messages.length; index += 1) {
                if (resolveMessageRole(messages[index]?.role) === 'bot') {
                    return messages[index];
                }
            }
            return null;
        };

        const messageTokens = (message) => Number(message?.usage?.total_tokens ?? message?.usage?.totalTokenCount ?? 0) || 0;
        const messageErrorCode = (message) => {
            const code = message?.response_data?.error_code;
            return typeof code === 'string' ? code : '';
        };
        const providerLabel = (provider) => (
            normalizeProviderKey(provider) === 'aipuffercloud' ? 'AI Puffer Cloud' : getPricingProviderLabel(provider)
        );

        const buildScoreToneClass = (percent) => {
            if (percent >= 70) {
                return 'is-strong';
            }
            if (percent >= 40) {
                return 'is-moderate';
            }
            return 'is-weak';
        };

        const getScoreSourceSummary = (scoreItem) => {
            const provider = String(scoreItem?.provider || __('Unknown', 'gpt3-ai-content-generator'));
            let source = '';
            if (provider === 'OpenAI') {
                source = scoreItem?.store_name || scoreItem?.store_id || '';
            } else if (provider === 'Pinecone') {
                source = scoreItem?.index_name || '';
            } else if (provider === 'Qdrant' || provider === 'Chroma') {
                source = scoreItem?.collection_name || '';
            } else {
                source = scoreItem?.index_name || scoreItem?.store_name || scoreItem?.collection_name || '';
            }
            return source ? `${provider} · ${source}` : provider;
        };

        const buildScoreChunkLabel = (scoreItem) => {
            const total = Number(scoreItem?.total_chunks ?? 0);
            let chunkNumber = Number(scoreItem?.chunk_number ?? 0);
            if (!chunkNumber && scoreItem?.chunk_index !== undefined) {
                chunkNumber = Number(scoreItem.chunk_index) + 1;
            }
            if (!Number.isFinite(chunkNumber) || !Number.isFinite(total) || chunkNumber < 1 || total <= 1) {
                return '';
            }
            return `${__('Chunk', 'gpt3-ai-content-generator')} ${Math.round(chunkNumber)}/${Math.round(total)}`;
        };

        let markdownRenderer;
        const renderMessageBody = (content) => {
            if (markdownRenderer === undefined) {
                markdownRenderer = null;
                if (typeof window.markdownit === 'function') {
                    markdownRenderer = window.markdownit({ html: false, linkify: true, typographer: true });
                    if (window.hljs && typeof window.markdownitHighlight === 'function') {
                        markdownRenderer.use(window.markdownitHighlight, { hljs: window.hljs });
                    }
                }
            }
            return markdownRenderer
                ? { html: markdownRenderer.render(content), markdown: true }
                : { html: escapeHtml(content), markdown: false };
        };

        const disclosureButton = (targetId, label) => `
            <button type="button" class="aipkit_usage_disclosure" data-aipkit-stats-toggle="${targetId}" aria-controls="${targetId}" aria-expanded="false">
                <span>${escapeHtml(label)}</span>
                <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
            </button>
        `;

        const renderPayloadCard = (requestPayload, idBase) => {
            const payloadId = `${idBase}_payload`;
            const rawPayloadId = `${payloadId}_raw`;
            const summary = summarizeRequestPayload(requestPayload, providerLabel);
            const field = (label, value) => `<div><dt>${escapeHtml(label)}</dt><dd>${escapeHtml(value)}</dd></div>`;
            const section = (label, content, suffix) => {
                const sectionId = `${payloadId}_${suffix}`;
                const isLong = content.length > 280 || content.split('\n').length > 4;
                return `
                    <div class="aipkit_usage_payload_section">
                        <div class="aipkit_usage_payload_label">${escapeHtml(label)}</div>
                        <pre class="aipkit_usage_payload_text${isLong ? ' is-clamped' : ''}" id="${sectionId}">${escapeHtml(content)}</pre>
                        ${isLong ? `<button type="button" class="aipkit_usage_link_btn" data-aipkit-usage-clamp="${sectionId}" aria-controls="${sectionId}" aria-expanded="false">${escapeHtml(__('Show all', 'gpt3-ai-content-generator'))}</button>` : ''}
                    </div>
                `;
            };
            return disclosureButton(payloadId, __('Request details', 'gpt3-ai-content-generator')) + `
                <div class="aipkit_usage_expand" id="${payloadId}" hidden>
                    <div class="aipkit_usage_xcard">
                        <div class="aipkit_usage_xcard_head">
                            <span class="aipkit_usage_xcard_title">${escapeHtml(__('Request payload', 'gpt3-ai-content-generator'))}</span>
                            <button type="button" class="aipkit_usage_btn aipkit_usage_btn--small" data-aipkit-copy-payload="${rawPayloadId}">${escapeHtml(__('Copy JSON', 'gpt3-ai-content-generator'))}</button>
                            <textarea id="${rawPayloadId}" hidden readonly>${escapeHtml(JSON.stringify(requestPayload, null, 2))}</textarea>
                        </div>
                        <dl class="aipkit_usage_kv">
                            ${field(__('Provider', 'gpt3-ai-content-generator'), summary.provider)}
                            ${field(__('Model', 'gpt3-ai-content-generator'), summary.model)}
                            ${field(__('Max output', 'gpt3-ai-content-generator'), summary.maxOutputTokens)}
                            ${field(__('Reasoning', 'gpt3-ai-content-generator'), summary.reasoning)}
                        </dl>
                        ${summary.systemPrompt ? section(__('System prompt', 'gpt3-ai-content-generator'), summary.systemPrompt, 'system') : ''}
                        ${summary.userMessage ? section(__('User message', 'gpt3-ai-content-generator'), summary.userMessage, 'user') : ''}
                    </div>
                </div>
            `;
        };

        const renderKnowledgeCard = (scoreItems, idBase) => {
            const sorted = [...scoreItems].sort((left, right) => Number(right?.score ?? 0) - Number(left?.score ?? 0));
            const highest = sorted[0];
            const topPercent = highest?.score !== undefined ? Math.round(Number(highest.score) * 100) : 0;
            const label = sorted.length > 1
                ? `${sorted.length} ${__('sources', 'gpt3-ai-content-generator')} · ${__('top', 'gpt3-ai-content-generator')} ${topPercent}%`
                : `${topPercent}% ${__('match', 'gpt3-ai-content-generator')}`;
            const scoresId = `${idBase}_scores`;
            const rows = sorted.map((scoreItem, index) => {
                const fileName = typeof scoreItem?.file_name === 'string' ? scoreItem.file_name.trim() : '';
                const chunkLabel = buildScoreChunkLabel(scoreItem);
                const percent = scoreItem?.score !== undefined ? Math.round(Number(scoreItem.score) * 100) : null;
                const preview = scoreItem?.content_preview || '';
                return `
                    <div class="aipkit_usage_source">
                        <div class="aipkit_usage_source_head">
                            <strong>${escapeHtml(fileName || `${__('Result', 'gpt3-ai-content-generator')} ${index + 1}`)}</strong>
                            ${chunkLabel ? `<span>${escapeHtml(chunkLabel)}</span>` : ''}
                            <span class="aipkit_usage_score ${percent !== null ? buildScoreToneClass(percent) : 'is-weak'}">${percent !== null ? escapeHtml(`${percent}%`) : '—'}</span>
                        </div>
                        ${preview ? `<p>${escapeHtml(preview)}</p>` : ''}
                    </div>
                `;
            }).join('');
            return disclosureButton(scoresId, label) + `
                <div class="aipkit_usage_expand" id="${scoresId}" hidden>
                    <div class="aipkit_usage_xcard">
                        <div class="aipkit_usage_xcard_head">
                            <span class="aipkit_usage_xcard_title">${escapeHtml(__('Knowledge used', 'gpt3-ai-content-generator'))}</span>
                            <span class="aipkit_usage_xcard_note">${escapeHtml(getScoreSourceSummary(highest))}</span>
                        </div>
                        ${rows}
                    </div>
                </div>
            `;
        };

        const renderLiveUsage = (message) => {
            const liveUsage = message.usage || {};
            if (Number.isFinite(liveUsage.live_seconds)) {
                return `<p class="aipkit_usage_msg_note">${escapeHtml(
                    `${__('Reported voice duration', 'gpt3-ai-content-generator')}: ${formatNumber(liveUsage.live_seconds)} ${__('seconds', 'gpt3-ai-content-generator')} · `
                    + `${__('Billable duration', 'gpt3-ai-content-generator')}: ${formatNumber(liveUsage.billable_seconds ?? Math.max(15, Math.ceil(liveUsage.live_seconds)))} ${__('seconds', 'gpt3-ai-content-generator')} · `
                    + (liveUsage.live_usage_complete ? __('Final usage received', 'gpt3-ai-content-generator') : __('Partial usage — final report missing', 'gpt3-ai-content-generator'))
                )}</p>`;
            }
            if (liveUsage.live_session_id && Number.isFinite(liveUsage.total_tokens)) {
                return `<p class="aipkit_usage_msg_note">${escapeHtml(`${message.model || ''} · ${formatNumber(liveUsage.input_tokens || 0)} ${__('input tokens', 'gpt3-ai-content-generator')} · ${formatNumber(liveUsage.output_tokens || 0)} ${__('output tokens', 'gpt3-ai-content-generator')}`)}</p>`;
            }
            return '';
        };

        const renderConversationMessages = (messages, userLabel, botLabel) => messages.map((message, index) => {
            const role = resolveMessageRole(message.role);
            const idBase = `aipkit_usage_msg_${index}`;
            const tokens = messageTokens(message);
            const meta = [formatMessageTime(message.timestamp), role === 'bot' && tokens ? formatTokens(tokens) : ''].filter(Boolean).join(' · ');
            const label = role === 'user' ? userLabel : role === 'bot' ? botLabel : role.charAt(0).toUpperCase() + role.slice(1);
            let extras = '';
            if (role === 'user') {
                const requestPayload = findNextBotMessage(messages, index)?.request_payload;
                if (requestPayload) {
                    extras = renderPayloadCard(requestPayload, idBase);
                }
            } else if (role === 'bot' && Array.isArray(message.vector_search_scores) && message.vector_search_scores.length) {
                extras = renderKnowledgeCard(message.vector_search_scores, idBase);
            }
            const code = messageErrorCode(message);
            const body = renderMessageBody(normalizeMessageContent(message.content));
            return `
                <div class="aipkit_usage_msg aipkit_usage_msg--${escapeHtml(role)}${code && !REFUSAL_CODES.includes(code) ? ' is-failed' : ''}">
                    <div class="aipkit_usage_msg_who">${escapeHtml(label)}${meta ? `<span>${escapeHtml(meta)}</span>` : ''}</div>
                    <div class="aipkit_usage_bubble${body.markdown ? ' aipkit_stats_message_body--markdown' : ''}">${body.html}</div>
                    ${renderLiveUsage(message)}
                    ${extras}
                </div>
            `;
        }).join('');

        // One step per request and reply; logs without that structure list each reply as its own request.
        const renderTaskSteps = (messages) => {
            const steps = [];
            let pending = null;
            messages.forEach((message) => {
                const role = resolveMessageRole(message.role);
                if (role === 'user') {
                    if (pending) {
                        steps.push({ prompt: pending, reply: null });
                    }
                    pending = message;
                } else if (role === 'bot') {
                    steps.push({ prompt: pending, reply: message });
                    pending = null;
                }
            });
            if (pending) {
                steps.push({ prompt: pending, reply: null });
            }
            return steps.map((step, index) => {
                const source = String(step.prompt?.original_content || step.prompt?.content || '').trim();
                const named = source.match(/^generate\s+(.{1,40})$/i);
                const label = named
                    ? named[1].charAt(0).toUpperCase() + named[1].slice(1)
                    : sprintf(__('Request %s', 'gpt3-ai-content-generator'), index + 1);
                const code = messageErrorCode(step.reply);
                let status = { label: __('Done', 'gpt3-ai-content-generator'), tone: 'is-ok' };
                if (!step.reply) {
                    status = { label: __('No reply', 'gpt3-ai-content-generator'), tone: 'is-muted' };
                } else if (code && REFUSAL_CODES.includes(code)) {
                    status = { label: __('Blocked', 'gpt3-ai-content-generator'), tone: 'is-warning' };
                } else if (code) {
                    status = { label: __('Failed', 'gpt3-ai-content-generator'), tone: 'is-error' };
                }
                const tokens = messageTokens(step.reply);
                const result = [truncate(normalizeMessageContent(step.reply?.content), 160), tokens ? formatTokens(tokens) : ''].filter(Boolean).join(' · ');
                const requestPayload = step.reply?.request_payload || step.prompt?.request_payload;
                return `
                    <li class="aipkit_usage_step">
                        <span class="aipkit_usage_step_num ${status.tone}" aria-hidden="true">${index + 1}</span>
                        <div class="aipkit_usage_step_main">
                            <div class="aipkit_usage_step_head">
                                <strong>${escapeHtml(label)}</strong>
                                <span class="aipkit_usage_pill ${status.tone}">${escapeHtml(status.label)}</span>
                            </div>
                            ${result ? `<p class="aipkit_usage_step_result">${escapeHtml(result)}</p>` : ''}
                            ${requestPayload ? renderPayloadCard(requestPayload, `aipkit_usage_step_${index}`) : ''}
                        </div>
                    </li>
                `;
            }).join('');
        };

        const formatDuration = (seconds) => {
            const value = Math.max(0, Math.round(Number(seconds) || 0));
            if (!value) {
                return '';
            }
            if (value < 60) {
                return sprintf(__('%s s', 'gpt3-ai-content-generator'), value);
            }
            if (value < 3600) {
                return sprintf(__('%s min', 'gpt3-ai-content-generator'), Math.round(value / 60));
            }
            return sprintf(__('%s h', 'gpt3-ai-content-generator'), Math.round(value / 360) / 10);
        };

        const renderDetail = (detail) => {
            if (!elements.detailPanel) {
                return;
            }
            const row = state.logRows.get(Number(detail.id)) || {};
            const log = { ...detail, ...row };
            const messages = Array.isArray(detail.messages) ? detail.messages : [];
            const moduleKey = String(detail.module || '').toLowerCase().replace(/[\s-]+/g, '_');

            const isContentWriterPlaceholder = (content) => {
                const trimmed = String(content || '').trim().toLowerCase();
                return Boolean(trimmed) && (trimmed.startsWith('generate ') || trimmed.startsWith('content writer request'));
            };
            const extractPromptFromPayload = (payload) => {
                const sent = payload && typeof payload === 'object' ? payload.payload_sent : null;
                if (!sent || typeof sent !== 'object') {
                    return null;
                }
                const messagesList = Array.isArray(sent.messages) ? sent.messages : (Array.isArray(sent.input) ? sent.input : null);
                const userMessage = Array.isArray(messagesList)
                    ? messagesList.find((entry) => entry && entry.role === 'user' && typeof entry.content === 'string' && entry.content.trim())
                    : null;
                return userMessage ? userMessage.content.trim() : null;
            };
            const findPromptField = (payload, content) => {
                if (!payload || typeof payload !== 'object') {
                    return null;
                }
                const mapping = {
                    'generate excerpt': 'custom_excerpt_prompt',
                    'generate tags': 'custom_tags_prompt',
                    'generate meta description': 'custom_meta_prompt',
                    'generate focus keyword': 'custom_keyword_prompt',
                    'generate image prompt': 'image_prompt',
                    'generate featured image': 'featured_image_prompt',
                    'generate title': 'custom_title_prompt',
                };
                const field = mapping[String(content || '').trim().toLowerCase()];
                const value = field && typeof payload[field] === 'string' ? payload[field].trim() : '';
                return value || null;
            };
            const findAnyPromptField = (payload) => {
                if (!payload || typeof payload !== 'object') {
                    return null;
                }
                const key = Object.keys(payload).find((name) => /_prompt$/i.test(name) && typeof payload[name] === 'string' && payload[name].trim());
                return key ? payload[key].trim() : null;
            };

            // Content Writer stores "Generate title"-style placeholders; show the prompt that was really sent.
            if (moduleKey === 'content_writer') {
                messages.forEach((message, index) => {
                    if (!message || message.role !== 'user' || typeof message.content !== 'string' || !isContentWriterPlaceholder(message.content)) {
                        return;
                    }
                    const rawContent = message.content.trim();
                    const replacement = extractPromptFromPayload(messages[index + 1]?.request_payload)
                        || extractPromptFromPayload(message.request_payload)
                        || findPromptField(message.request_payload, rawContent)
                        || findAnyPromptField(message.request_payload);
                    if (replacement) {
                        message.original_content = message.content;
                        message.content = replacement;
                    }
                });
            }

            state.currentDetail = detail;
            const isConversation = isConversationLog(detail);
            const status = getLogStatus(log);
            const who = getLogWho(log);
            const lastBot = [...messages].reverse().find((message) => resolveMessageRole(message.role) === 'bot');
            const totalTokens = messages.reduce((sum, message) => sum + messageTokens(message), 0);
            const startedAt = formatTimestamp(detail.first_message_ts || detail.last_message_ts);

            if (elements.detailTitle) {
                elements.detailTitle.textContent = isConversation ? who : getModuleLabel(detail.module);
            }
            if (elements.detailStatus) {
                elements.detailStatus.className = `aipkit_usage_pill ${status.tone}`;
                elements.detailStatus.textContent = status.label;
                elements.detailStatus.hidden = false;
            }
            if (elements.detailSub) {
                elements.detailSub.textContent = (isConversation
                    ? [detail.bot_name || getModuleLabel('chatbot'), startedAt, formatMessages(detail.message_count || messages.length)]
                    : [sprintf(__('Started by %s', 'gpt3-ai-content-generator'), who), startedAt, formatDuration(Number(detail.last_message_ts) - Number(detail.first_message_ts))]
                ).filter(Boolean).join(' · ');
            }
            if (elements.detailMeta) {
                const metaItems = [
                    [__('Model', 'gpt3-ai-content-generator'), lastBot?.model || ''],
                    [__('Provider', 'gpt3-ai-content-generator'), lastBot?.provider ? providerLabel(lastBot.provider) : ''],
                    [__('Tokens', 'gpt3-ai-content-generator'), totalTokens ? formatNumber(totalTokens) : ''],
                ].filter((item) => item[1]);
                elements.detailMeta.innerHTML = metaItems.map(([label, value]) => `<div><dt>${escapeHtml(label)}</dt><dd>${escapeHtml(value)}</dd></div>`).join('');
                elements.detailMeta.hidden = !metaItems.length;
            }

            if (!messages.length) {
                elements.detailPanel.innerHTML = `<div class="aipkit_usage_state"><p>${escapeHtml(__('No messages were saved for this log.', 'gpt3-ai-content-generator'))}</p></div>`;
            } else if (isConversation) {
                elements.detailPanel.innerHTML = `<div class="aipkit_usage_messages">${renderConversationMessages(
                    messages,
                    who,
                    detail.bot_name || getModuleLabel('chatbot')
                )}</div>`;
            } else {
                elements.detailPanel.innerHTML = `<ol class="aipkit_usage_steps">${renderTaskSteps(messages)}</ol>`;
            }

            const ipBlockState = detail.ip_block && typeof detail.ip_block === 'object' ? detail.ip_block : {};
            if (elements.detailFooter) {
                elements.detailFooter.innerHTML = `
                    ${isConversation && ipBlockState.can_block ? `
                        <button type="button" class="aipkit_usage_btn aipkit_stats_detail_ip_action" data-log-id="${escapeHtml(detail.id)}" data-ip-block-action="${ipBlockState.is_blocked ? 'unblock' : 'block'}">
                            <span class="dashicons ${ipBlockState.is_blocked ? 'dashicons-unlock' : 'dashicons-lock'}" aria-hidden="true"></span>
                            ${escapeHtml(ipBlockState.is_blocked ? __('Unblock IP', 'gpt3-ai-content-generator') : __('Block IP', 'gpt3-ai-content-generator'))}
                        </button>
                    ` : ''}
                    <button type="button" class="aipkit_usage_btn" data-aipkit-usage-export>
                        <span class="dashicons dashicons-download" aria-hidden="true"></span>
                        ${escapeHtml(__('Export', 'gpt3-ai-content-generator'))}
                    </button>
                    <button type="button" class="aipkit_usage_btn aipkit_usage_btn--danger aipkit_stats_detail_delete" data-log-id="${escapeHtml(detail.id)}">
                        <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                        ${escapeHtml(isConversation ? __('Delete conversation', 'gpt3-ai-content-generator') : __('Delete log', 'gpt3-ai-content-generator'))}
                    </button>
                `;
                elements.detailFooter.hidden = false;
            }
        };

        const exportDetail = () => {
            const detail = state.currentDetail;
            if (!detail) {
                return;
            }
            const toIso = (timestamp) => (Number(timestamp) ? new Date(Number(timestamp) * 1000).toISOString() : '');
            const data = {
                id: Number(detail.id),
                source: detail.bot_name || getModuleLabel(detail.module),
                module: detail.module || 'chatbot',
                started: toIso(detail.first_message_ts),
                updated: toIso(detail.last_message_ts),
                messages: (Array.isArray(detail.messages) ? detail.messages : []).map((message) => ({
                    role: resolveMessageRole(message.role),
                    content: normalizeMessageContent(message.content),
                    time: toIso(message.timestamp),
                    provider: message.provider || '',
                    model: message.model || '',
                    tokens: messageTokens(message),
                })),
            };
            triggerDownload(JSON.stringify(data, null, 2), `aipkit-log-${Number(detail.id)}.json`, 'application/json');
        };

        const VIEW_LABELS = {
            everything: __('Everything', 'gpt3-ai-content-generator'),
            conversations: __('Conversations', 'gpt3-ai-content-generator'),
            tasks: __('Content', 'gpt3-ai-content-generator'),
            requests: __('AI requests', 'gpt3-ai-content-generator'),
            errors: __('Errors', 'gpt3-ai-content-generator'),
        };
        const TASK_GROUP_LABELS = {
            content_writer: __('Content Writer', 'gpt3-ai-content-generator'),
            automations: __('Automations', 'gpt3-ai-content-generator'),
            assistant: __('Content Assistant', 'gpt3-ai-content-generator'),
            images: __('Images', 'gpt3-ai-content-generator'),
            forms: __('AI Forms', 'gpt3-ai-content-generator'),
            other: __('Other', 'gpt3-ai-content-generator'),
        };

        const announceResults = (total) => {
            announce(sprintf(__('%1$s: %2$s results', 'gpt3-ai-content-generator'), VIEW_LABELS[state.view] || '', formatNumber(total)));
        };

        const fetchLogs = async () => {
            if (!elements.tableBody || typeof window.aipkit_apiRequest !== 'function') {
                return;
            }
            const sequence = ++state.logsSequence;
            state.logRows.clear();
            renderLogsHeader();
            renderSkeletonRows(elements.tableBody, 6);
            if (elements.pagination) {
                elements.pagination.innerHTML = '';
            }
            try {
                const response = await window.aipkit_apiRequest('aipkit_stats_get_logs', {
                    ...buildFiltersPayload(),
                    page: state.page,
                    per_page: state.perPage,
                });
                if (sequence !== state.logsSequence) {
                    return;
                }
                const logs = Array.isArray(response.logs) ? response.logs : [];
                logs.forEach((log) => state.logRows.set(Number(log.id), log));
                renderLogs(logs);
                renderPagination(response.pagination || {});
                announceResults(response.pagination?.total_logs ?? logs.length);
            } catch (error) {
                if (sequence !== state.logsSequence) {
                    return;
                }
                state.visibleLogs = [];
                state.selectedLogIds.clear();
                syncLogSelectionUi();
                renderLoadError(elements.tableBody, 6);
            }
        };

        // Nothing in Everything while AI requests exist: say what Everything covers and link to them.
        const renderFeedEmpty = () => {
            const requests = Number(state.overview?.requests?.total || 0);
            const link = state.view === 'everything' && !state.search && requests > 0
                ? `<button type="button" class="aipkit_usage_btn aipkit_usage_btn--small" data-aipkit-usage-goto="requests">${escapeHtml(sprintf(__('See AI requests (%s)', 'gpt3-ai-content-generator'), formatNumber(requests)))}</button>`
                : '';
            return `<li class="aipkit_usage_state"><p>${escapeHtml(emptyText(state.view))}</p>${link}</li>`;
        };

        // Everything: conversations and content runs (the log list) merged with website actions from the same period,
        // newest first. AI requests stay in their own view: every reply and draft also creates requests.
        let mergedFeed;
        const fetchFeed = async (reset = true) => {
            if (!elements.feedList || typeof window.aipkit_apiRequest !== 'function') return;
            const sequence = ++state.logsSequence;
            if (reset) {
                state.logRows.clear();
                const filters = buildFiltersPayload();
                const days = state.days;
                mergedFeed = createMergedActivity([
                    async (page) => {
                        const response = await window.aipkit_apiRequest('aipkit_stats_get_logs', { ...filters, page, per_page: FEED_PER_PAGE });
                        return {
                            rows: (response.logs || []).map((item) => ({ kind: 'log', item, timestamp: Number(item.last_message_ts) || 0 })),
                            more: Number(response.pagination?.current_page || 1) < Number(response.pagination?.total_pages || 0),
                        };
                    },
                    ...(websiteActivity ? [async (page) => {
                        const response = await websiteActivity.everything(page, FEED_PER_PAGE, days);
                        return {
                            rows: (response.items || []).map((item) => ({ kind: 'website', item, timestamp: Number(item.created_at) || 0 })),
                            more: response.has_more,
                        };
                    }] : []),
                ]);
                elements.feedList.innerHTML = Array.from({ length: 5 }, () => '<li class="aipkit_usage_feed_skeleton" aria-hidden="true"><span class="aipkit_usage_skeleton"></span><span class="aipkit_usage_skeleton"></span></li>').join('');
                if (elements.feedFooter) elements.feedFooter.hidden = true;
            }
            elements.feedList.setAttribute('aria-busy', 'true');
            if (elements.feedMore) elements.feedMore.disabled = true;
            try {
                const result = await mergedFeed.next(FEED_PER_PAGE);
                if (sequence !== state.logsSequence) return;
                elements.feedList.innerHTML = result.rows.map(({ kind, item }) => {
                    if (kind === 'website') return websiteActivity.renderRow(item);
                    state.logRows.set(Number(item.id), item);
                    return renderFeedRow(item);
                }).join('') || renderFeedEmpty();
                if (elements.feedFooter) elements.feedFooter.hidden = !result.more;
                announceResults(result.rows.length);
            } catch (error) {
                if (sequence !== state.logsSequence) return;
                if (reset) elements.feedList.innerHTML = `<li>${loadErrorHtml()}</li>`;
                else showStatus(__('More activity couldn’t load. Try again.', 'gpt3-ai-content-generator'), 'error');
                if (elements.feedFooter) elements.feedFooter.hidden = false;
            } finally {
                if (sequence === state.logsSequence) {
                    elements.feedList.removeAttribute('aria-busy');
                    if (elements.feedMore) elements.feedMore.disabled = false;
                }
            }
        };

        const fetchDetail = async (logId, opener = null) => {
            const id = Number(logId);
            if (!id || !elements.detailDrawer || typeof window.aipkit_apiRequest !== 'function') {
                return;
            }
            const sequence = ++state.detailSequence;
            const refreshing = opener === false;
            state.selectedLogId = id;
            markActiveRow();
            if (!refreshing) {
                const row = state.logRows.get(id) || {};
                if (elements.detailTitle) {
                    elements.detailTitle.textContent = isConversationLog(row) ? getLogWho(row) : getModuleLabel(row.module);
                }
                if (elements.detailStatus) {
                    elements.detailStatus.hidden = true;
                }
                if (elements.detailSub) {
                    elements.detailSub.textContent = '';
                }
                if (elements.detailMeta) {
                    elements.detailMeta.hidden = true;
                }
                if (elements.detailFooter) {
                    elements.detailFooter.hidden = true;
                }
                elements.detailPanel.innerHTML = `<div class="aipkit_usage_state" role="status"><p>${escapeHtml(__('Loading…', 'gpt3-ai-content-generator'))}</p></div>`;
                if (drawerState.open !== elements.detailDrawer) {
                    openDrawer(elements.detailDrawer, opener);
                }
            }
            try {
                const response = await window.aipkit_apiRequest('aipkit_stats_get_log_detail', {
                    log_id: id,
                });
                if (sequence !== state.detailSequence) {
                    return;
                }
                renderDetail(response);
                if (refreshing) {
                    elements.detailFooter?.querySelector('.aipkit_stats_detail_ip_action')?.focus({ preventScroll: true });
                }
            } catch (error) {
                if (sequence !== state.detailSequence) {
                    return;
                }
                elements.detailPanel.innerHTML = `
                    <div class="aipkit_usage_state is-error">
                        <p>${escapeHtml(error.message || __('This log couldn’t load.', 'gpt3-ai-content-generator'))}</p>
                        <button type="button" class="aipkit_usage_btn aipkit_usage_btn--small" data-aipkit-usage-detail-retry="${id}">${escapeHtml(__('Try again', 'gpt3-ai-content-generator'))}</button>
                    </div>
                `;
            }
        };

        // Website activity (website management): its own rows, sub-filters and drawer content inside this page's frame.
        websiteActivity = createWebsiteActivity({
            container, elements, escapeHtml, __, _n, sprintf, formatNumber, formatTimestamp, timeHtml, openDrawer, announce, showStatus, loadErrorHtml, listenGlobal,
            getSearch: () => state.search,
            getDays: () => state.days,
            onCounts: () => {
                syncEmptyChips();
                if (state.view === 'website') renderSubchips();
            },
            onBreakdown: () => syncCards(),
        });

        const loadActiveView = () => {
            if (state.view === 'website') {
                websiteActivity?.load(true);
            } else if (state.view === 'requests') {
                fetchRequests(1);
            } else if (state.view === 'conversations' || state.view === 'tasks') {
                fetchLogs();
            } else {
                fetchFeed(true);
            }
        };

        const scheduleSearch = (value) => {
            clearSelectedLogs();
            state.search = value.trim();
            state.page = 1;
            if (searchTimer) {
                clearTimeout(searchTimer);
            }
            searchTimer = window.setTimeout(() => {
                if (state.view !== 'requests') {
                    loadActiveView();
                }
            }, 300);
        };

        const renderSubchips = () => {
            const box = elements.subchips;
            if (!box) {
                return;
            }
            const overview = state.overview;
            let items = [];
            let current = '';
            let key = '';
            if (state.view === 'conversations') {
                key = 'bot';
                current = state.botId;
                const bots = Array.isArray(overview?.bots) ? overview.bots : [];
                if (bots.length > 1 || current) {
                    items = [{ value: '', label: __('All bots', 'gpt3-ai-content-generator') }]
                        .concat(bots.map((bot) => ({ value: String(bot.id), label: bot.name, count: bot.count })));
                }
            } else if (state.view === 'tasks') {
                key = 'group';
                current = state.group;
                const groups = overview?.task_groups || {};
                const present = Object.keys(TASK_GROUP_LABELS).filter((group) => Number(groups[group] || 0) > 0 || group === current);
                if (present.length > 1 || current) {
                    items = [{ value: '', label: __('All', 'gpt3-ai-content-generator') }]
                        .concat(present.map((group) => ({ value: group, label: TASK_GROUP_LABELS[group], count: Number(groups[group] || 0) })));
                }
            } else if (state.view === 'website' && websiteActivity) {
                ({ key, current, items } = websiteActivity.subchips());
            } else if (state.view === 'requests') {
                key = 'source';
                current = state.requestsSource === 'all' ? '' : state.requestsSource;
                const requests = overview?.requests || {};
                if ((Number(requests.cloud) > 0 && Number(requests.own) > 0) || current) {
                    items = [
                        { value: '', label: __('All sources', 'gpt3-ai-content-generator') },
                        { value: 'cloud', label: __('AI Puffer Cloud', 'gpt3-ai-content-generator'), count: Number(requests.cloud || 0) },
                        { value: 'provider', label: __('Own keys', 'gpt3-ai-content-generator'), count: Number(requests.own || 0) },
                    ];
                }
            }
            box.hidden = !items.length;
            box.dataset.key = key;
            if (!items.length) {
                box.innerHTML = '';
                return;
            }
            const labels = {
                bot: __('Filter by chatbot', 'gpt3-ai-content-generator'),
                group: __('Filter by type', 'gpt3-ai-content-generator'),
                source: __('Filter by source', 'gpt3-ai-content-generator'),
                website: __('Filter website activity', 'gpt3-ai-content-generator'),
            };
            box.setAttribute('aria-label', labels[key] || '');
            const maxChips = 6;
            const chips = key === 'bot' && items.length > maxChips + 1 ? items.slice(0, maxChips) : items;
            const overflow = items.slice(chips.length);
            const countHtml = (item) => (item.count !== undefined ? `<span class="aipkit_usage_chip_count">${escapeHtml(formatNumber(item.count))}</span>` : '');
            box.innerHTML = chips.map((item) => `
                <button type="button" class="aipkit_usage_subchip" data-aipkit-usage-sub="${escapeHtml(item.value)}" aria-pressed="${item.value === current ? 'true' : 'false'}">${escapeHtml(item.label)}${countHtml(item)}</button>
            `).join('') + (overflow.length ? `
                <label class="aipkit_usage_more_bots">
                    <span class="screen-reader-text">${escapeHtml(__('More bots', 'gpt3-ai-content-generator'))}</span>
                    <select data-aipkit-usage-sub-select>
                        <option value="">${escapeHtml(__('More bots', 'gpt3-ai-content-generator'))}</option>
                        ${overflow.map((item) => `<option value="${escapeHtml(item.value)}"${item.value === current ? ' selected' : ''}>${escapeHtml(`${item.label} (${formatNumber(item.count)})`)}</option>`).join('')}
                    </select>
                </label>
            ` : '');
        };

        const applySubFilter = (value) => {
            const key = elements.subchips?.dataset.key;
            if (key === 'bot') {
                state.botId = value;
            } else if (key === 'group') {
                state.group = value;
            } else if (key === 'source') {
                state.requestsSource = value || 'all';
            } else if (key === 'website' && websiteActivity) {
                websiteActivity.setFilter(value);
            } else {
                return;
            }
            state.page = 1;
            clearSelectedLogs();
            renderSubchips();
            loadActiveView();
        };

        // A view with nothing in the period has no chip; the open view keeps its chip, and so does an unknown count.
        // Website keeps its chip while website management is off: its empty view is where setup starts.
        const syncEmptyChips = (counts = {
            conversations: state.overview?.conversations,
            tasks: state.overview?.tasks,
            requests: state.overview?.requests?.total,
            website: websiteActivity?.count() ?? null,
        }) => {
            elements.viewChips.forEach((chip) => {
                const view = chip.dataset.aipkitUsageView;
                if (!['conversations', 'tasks', 'requests'].includes(view) && !(view === 'website' && websiteActivity)) {
                    return;
                }
                const count = counts[view];
                if (view === 'website' && websiteActivity.connected() === false) {
                    chip.hidden = false;
                    return;
                }
                chip.hidden = state.view !== view && count !== null && count !== undefined && Number(count) === 0;
            });
        };

        // Errors shows only when the period has failures (or they couldn't be counted), or while it is open.
        const syncErrorsChip = () => {
            const chip = container.querySelector('[data-aipkit-usage-view="errors"]');
            const errors = state.overview?.errors;
            if (chip) {
                chip.hidden = state.view !== 'errors' && !(state.overview && (errors === null || Number(errors) > 0));
            }
        };

        const renderOverview = () => {
            const overview = state.overview;
            setPeriodLabels();
            const counts = overview
                ? { conversations: overview.conversations, tasks: overview.tasks, requests: overview.requests?.total, errors: overview.errors }
                : {};
            // Website activity has no period: its count covers the kept history.
            counts.website = websiteActivity?.count() ?? null;
            elements.viewChips.forEach((chip) => {
                const view = chip.dataset.aipkitUsageView;
                const countElement = chip.querySelector('[data-aipkit-usage-count]');
                const count = counts[view];
                if (countElement) {
                    countElement.textContent = count === null || count === undefined ? '' : formatNumber(count);
                }
            });
            syncEmptyChips(counts);
            syncErrorsChip();
            const requests = overview?.requests || {};
            elements.summaryValues.forEach((value) => {
                const key = value.dataset.aipkitUsageSummary;
                const count = key === 'requests' ? requests.total : overview?.[key];
                value.textContent = overview && count !== undefined && count !== null ? formatNumber(count) : '—';
            });
            syncCards();
            renderSubchips();
            // The empty Everything message links to AI requests once their count is known.
            if (state.view === 'everything' && elements.feedList && !elements.feedList.hasAttribute('aria-busy') && elements.feedList.querySelector('.aipkit_usage_state')) {
                elements.feedList.innerHTML = renderFeedEmpty();
            }
        };

        const fetchOverview = async () => {
            if (typeof window.aipkit_apiRequest !== 'function') {
                return;
            }
            const sequence = ++state.overviewSequence;
            container.classList.add('is-overview-loading');
            elements.viewChips.forEach((chip) => {
                const countElement = chip.querySelector('[data-aipkit-usage-count]');
                if (countElement) {
                    countElement.textContent = '';
                }
            });
            try {
                const overview = await window.aipkit_apiRequest('aipkit_stats_get_overview', { days: state.days });
                if (sequence !== state.overviewSequence) {
                    return;
                }
                state.overview = overview && typeof overview === 'object' ? overview : null;
            } catch (error) {
                if (sequence !== state.overviewSequence) {
                    return;
                }
                state.overview = null;
            }
            container.classList.remove('is-overview-loading');
            renderOverview();
        };

        // The header's one refresh: its submit refreshes AI credits (and, through Cloud, website actions); this adds the
        // actions used. Without a credits card there is no form, so website actions are read directly.
        elements.refresh?.addEventListener('click', () => {
            if (!elements.refresh.form) void refreshWebsiteActions(document, true);
            websiteActivity?.loadBreakdown();
        });

        const refreshActivity = () => {
            websiteActivity?.loadBreakdown();
            fetchOverview();
            loadActiveView();
        };

        const setView = (view, { load = true } = {}) => {
            const nextView = VIEWS.includes(view) && (view !== 'website' || websiteActivity) ? view : 'everything';
            if (nextView !== state.view) {
                state.view = nextView;
                state.page = 1;
                state.group = '';
                state.botId = '';
                state.requestsSource = 'all';
                clearSelectedLogs();
            }
            setStoredValue(VIEW_STORAGE_KEY, nextView);
            elements.viewChips.forEach((chip) => {
                const isActive = chip.dataset.aipkitUsageView === nextView;
                chip.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
            syncEmptyChips();
            syncErrorsChip();
            const panel = nextView === 'requests' || nextView === 'website' ? nextView : (nextView === 'conversations' || nextView === 'tasks' ? 'logs' : 'feed');
            elements.viewPanels.forEach((element) => {
                element.hidden = element.dataset.aipkitUsagePanel !== panel;
            });
            const searchField = elements.searchInput?.closest('.aipkit_usage_search');
            if (searchField) {
                searchField.hidden = nextView === 'requests';
            }
            [elements.exportBtn, elements.deleteAllBtn].forEach((item) => {
                if (item) {
                    item.disabled = nextView === 'requests' || nextView === 'website';
                }
            });
            renderSubchips();
            if (load) {
                loadActiveView();
            }
        };

        const setPeriodUi = () => {
            elements.periodButtons.forEach((button) => {
                const current = Number(button.dataset.aipkitUsageDays) === state.days;
                button.setAttribute('aria-checked', current ? 'true' : 'false');
                if (current && elements.periodCurrent) elements.periodCurrent.textContent = button.textContent.trim();
            });
            setPeriodLabels();
        };

        // The period menu: opens on the current period; arrows, Home and End move; Enter picks; Escape or Tab closes.
        const periodItems = () => [...elements.periodButtons];
        const togglePeriodMenu = (open, { focus = true } = {}) => {
            if (!elements.periodMenu || !elements.periodToggle) return;
            elements.periodMenu.hidden = !open;
            elements.periodToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                (periodItems().find((item) => item.getAttribute('aria-checked') === 'true') || periodItems()[0])?.focus({ preventScroll: true });
            } else if (focus) {
                elements.periodToggle.focus({ preventScroll: true });
            }
        };
        elements.periodToggle?.addEventListener('click', () => {
            togglePeriodMenu(elements.periodMenu?.hidden !== false);
        });
        elements.periodToggle?.addEventListener('keydown', (event) => {
            if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
            event.preventDefault();
            togglePeriodMenu(true);
        });
        elements.periodMenu?.addEventListener('keydown', (event) => {
            const items = periodItems();
            const index = items.indexOf(document.activeElement);
            const moves = { ArrowDown: index + 1, ArrowUp: index - 1, Home: 0, End: items.length - 1 };
            if (event.key in moves) {
                event.preventDefault();
                items[(moves[event.key] + items.length) % items.length]?.focus({ preventScroll: true });
            } else if (event.key === 'Escape') {
                event.preventDefault();
                togglePeriodMenu(false);
            } else if (event.key === 'Tab') {
                togglePeriodMenu(false, { focus: false });
            }
        });
        listenGlobal(document, 'click', (event) => {
            if (elements.periodMenu?.hidden === false && !event.target.closest?.('[data-aipkit-usage-period]')) {
                togglePeriodMenu(false, { focus: false });
            }
        });

        // Visitor billing: the customer dashboard and buy-credits links.
        const saveCustomerLinks = async () => {
            const customerDashboardUrl = elements.customerDashboardUrl
                ? String(elements.customerDashboardUrl.value || '').trim()
                : '';
            const customerBuyCreditsUrl = elements.customerBuyCreditsUrl
                ? String(elements.customerBuyCreditsUrl.value || '').trim()
                : '';
            showStatus(__('Saving…', 'gpt3-ai-content-generator'), 'loading');
            try {
                const response = await window.aipkit_apiRequest('aipkit_stats_save_settings', {
                    customer_dashboard_page_url: customerDashboardUrl,
                    customer_buycredits_url: customerBuyCreditsUrl,
                });
                if (elements.customerDashboardUrl) {
                    elements.customerDashboardUrl.dataset.defaultValue = customerDashboardUrl;
                }
                if (elements.customerBuyCreditsUrl) {
                    elements.customerBuyCreditsUrl.dataset.defaultValue = customerBuyCreditsUrl;
                }
                updateShortcodeSnippet();
                showStatus(response.message || __('Settings saved.', 'gpt3-ai-content-generator'), 'success');
                window.setTimeout(() => showStatus(''), 2000);
            } catch (error) {
                showStatus(error.message || __('The settings couldn’t be saved.', 'gpt3-ai-content-generator'), 'error');
            }
        };

        const queueSettingsSave = () => {
            if (settingsSaveTimer) {
                window.clearTimeout(settingsSaveTimer);
            }
            settingsSaveTimer = window.setTimeout(() => {
                settingsSaveTimer = null;
                saveCustomerLinks();
            }, 450);
        };

        elements.periodButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const days = Number(button.dataset.aipkitUsageDays);
                togglePeriodMenu(false);
                if (!VALID_DAY_VALUES.includes(String(days)) || days === state.days) {
                    return;
                }
                state.days = days;
                setStoredValue(DAYS_STORAGE_KEY, state.days);
                state.page = 1;
                clearSelectedLogs();
                setPeriodUi();
                refreshActivity();
            });
        });

        elements.viewChips.forEach((chip) => {
            chip.addEventListener('click', () => {
                setView(chip.dataset.aipkitUsageView);
            });
        });

        if (elements.subchips) {
            elements.subchips.addEventListener('click', (event) => {
                const chip = event.target.closest('[data-aipkit-usage-sub]');
                if (!chip) {
                    return;
                }
                const value = chip.dataset.aipkitUsageSub || '';
                applySubFilter(value);
                Array.from(elements.subchips.querySelectorAll('[data-aipkit-usage-sub]'))
                    .find((item) => item.dataset.aipkitUsageSub === value)
                    ?.focus({ preventScroll: true });
            });
            elements.subchips.addEventListener('change', (event) => {
                if (!event.target.matches('[data-aipkit-usage-sub-select]')) {
                    return;
                }
                applySubFilter(event.target.value || '');
                elements.subchips.querySelector('[data-aipkit-usage-sub-select]')?.focus({ preventScroll: true });
            });
        }

        if (elements.feedList) {
            elements.feedList.addEventListener('click', (event) => {
                const row = event.target.closest('.aipkit_usage_feed_row[data-log-id]');
                if (row) {
                    fetchDetail(row.dataset.logId, row);
                    return;
                }
                const website = event.target.closest('[data-website-op]');
                if (website && websiteActivity) {
                    websiteActivity.openDetail(website.dataset.websiteOp, website);
                    return;
                }
                const goto = event.target.closest('[data-aipkit-usage-goto]');
                if (goto) {
                    setView(goto.dataset.aipkitUsageGoto);
                }
            });
        }

        if (elements.feedMore) {
            elements.feedMore.addEventListener('click', () => {
                fetchFeed(false);
            });
        }

        if (elements.ledgerDays) {
            elements.ledgerDays.addEventListener('change', () => {
                state.pricingManagementLoaded = false;
                fetchPricingManagement();
            });
        }

        container.addEventListener('click', (event) => {
            const drawerOpener = event.target.closest('[data-aipkit-usage-drawer-open]');
            if (drawerOpener) {
                const overlay = document.getElementById(drawerOpener.dataset.aipkitUsageDrawerOpen);
                if (overlay && overlay === elements.billingDrawer) {
                    toggleTableMenu(false);
                    activateTab(state.activeTab);
                    openDrawer(overlay, elements.tableMenuTrigger);
                } else {
                    openDrawer(overlay, drawerOpener);
                }
                return;
            }
            if (event.target.closest('[data-aipkit-usage-drawer-close]') || event.target.matches('[data-aipkit-usage-drawer]')) {
                closeDrawer();
                return;
            }
            if (event.target.closest('[data-aipkit-usage-retry]')) {
                loadActiveView();
            }
        });

        listenGlobal(document, 'keydown', (event) => {
            const overlay = drawerState.open;
            if (!overlay || !container.isConnected || document.querySelector('.aipkit-modal-overlay.aipkit-active')) {
                return;
            }
            if (event.key === 'Escape') {
                if (elements.tableMenu && !elements.tableMenu.hidden) {
                    return;
                }
                event.preventDefault();
                closeDrawer();
                return;
            }
            if (event.key !== 'Tab') {
                return;
            }
            const focusable = Array.from(overlay.querySelectorAll(FOCUSABLE_SELECTOR))
                .filter((element) => element.getClientRects().length > 0);
            if (!focusable.length) {
                return;
            }
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && (document.activeElement === first || !overlay.contains(document.activeElement))) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && (document.activeElement === last || !overlay.contains(document.activeElement))) {
                event.preventDefault();
                first.focus();
            }
        });

        if (elements.tabButtons && elements.tabButtons.length) {
            elements.tabButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    activateTab(button.dataset.aipkitStatsTab || 'logs');
                });

                button.addEventListener('keydown', (event) => {
                    const currentIndex = Array.from(elements.tabButtons).indexOf(button);
                    if (currentIndex === -1) {
                        return;
                    }

                    let nextIndex = null;
                    if (event.key === 'ArrowRight') {
                        nextIndex = (currentIndex + 1) % elements.tabButtons.length;
                    } else if (event.key === 'ArrowLeft') {
                        nextIndex = (currentIndex - 1 + elements.tabButtons.length) % elements.tabButtons.length;
                    } else if (event.key === 'Home') {
                        nextIndex = 0;
                    } else if (event.key === 'End') {
                        nextIndex = elements.tabButtons.length - 1;
                    }

                    if (nextIndex === null) {
                        return;
                    }

                    event.preventDefault();
                    const nextButton = elements.tabButtons[nextIndex];
                    if (!nextButton) {
                        return;
                    }
                    nextButton.focus();
                    activateTab(nextButton.dataset.aipkitStatsTab || 'logs');
                });
            });
        }

        if (elements.pricingUsageType) {
            elements.pricingUsageType.addEventListener('change', () => {
                const parsedValue = parsePricingUsageTypeValue(elements.pricingUsageType.value);
                if (elements.pricingModule) {
                    elements.pricingModule.value = parsedValue.module;
                }
                populatePricingOperationOptions(parsedValue.operation, false);
            });
        }

        if (elements.pricingModule) {
            elements.pricingModule.addEventListener('change', () => {
                populatePricingOperationOptions();
            });
        }

        if (elements.pricingOperation) {
            elements.pricingOperation.addEventListener('change', () => {
                populatePricingBillingMethodOptions();
                populatePricingAiSelection();
            });
        }

        if (elements.pricingBillingMethod) {
            elements.pricingBillingMethod.addEventListener('change', () => {
                populatePricingBillingMethodOptions(elements.pricingBillingMethod.value);
            });
        }

        if (elements.pricingAiSelection) {
            elements.pricingAiSelection.addEventListener('change', () => {
                syncPricingAiFields();
            });
        }

        if (elements.pricingForm) {
            elements.pricingForm.addEventListener('submit', (event) => {
                event.preventDefault();
                savePricingRule();
            });
        }

        if (elements.pricingOpen) {
            elements.pricingOpen.addEventListener('click', () => {
                openPricingModal('new');
            });
        }

        if (elements.pricingReset) {
            elements.pricingReset.addEventListener('click', (event) => {
                event.preventDefault();
                closePricingModal(true);
            });
        }

        if (elements.pricingModalClose) {
            elements.pricingModalClose.addEventListener('click', () => {
                closePricingModal(true);
            });
        }

        if (elements.pricingModal) {
            elements.pricingModal.addEventListener('click', (event) => {
                if (event.target === elements.pricingModal) {
                    closePricingModal(true);
                }
            });
        }

        if (elements.retentionOpen) {
            elements.retentionOpen.addEventListener('click', (event) => {
                event.preventDefault();
                toggleTableMenu(false);
                openRetentionModal();
            });
        }

        if (elements.retentionModalClose) {
            elements.retentionModalClose.addEventListener('click', () => {
                closeRetentionModal();
            });
        }

        if (elements.retentionModal) {
            elements.retentionModal.addEventListener('click', (event) => {
                if (event.target === elements.retentionModal || event.target.closest('[data-aipkit-retention-close]')) {
                    closeRetentionModal();
                }
            });
        }

        if (elements.searchInput) {
            elements.searchInput.addEventListener('input', (event) => {
                scheduleSearch(event.target.value || '');
            });
        }

        if (elements.tableHeader) {
            elements.tableHeader.addEventListener('change', (event) => {
                if (event.target.id !== 'aipkit_stats_logs_select_all') {
                    return;
                }
                state.visibleLogs
                    .map((log) => Number(log.id))
                    .filter(Boolean)
                    .forEach((logId) => {
                        if (event.target.checked) {
                            state.selectedLogIds.add(logId);
                        } else {
                            state.selectedLogIds.delete(logId);
                        }
                    });
                syncLogSelectionUi();
            });
        }

        if (elements.clearLogSelection) {
            elements.clearLogSelection.addEventListener('click', clearSelectedLogs);
        }

        if (elements.bulkDeleteLogs) {
            elements.bulkDeleteLogs.addEventListener('click', deleteSelectedLogs);
        }

        if (elements.tableBody) {
            elements.tableBody.addEventListener('change', (event) => {
                const checkbox = event.target.closest('.aipkit_stats_log_checkbox');
                if (!checkbox) {
                    return;
                }
                const row = checkbox.closest('tr[data-log-id]');
                const logId = Number(row?.getAttribute('data-log-id') || 0);
                if (!logId) {
                    return;
                }
                if (checkbox.checked) {
                    state.selectedLogIds.add(logId);
                } else {
                    state.selectedLogIds.delete(logId);
                }
                syncLogSelectionUi();
            });

            elements.tableBody.addEventListener('click', (event) => {
                if (event.target.closest('.aipkit_stats_log_checkbox, .aipkit_stats_log_select_cell, a')) {
                    return;
                }
                const row = event.target.closest('tr[data-log-id]');
                if (!row) {
                    return;
                }
                fetchDetail(row.getAttribute('data-log-id'), row.querySelector('[data-log-open]') || row);
            });
        }

        if (elements.detailDrawer) {
            elements.detailDrawer.addEventListener('click', (event) => {
                const copyPayloadBtn = event.target.closest('[data-aipkit-copy-payload]');
                if (copyPayloadBtn) {
                    const rawPayload = document.getElementById(copyPayloadBtn.getAttribute('data-aipkit-copy-payload'))?.value || '';
                    if (!rawPayload) {
                        return;
                    }
                    copyTextToClipboard(rawPayload).then(() => {
                        copyPayloadBtn.textContent = __('Copied', 'gpt3-ai-content-generator');
                        window.setTimeout(() => {
                            copyPayloadBtn.textContent = __('Copy JSON', 'gpt3-ai-content-generator');
                        }, 1400);
                    }).catch(() => {
                        showStatus(__('The request payload couldn’t be copied.', 'gpt3-ai-content-generator'), 'error');
                    });
                    return;
                }
                const ipBlockBtn = event.target.closest('.aipkit_stats_detail_ip_action');
                if (ipBlockBtn) {
                    setLogIpBlocked(ipBlockBtn);
                    return;
                }
                const deleteBtn = event.target.closest('.aipkit_stats_detail_delete');
                if (deleteBtn) {
                    deleteSingleLog(deleteBtn.getAttribute('data-log-id'));
                    return;
                }
                if (event.target.closest('[data-aipkit-usage-export]')) {
                    exportDetail();
                    return;
                }
                const retryBtn = event.target.closest('[data-aipkit-usage-detail-retry]');
                if (retryBtn) {
                    fetchDetail(retryBtn.getAttribute('data-aipkit-usage-detail-retry'), false);
                    return;
                }
                const clampBtn = event.target.closest('[data-aipkit-usage-clamp]');
                if (clampBtn) {
                    const target = document.getElementById(clampBtn.getAttribute('data-aipkit-usage-clamp'));
                    const expanded = clampBtn.getAttribute('aria-expanded') === 'true';
                    target?.classList.toggle('is-clamped', expanded);
                    clampBtn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                    clampBtn.textContent = expanded ? __('Show all', 'gpt3-ai-content-generator') : __('Show less', 'gpt3-ai-content-generator');
                    return;
                }
                const toggleBtn = event.target.closest('[data-aipkit-stats-toggle]');
                if (toggleBtn) {
                    const targetElement = document.getElementById(toggleBtn.getAttribute('data-aipkit-stats-toggle'));
                    if (!targetElement) {
                        return;
                    }
                    const isExpanded = toggleBtn.getAttribute('aria-expanded') === 'true';
                    targetElement.hidden = isExpanded;
                    toggleBtn.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
                }
            });
        }

        if (elements.tableMenuTrigger && elements.tableMenu) {
            elements.tableMenuTrigger.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                toggleTableMenu(elements.tableMenu.hidden);
            });
        }

        listenGlobal(document, 'click', (event) => {
            if (!elements.tableMenu || elements.tableMenu.hidden) {
                return;
            }
            if (event.target.closest('#aipkit_stats_table_menu') || event.target.closest('#aipkit_stats_table_menu_trigger')) {
                return;
            }
            toggleTableMenu(false);
        });

        listenGlobal(document, 'keydown', (event) => {
            if (event.key !== 'Escape' || !elements.tableMenu || elements.tableMenu.hidden) {
                return;
            }
            toggleTableMenu(false, true);
        });

        listenGlobal(window, 'resize', positionTableMenu);
        listenGlobal(window, 'scroll', positionTableMenu, { capture: true, passive: true });

        listenGlobal(document, 'keydown', (event) => {
            if (event.key === 'Escape' && elements.pricingModal?.classList.contains('aipkit-active')) {
                closePricingModal(true);
            }
        });

        listenGlobal(document, 'keydown', (event) => {
            if (event.key === 'Escape' && elements.retentionModal?.classList.contains('aipkit-active')) {
                closeRetentionModal();
            }
        });

        if (elements.deleteAllBtn) {
            elements.deleteAllBtn.addEventListener('click', () => {
                toggleTableMenu(false, true);
                deleteAllLogs();
            });
        }

        if (elements.exportBtn) {
            elements.exportBtn.addEventListener('click', () => {
                toggleTableMenu(false, true);
                exportLogs();
            });
        }

        if (elements.pricingRulesBody) {
            elements.pricingRulesBody.addEventListener('click', (event) => {
                const statusControl = event.target.closest('[data-pricing-toggle="enabled"]');
                if (statusControl) {
                    const ruleId = statusControl.getAttribute('data-rule-id');
                    if (!ruleId) {
                        return;
                    }
                    const nextEnabled = statusControl.getAttribute('aria-pressed') !== 'true';
                    togglePricingRuleStatus(ruleId, nextEnabled, statusControl);
                    return;
                }

                const button = event.target.closest('[data-pricing-action]');
                if (!button) {
                    return;
                }
                const ruleId = button.getAttribute('data-rule-id');
                const action = button.getAttribute('data-pricing-action');
                if (!ruleId || !action) {
                    return;
                }
                const rule = state.pricingRules.find((item) => String(item.id) === String(ruleId));
                if (action === 'edit') {
                    openPricingModal('edit', rule);
                } else if (action === 'delete') {
                    deletePricingRule(ruleId);
                }
            });
        }

        if (elements.shortcodeOptions && elements.shortcodeOptions.length) {
            elements.shortcodeOptions.forEach((option) => {
                option.addEventListener('change', updateShortcodeSnippet);
            });
            updateShortcodeSnippet();
        }

        if (elements.shortcodeTextOptions && elements.shortcodeTextOptions.length) {
            elements.shortcodeTextOptions.forEach((input) => {
                input.addEventListener('input', updateShortcodeSnippet);
                input.addEventListener('change', updateShortcodeSnippet);
                if (input.name === 'cfg_dashboard_url' || input.name === 'cfg_buycredits_url') {
                    input.addEventListener('input', queueSettingsSave);
                    input.addEventListener('change', () => {
                        if (settingsSaveTimer) {
                            window.clearTimeout(settingsSaveTimer);
                            settingsSaveTimer = null;
                        }
                        saveCustomerLinks();
                    });
                }
            });
            updateShortcodeSnippet();
        }

        if (elements.shortcodeSnippet) {
            elements.shortcodeSnippet.addEventListener('click', () => {
                if (typeof window.aipkit_copyShortcode === 'function') {
                    const shortcode = elements.shortcodeSnippet.dataset.shortcode
                        || elements.shortcodeSnippet.querySelector('.aipkit_stats_shortcode_text')?.textContent
                        || elements.shortcodeSnippet.textContent;
                    window.aipkit_copyShortcode(shortcode.trim(), elements.shortcodeSnippet);
                }
            });
        }

        if (elements.userSearchInput) {
            elements.userSearchInput.addEventListener('input', () => {
                clearTimeout(userSearchTimer);
                const value = elements.userSearchInput.value.trim();
                userSearchTimer = window.setTimeout(() => {
                    fetchUserCredits(1, value);
                }, 350);
            });
            elements.userSearchInput.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    clearTimeout(userSearchTimer);
                    const value = elements.userSearchInput.value.trim();
                    fetchUserCredits(1, value);
                    if (elements.userSearchClear) {
                        elements.userSearchClear.hidden = value.length === 0;
                    }
                }
            });
        }

        if (elements.userSearchClear) {
            elements.userSearchClear.addEventListener('click', () => {
                if (elements.userSearchInput) {
                    elements.userSearchInput.value = '';
                }
                elements.userSearchClear.hidden = true;
                fetchUserCredits(1, '');
            });
        }

        if (elements.userTableBody) {
            const listenerAttr = 'data-user-credits-listeners';
            if (!elements.userTableBody.getAttribute(listenerAttr)) {
                elements.userTableBody.addEventListener('change', (event) => {
                    if (event.target.matches('.aipkit_stats_credits_balance_input')) {
                        handleBalanceUpdate(event.target);
                    }
                });

                elements.userTableBody.addEventListener('keypress', (event) => {
                    if (event.key === 'Enter' && event.target.matches('.aipkit_stats_credits_balance_input')) {
                        event.preventDefault();
                        event.target.blur();
                    }
                });

                elements.userTableBody.addEventListener('click', (event) => {
                    const detailBtn = event.target.closest('[data-user-activity-toggle]');
                    if (detailBtn) {
                        event.preventDefault();
                        const userId = detailBtn.dataset.userActivityToggle || '';
                        const isExpanded = detailBtn.getAttribute('aria-expanded') === 'true';
                        setExpandedUserDetail(isExpanded ? '' : userId);
                        return;
                    }

                    const resetUsageBtn = event.target.closest('[data-user-reset-usage]');
                    if (resetUsageBtn) {
                        event.preventDefault();
                        handleUsageReset(resetUsageBtn);
                        return;
                    }

                    const resetAllBtn = event.target.closest('[data-user-reset-all-usage]');
                    if (resetAllBtn) {
                        event.preventDefault();
                        handleResetAllUsage(resetAllBtn);
                        return;
                    }

                    const historyBtn = event.target.closest('.aipkit_stats_purchase_history_btn');
                    if (historyBtn) {
                        event.preventDefault();
                        const history = historyBtn.dataset.history ? JSON.parse(historyBtn.dataset.history) : [];
                        openPurchaseHistoryModal(history, historyBtn.dataset.userName || '');
                    }
                });

                elements.userTableBody.setAttribute(listenerAttr, 'true');
            }
        }

        resetPricingForm();
        closePricingModal(false);

        const requestedTab = readRequestedTab();
        if (requestedTab === 'requests') {
            state.view = 'requests';
        } else if (requestedTab === 'logs') {
            state.view = 'conversations';
        } else if (requestedTab === 'website') {
            state.view = 'website';
        }
        setPeriodUi();
        setView(state.view, { load: false });
        syncCards();
        refreshActivity();
        if (state.view !== 'website') {
            websiteActivity?.prefetchCounts();
        }
        refreshCloudCredits(container);
        refreshWebsiteActions(container);
        if (BILLING_TABS.includes(requestedTab) && elements.billingDrawer) {
            activateTab(requestedTab);
            openDrawer(elements.billingDrawer, elements.tableMenuTrigger);
        } else if (requestedTab === 'cloud') {
            container.querySelector('#aipkit_usage_cloud_credits')?.scrollIntoView({ block: 'nearest' });
        }
    }

    window.aipkit_initStats = aipkit_initStats;
})();
