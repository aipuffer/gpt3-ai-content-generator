import { summarizeRequestPayload } from './request-payload-summary.js';
import { bindCloudConnection, refreshCloudCredits } from '../settings/cloud-connection.js';

(function () {
    'use strict';

    const DEFAULT_DAYS = 7;
    const DEFAULT_PER_PAGE = 10;
    const LOGS_PER_PAGE_STORAGE_KEY = 'aipkit_stats_logs_per_page';
    const LOGS_PER_PAGE_OPTIONS = [5, 10, 20, 50];
    const DAYS_STORAGE_KEY = 'aipkit_stats_days';
    const REQUESTS_DAYS_STORAGE_KEY = 'aipkit_stats_requests_days';
    const BOT_FILTER_STORAGE_KEY = 'aipkit_stats_bot_filter';
    const MODULE_FILTER_STORAGE_KEY = 'aipkit_stats_module_filter';
    const VALID_TABS = ['logs', 'requests', 'cloud', 'pricing', 'activity', 'balances', 'woocommerce'];
    const VALID_DAY_VALUES = ['7', '30', '90'];
    const REQUESTS_DAY_VALUES = [...VALID_DAY_VALUES, '0'];
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
        bindCloudConnection(container);
        const defaultTab = 'logs';

        if (typeof window.aipkit_initContentWriterPopovers === 'function') {
            window.aipkit_initContentWriterPopovers(container);
        }
        const state = {
            days: parseInt(container.dataset.defaultDays || DEFAULT_DAYS, 10) || DEFAULT_DAYS,
            perPage: DEFAULT_PER_PAGE,
            page: 1,
            search: '',
            botId: '',
            module: '',
            selectedLogId: null,
            selectedLogIds: new Set(),
            visibleLogs: [],
            pricingRules: [],
            activeTab: defaultTab,
            logsLoaded: false,
            requestsDays: DEFAULT_DAYS,
            requestsLoaded: false,
            requestsPage: 1,
            selectedRequestIds: new Set(),
            deletingRequests: false,
            requestsSequence: 0,
            pricingManagementLoaded: false,
            creditsLoaded: false,
        };

        const __ = (wp.i18n && wp.i18n.__) ? wp.i18n.__ : (text) => text;

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
        let hasAutoSelected = false;

        const elements = {
            status: container.querySelector('#aipkit_stats_status'),
            sectionButtons: container.querySelectorAll('[data-aipkit-stats-section]'),
            sectionPanels: container.querySelectorAll('[data-aipkit-stats-section-panel]'),
            tabButtons: container.querySelectorAll('[data-aipkit-stats-tab]'),
            tabPanels: container.querySelectorAll('[data-aipkit-stats-panel]'),
            daysFilters: container.querySelectorAll('[data-aipkit-stats-days-filter]'),
            requestsDays: container.querySelector('#aipkit_stats_requests_days'),
            botFilter: container.querySelector('#aipkit_stats_bot_filter'),
            moduleFilter: container.querySelector('#aipkit_stats_module_filter'),
            searchInput: container.querySelector('#aipkit_stats_search_input'),
            exportBtn: container.querySelector('#aipkit_stats_export_btn'),
            deleteAllBtn: container.querySelector('#aipkit_stats_delete_all_btn'),
            tableMenu: container.querySelector('#aipkit_stats_table_menu'),
            tableMenuTrigger: container.querySelector('#aipkit_stats_table_menu_trigger'),
            filtersToggleBtn: container.querySelector('#aipkit_stats_filters_toggle'),
            logsToolbar: container.querySelector('#aipkit_stats_logs_toolbar'),
            filtersResetBtn: container.querySelector('#aipkit_stats_filters_reset_btn'),
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
            selectAllLogs: container.querySelector('#aipkit_stats_logs_select_all'),
            selectionCount: container.querySelector('#aipkit_stats_logs_selection_count'),
            bulkDeleteLogs: container.querySelector('#aipkit_stats_logs_bulk_delete'),
            clearLogSelection: container.querySelector('#aipkit_stats_logs_selection_clear'),
            pagination: container.querySelector('#aipkit_stats_pagination'),
            detailIdentity: container.querySelector('#aipkit_stats_detail_identity'),
            detailPanel: container.querySelector('#aipkit_stats_detail_panel'),
            settingsToggle: document.getElementById('aipkit_stats_auto_delete_toggle'),
            retentionSelect: document.getElementById('aipkit_stats_retention_days'),
            retentionRow: container.querySelector('[data-aipkit-stats-retention-row]'),
            cronRow: container.querySelector('[data-aipkit-stats-cron-row]'),
            cronStatus: document.getElementById('aipkit_stats_cron_status'),
            cronText: document.getElementById('aipkit_stats_cron_text'),
            cronLastRun: document.getElementById('aipkit_stats_cron_last_run'),
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

        const readActiveTab = () => {
            try {
                const params = new URLSearchParams(window.location.search || '');
                const requestedTab = String(params.get('aipkit_stats_tab') || '').trim();
                if (VALID_TABS.includes(requestedTab)) {
                    params.delete('aipkit_stats_tab');
                    const nextQuery = params.toString();
                    const nextUrl = `${window.location.pathname}${nextQuery ? `?${nextQuery}` : ''}${window.location.hash || ''}`;
                    window.history.replaceState(window.history.state, '', nextUrl);
                    if (requestedTab === 'cloud') return 'logs';
                    if (requestedTab === 'logs' || requestedTab === 'requests') return requestedTab;
                    return container.querySelector('[data-aipkit-stats-section="billing"]:not([hidden])') ? requestedTab : defaultTab;
                }
            } catch (error) {
                // Use the first available Usage section when the URL cannot be read.
            }
            return defaultTab;
        };

        const readStoredDays = (fallbackDays) => {
            const stored = getStoredValue(DAYS_STORAGE_KEY);
            if (stored && VALID_DAY_VALUES.includes(stored)) {
                return parseInt(stored, 10) || fallbackDays;
            }
            return fallbackDays;
        };

        const readStoredFilter = (storageKey) => {
            const stored = getStoredValue(storageKey);
            return stored === null ? '' : stored;
        };

        const syncSelectValue = (select, value, fallbackValue = '') => {
            if (!select) {
                return String(fallbackValue || '');
            }

            const nextValue = String(value ?? '');
            const hasValue = Array.from(select.options).some((option) => String(option.value) === nextValue);
            const resolvedValue = hasValue ? nextValue : String(fallbackValue ?? '');
            select.value = resolvedValue;
            return resolvedValue;
        };

        const syncDaysFilterControls = (value) => {
            if (!elements.daysFilters || !elements.daysFilters.length) {
                return String(DEFAULT_DAYS);
            }

            const fallbackValue = String(DEFAULT_DAYS);
            let resolvedValue = fallbackValue;

            elements.daysFilters.forEach((select, index) => {
                const nextValue = syncSelectValue(select, String(value), fallbackValue);
                if (index === 0) {
                    resolvedValue = nextValue;
                }
            });

            return resolvedValue;
        };

        state.days = readStoredDays(state.days);
        state.botId = readStoredFilter(BOT_FILTER_STORAGE_KEY);
        state.module = readStoredFilter(MODULE_FILTER_STORAGE_KEY);
        const storedLogsPerPage = parseInt(getStoredValue(LOGS_PER_PAGE_STORAGE_KEY), 10);
        state.perPage = LOGS_PER_PAGE_OPTIONS.includes(storedLogsPerPage)
            ? storedLogsPerPage
            : DEFAULT_PER_PAGE;
        if (elements.daysFilters && elements.daysFilters.length) {
            state.days = parseInt(
                syncDaysFilterControls(state.days),
                10
            ) || DEFAULT_DAYS;
            setStoredValue(DAYS_STORAGE_KEY, state.days);
        }

        const storedRequestsDays = getStoredValue(REQUESTS_DAYS_STORAGE_KEY);
        state.requestsDays = Number(REQUESTS_DAY_VALUES.includes(storedRequestsDays) ? storedRequestsDays : state.days);
        syncSelectValue(elements.requestsDays, state.requestsDays, DEFAULT_DAYS);

        if (elements.botFilter) {
            state.botId = syncSelectValue(elements.botFilter, state.botId, '');
            setStoredValue(BOT_FILTER_STORAGE_KEY, state.botId);
        }

        if (elements.moduleFilter) {
            state.module = syncSelectValue(elements.moduleFilter, state.module, '');
            setStoredValue(MODULE_FILTER_STORAGE_KEY, state.module);
        }

        const setLogFiltersExpanded = (expanded) => {
            if (!elements.filtersToggleBtn || !elements.logsToolbar) {
                return;
            }
            const nextState = Boolean(expanded);
            elements.logsToolbar.hidden = !nextState;
            elements.filtersToggleBtn.setAttribute('aria-expanded', nextState ? 'true' : 'false');
        };

        const syncLogFiltersResetState = () => {
            const hasActiveFilters = (
                Number(state.days) !== Number(DEFAULT_DAYS)
                || Boolean(state.botId)
                || Boolean(state.module)
                || Boolean(state.search)
            );
            if (elements.filtersResetBtn) {
                elements.filtersResetBtn.disabled = !hasActiveFilters;
                elements.filtersResetBtn.setAttribute('aria-disabled', hasActiveFilters ? 'false' : 'true');
            }
            if (elements.filtersToggleBtn) {
                elements.filtersToggleBtn.classList.toggle('aipkit_has_active_filters', hasActiveFilters);
            }
            return hasActiveFilters;
        };

        setLogFiltersExpanded(syncLogFiltersResetState());

        const updateSettingsVisibility = (enabled) => {
            if (elements.retentionRow) {
                elements.retentionRow.hidden = !enabled;
            }
            if (elements.cronRow) {
                elements.cronRow.hidden = !enabled;
            }
            if (elements.retentionSelect) {
                elements.retentionSelect.disabled = !enabled || !isPro;
            }
        };

        const updateCronStatus = (data) => {
            if (!data || !elements.cronStatus) {
                return;
            }
            if (data.state) {
                elements.cronStatus.setAttribute('data-state', data.state);
            }
            if (elements.cronText && data.status_text) {
                elements.cronText.textContent = data.status_text;
            }
            if (elements.cronLastRun && data.last_run_label) {
                elements.cronLastRun.textContent = data.last_run_label;
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
        };

        const openRetentionModal = () => {
            if (!elements.retentionModal) {
                return;
            }

            elements.retentionModal.classList.add('aipkit-active');
            elements.retentionModal.setAttribute('aria-hidden', 'false');
            refreshCronStatus();

            window.setTimeout(() => {
                if (elements.settingsToggle && !elements.settingsToggle.disabled) {
                    elements.settingsToggle.focus();
                    return;
                }
                if (elements.retentionSelect && !elements.retentionSelect.disabled) {
                    elements.retentionSelect.focus();
                    return;
                }
                elements.retentionModalClose?.focus();
            }, 0);
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
            renderTablePlaceholder(elements.requestsBody, 5, __('Loading requests...', 'gpt3-ai-content-generator'));
            if (elements.requestsPagination) elements.requestsPagination.innerHTML = '';
            try {
                const response = await window.aipkit_apiRequest('aipkit_stats_get_requests', { days: state.requestsDays, page });
                if (sequence !== state.requestsSequence) return;
                const requests = Array.isArray(response.requests) ? response.requests : [];
                if (!requests.length) {
                    renderTablePlaceholder(elements.requestsBody, 5, __('No recorded requests in this period.', 'gpt3-ai-content-generator'));
                } else {
                    elements.requestsBody.innerHTML = requests.map((request) => {
                        const provider = String(request.providerLabel || request.provider || __('Unknown provider', 'gpt3-ai-content-generator'));
                        const model = String(request.modelLabel || request.model || '');
                        const time = Date.parse(String(request.createdAt || ''));
                        const cloudCredits = requestCloudCredits(request);
                        const exactCredits = requestExactCredits(request);
                        return `<tr>
                            <td class="aipkit_stats_log_select_cell"><input type="checkbox" class="aipkit_stats_log_checkbox" data-request-id="${Number(request.id)}" aria-label="${escapeHtml(__('Select request', 'gpt3-ai-content-generator'))}" /></td>
                            <td>${escapeHtml(Number.isFinite(time) ? formatTimestamp(time / 1000) : '—')}</td>
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
                state.requestsLoaded = true;
            } catch (error) {
                if (sequence !== state.requestsSequence) return;
                state.requestsLoaded = false;
                renderTablePlaceholder(elements.requestsBody, 5, error.message || __('Failed to load requests.', 'gpt3-ai-content-generator'), true);
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
                } catch (error) {
                    showStatus(error.message || __('Could not delete request history.', 'gpt3-ai-content-generator'), 'error');
                } finally {
                    state.deletingRequests = false;
                    elements.requestsDelete.disabled = false;
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
                    days: state.days,
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
            if (tabKey === 'requests') {
                if (!state.requestsLoaded) fetchRequests(state.requestsPage);
                return;
            }
            if (tabKey === 'balances') {
                if (!state.creditsLoaded) {
                    fetchUserCredits(userCreditsState.page, userCreditsState.search);
                }
                return;
            }

            if (tabKey === 'woocommerce') {
                return;
            }

            if ((tabKey === 'pricing' || tabKey === 'activity') && !state.pricingManagementLoaded) {
                fetchPricingManagement();
                return;
            }

            if (tabKey === 'logs' && !state.logsLoaded) {
                fetchLogs();
            }
        };

        const activateTab = (tabKey, refreshCredits = true) => {
            const nextTab = tabKey === 'cloud' ? 'logs' : VALID_TABS.includes(tabKey) ? tabKey : defaultTab;
            const section = nextTab === 'logs' || nextTab === 'requests' ? nextTab : 'billing';
            state.activeTab = nextTab;

            elements.sectionButtons.forEach((button) => {
                const isActive = button.dataset.aipkitStatsSection === section;
                button.classList.toggle('aipkit_active', isActive);
                button.setAttribute('aria-selected', isActive ? 'true' : 'false');
                button.tabIndex = isActive ? 0 : -1;
            });

            elements.sectionPanels.forEach((panel) => {
                panel.hidden = panel.dataset.aipkitStatsSectionPanel !== section;
            });

            elements.tabButtons.forEach((button) => {
                const isActive = button.dataset.aipkitStatsTab === (section === 'billing' ? nextTab : 'pricing');
                button.classList.toggle('aipkit_active', isActive);
                button.setAttribute('aria-selected', isActive ? 'true' : 'false');
                button.tabIndex = isActive ? 0 : -1;
            });

            elements.tabPanels.forEach((panel) => {
                panel.hidden = panel.dataset.aipkitStatsPanel !== (section === 'billing' ? nextTab : 'pricing');
            });

            ensureTabData(nextTab);
            if (nextTab === 'logs' && refreshCredits) refreshCloudCredits(container);
        };

        const handleCloudConnectionChanged = (event) => {
            if (!container.isConnected) {
                window.removeEventListener('aipkit:cloud-connection-changed', handleCloudConnectionChanged);
                return;
            }
            const connected = Boolean(event.detail?.connected);
            const button = container.querySelector('[data-aipkit-stats-section="logs"]');
            const panel = container.querySelector('[data-aipkit-stats-section-panel="logs"]');
            if (!button || !panel) return;

            const template = document.createElement('template');
            template.innerHTML = String(event.detail?.usageHtml || '');
            const account = template.content.querySelector('#aipkit_cloud_connection');
            if (connected && !account) return;
            const currentAccount = panel.querySelector('#aipkit_cloud_connection');
            if (currentAccount && account) currentAccount.replaceWith(account);
            else if (currentAccount) currentAccount.remove();
            else if (account) panel.prepend(account);

            if (event.detail?.action === 'balance') return;

            const headerHint = container.querySelector('.aipkit_stats_header_hint');
            if (headerHint) headerHint.textContent = connected
                ? __('Manage your AI Puffer credits and review activity on this site.', 'gpt3-ai-content-generator')
                : __('Review conversations and recorded AI requests on this site.', 'gpt3-ai-content-generator');
            state.requestsSequence++;
            state.requestsLoaded = false;
            activateTab(state.activeTab, false);
            if (!panel.hidden) {
                if (event.detail?.action === 'disconnect') button.focus({ preventScroll: true });
                else account?.querySelector('[name="cloud_action"][value="refresh"]')?.focus({ preventScroll: true });
            }
        };
        if (window.aipkit_statsCloudConnectionHandler) {
            window.removeEventListener('aipkit:cloud-connection-changed', window.aipkit_statsCloudConnectionHandler);
        }
        window.aipkit_statsCloudConnectionHandler = handleCloudConnectionChanged;
        window.addEventListener('aipkit:cloud-connection-changed', handleCloudConnectionChanged);

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

        const getModuleIconClass = (moduleKey) => {
            const iconMap = {
                chat: 'dashicons-format-chat',
                chatbot: 'dashicons-format-chat',
                content_writer: 'dashicons-edit-page',
                content_writer_automation: 'dashicons-controls-repeat',
                image_generator: 'dashicons-format-image',
                ai_forms: 'dashicons-feedback',
                ai_form: 'dashicons-feedback',
                forms: 'dashicons-feedback',
                autogpt: 'dashicons-controls-repeat',
                ai_post_enhancer: 'dashicons-edit',
                wp_ai_client: 'dashicons-cloud',
            };
            return iconMap[moduleKey] || 'dashicons-admin-generic';
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
                elements.selectionCount.textContent = `${formatNumber(selectedCount)} ${selectedCount === 1
                    ? __('conversation selected', 'gpt3-ai-content-generator')
                    : __('conversations selected', 'gpt3-ai-content-generator')}`;
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

        const renderLogs = (logs) => {
            if (!elements.tableBody) {
                return;
            }
            state.visibleLogs = Array.isArray(logs) ? logs : [];
            if (!Array.isArray(logs) || !logs.length) {
                renderTablePlaceholder(elements.tableBody, 4, 'No logs found.');
                state.selectedLogIds.clear();
                syncLogSelectionUi();
                return;
            }

            const visibleIds = new Set(logs.map((log) => Number(log.id)).filter(Boolean));
            state.selectedLogIds = new Set(
                Array.from(state.selectedLogIds).filter((logId) => visibleIds.has(logId))
            );

            const rows = logs.map((log) => {
                const preview = log.last_message_content ? String(log.last_message_content) : '';
                const tokens = formatNumber(log.total_conversation_tokens || 0);
                const relativeTime = formatRelativeTimestamp(log.last_message_ts) || '—';
                const logId = Number(log.id);
                const moduleKey = String(log.module || 'chatbot');
                const moduleLabel = getModuleLabel(moduleKey);
                const moduleIconClass = getModuleIconClass(moduleKey);
                const isBulkSelected = state.selectedLogIds.has(logId);
                return `
                    <tr class="aipkit_stats_row${state.selectedLogId === logId ? ' aipkit_stats_row_active' : ''}${isBulkSelected ? ' aipkit_stats_row_bulk_selected' : ''}" data-log-id="${escapeHtml(log.id)}">
                        <td class="aipkit_stats_log_select_cell">
                            <input
                                type="checkbox"
                                class="aipkit_stats_log_checkbox"
                                aria-label="${escapeHtml(__('Select conversation', 'gpt3-ai-content-generator'))}"
                                ${isBulkSelected ? 'checked' : ''}
                            />
                        </td>
                        <td><span class="aipkit_stats_relative_time">${escapeHtml(relativeTime)}</span></td>
                        <td>
                            <div class="aipkit_stats_log_item">
                                <span class="aipkit_stats_log_module_icon" title="${escapeHtml(moduleLabel)}" role="img" aria-label="${escapeHtml(moduleLabel)}">
                                    <span class="dashicons ${escapeHtml(moduleIconClass)}" aria-hidden="true"></span>
                                </span>
                                <span class="aipkit_stats_log_item_text">
                                    <strong class="aipkit_stats_log_user">${escapeHtml(log.user_display_name || '—')}</strong>
                                    <span class="aipkit_stats_preview">${escapeHtml(preview)}</span>
                                </span>
                            </div>
                        </td>
                        <td class="aipkit_stats_log_tokens_cell">${escapeHtml(tokens)}</td>
                    </tr>
                `;
            });
            elements.tableBody.innerHTML = rows.join('');
            syncLogSelectionUi();
        };

        const buildFiltersPayload = () => ({
            days: state.days,
            bot_id: state.botId,
            module: state.module,
            search: state.search,
        });

        const triggerCsvDownload = (csvContent, filename) => {
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
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
            if (!logId) {
                return;
            }
            const confirmed = await confirmDestructiveAction(
                __('This permanently deletes the selected conversation and its saved messages. This cannot be undone.', 'gpt3-ai-content-generator'),
                {
                    title: __('Delete conversation', 'gpt3-ai-content-generator'),
                    confirmText: __('Delete', 'gpt3-ai-content-generator'),
                }
            );
            if (!confirmed) {
                return;
            }
            showStatus('Deleting conversation...');
            try {
                const response = await window.aipkit_apiRequest('aipkit_stats_delete_log', {
                    log_id: logId,
                });
                showStatus(response.message || 'Conversation deleted.');
                if (state.selectedLogId === Number(logId)) {
                    state.selectedLogId = null;
                    renderDetail(null);
                }
                state.selectedLogIds.delete(Number(logId));
                syncLogSelectionUi();
                fetchLogs();
                window.setTimeout(() => showStatus(''), 2000);
            } catch (error) {
                showStatus(error.message || 'Failed to delete conversation.', 'error');
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
                        state.selectedLogId = null;
                        renderDetail(null);
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
                await fetchDetail(logId);
                window.setTimeout(() => showStatus(''), 2000);
            } catch (error) {
                button.disabled = false;
                showStatus(error.message || __('The blocked IP list could not be updated.', 'gpt3-ai-content-generator'), 'error');
            }
        };

        const deleteAllLogs = async () => {
            const confirmed = await confirmDestructiveAction(
                __('This permanently deletes every conversation matching the current filters. This cannot be undone.', 'gpt3-ai-content-generator'),
                {
                    title: __('Delete filtered logs', 'gpt3-ai-content-generator'),
                    confirmText: __('Delete logs', 'gpt3-ai-content-generator'),
                }
            );
            if (!confirmed) {
                return;
            }
            showStatus('Deleting logs...');
            try {
                const response = await window.aipkit_apiRequest('aipkit_stats_delete_logs', buildFiltersPayload());
                showStatus(response.message || 'Logs deleted.');
                state.selectedLogId = null;
                state.selectedLogIds.clear();
                syncLogSelectionUi();
                renderDetail(null);
                fetchLogs();
                window.setTimeout(() => showStatus(''), 2500);
            } catch (error) {
                showStatus(error.message || 'Failed to delete logs.', 'error');
            }
        };

        const exportLogs = async () => {
            showStatus('Preparing export...');
            try {
                const response = await window.aipkit_apiRequest('aipkit_stats_export_logs', buildFiltersPayload());
                if (!response || !response.csv) {
                    throw new Error('Export failed.');
                }
                triggerCsvDownload(response.csv, response.filename);
                showStatus(response.message || 'Export ready.');
                window.setTimeout(() => showStatus(''), 2000);
            } catch (error) {
                showStatus(error.message || 'Failed to export logs.', 'error');
            }
        };

        const resetLogFilters = () => {
            clearSelectedLogs();
            state.days = DEFAULT_DAYS;
            state.botId = '';
            state.module = '';
            state.search = '';
            state.page = 1;

            if (searchTimer) {
                clearTimeout(searchTimer);
                searchTimer = null;
            }

            syncDaysFilterControls(state.days);

            if (elements.botFilter) {
                elements.botFilter.value = '';
            }

            if (elements.moduleFilter) {
                elements.moduleFilter.value = '';
            }

            if (elements.searchInput) {
                elements.searchInput.value = '';
            }

            setStoredValue(DAYS_STORAGE_KEY, state.days);
            setStoredValue(BOT_FILTER_STORAGE_KEY, state.botId);
            setStoredValue(MODULE_FILTER_STORAGE_KEY, state.module);

            syncLogFiltersResetState();
            fetchLogs();
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
                const focusTarget = elements.retentionOpen || elements.exportBtn || elements.deleteAllBtn;
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
                    itemLabelSingular: __('conversation', 'gpt3-ai-content-generator'),
                    itemLabelPlural: __('conversations', 'gpt3-ai-content-generator'),
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

        const renderDetail = (detail) => {
            if (!elements.detailPanel) {
                return;
            }
            if (!detail) {
                if (elements.detailIdentity) {
                    elements.detailIdentity.hidden = true;
                    elements.detailIdentity.innerHTML = '';
                }
                elements.detailPanel.innerHTML = `
                    <div class="aipkit_stats_detail_empty">
                        ${escapeHtml('Select a conversation to view details.')}
                    </div>
                `;
                return;
            }

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
                    const nextRole = resolveMessageRole(messages[index]?.role);
                    if (nextRole === 'bot') {
                        return messages[index];
                    }
                }
                return null;
            };

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
                return `Chunk ${Math.round(chunkNumber)}/${Math.round(total)}`;
            };

            const getScoreFileName = (scoreItem) => {
                const fileName = scoreItem?.file_name;
                return typeof fileName === 'string' ? fileName.trim() : '';
            };

            const messages = Array.isArray(detail.messages) ? detail.messages : [];
            const userDisplayName = String(detail.user_display_name || __('User', 'gpt3-ai-content-generator')).trim();
            const conversationLabel = detail.bot_name || getModuleLabel(detail.module);
            if (elements.detailIdentity) {
                elements.detailIdentity.innerHTML = `
                    <div class="aipkit_stats_detail_identity_name">${escapeHtml(userDisplayName)}</div>
                    <div class="aipkit_stats_detail_identity_meta">
                        ${escapeHtml(conversationLabel)}
                        <span aria-hidden="true">·</span>
                        ${escapeHtml(formatNumber(detail.message_count || messages.length))} ${escapeHtml(__('messages', 'gpt3-ai-content-generator'))}
                    </div>
                `;
                elements.detailIdentity.hidden = false;
            }
            const moduleKey = String(detail.module || '')
                .toLowerCase()
                .replace(/[\s-]+/g, '_');
            const markdownRenderer = (() => {
                if (typeof window.markdownit !== 'function') {
                    return null;
                }
                const renderer = window.markdownit({ html: false, linkify: true, typographer: true });
                if (window.hljs && typeof window.markdownitHighlight === 'function') {
                    renderer.use(window.markdownitHighlight, { hljs: window.hljs });
                }
                return renderer;
            })();

            const isContentWriterPlaceholder = (content) => {
                const trimmed = String(content || '').trim().toLowerCase();
                if (!trimmed) {
                    return false;
                }
                return trimmed.startsWith('generate ') || trimmed.startsWith('content writer request');
            };

            const extractPromptFromPayload = (payload) => {
                if (!payload || typeof payload !== 'object') {
                    return null;
                }
                const sent = payload.payload_sent;
                if (!sent || typeof sent !== 'object') {
                    return null;
                }
                const messagesList = Array.isArray(sent.messages)
                    ? sent.messages
                    : Array.isArray(sent.input)
                        ? sent.input
                        : null;
                if (!Array.isArray(messagesList)) {
                    return null;
                }
                const userMessage = messagesList.find((entry) => entry && entry.role === 'user' && typeof entry.content === 'string' && entry.content.trim());
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
                const key = String(content || '').trim().toLowerCase();
                const field = mapping[key];
                if (!field || typeof payload[field] !== 'string') {
                    return null;
                }
                const value = payload[field].trim();
                return value ? value : null;
            };

            const findAnyPromptField = (payload) => {
                if (!payload || typeof payload !== 'object') {
                    return null;
                }
                for (const key in payload) {
                    if (!Object.prototype.hasOwnProperty.call(payload, key)) {
                        continue;
                    }
                    if (!/_prompt$/i.test(key)) {
                        continue;
                    }
                    const value = payload[key];
                    if (typeof value === 'string' && value.trim()) {
                        return value.trim();
                    }
                }
                return null;
            };

            if (moduleKey === 'content_writer' && Array.isArray(messages) && messages.length) {
                for (let index = 0; index < messages.length; index += 1) {
                    const message = messages[index];
                    if (!message || message.role !== 'user' || typeof message.content !== 'string') {
                        continue;
                    }
                    const rawContent = message.content.trim();
                    if (!rawContent || !isContentWriterPlaceholder(rawContent)) {
                        continue;
                    }
                    let replacement = null;
                    const nextPayload = messages[index + 1]?.request_payload;
                    if (nextPayload) {
                        replacement = extractPromptFromPayload(nextPayload);
                    }
                    if (!replacement && message.request_payload) {
                        replacement = extractPromptFromPayload(message.request_payload);
                    }
                    if (!replacement && message.request_payload) {
                        replacement = findPromptField(message.request_payload, rawContent);
                    }
                    if (!replacement && message.request_payload) {
                        replacement = findAnyPromptField(message.request_payload);
                    }
                    if (replacement) {
                        message.original_content = message.content;
                        message.content = replacement;
                    }
                }
            }
            const renderPayloadField = (label, value) => `
                <div class="aipkit_stats_payload_field">
                    <span>${escapeHtml(label)}</span>
                    <strong>${escapeHtml(value)}</strong>
                </div>
            `;

            const renderPayloadSection = (label, content, modifier = '') => `
                <div class="aipkit_stats_payload_section">
                    <div class="aipkit_stats_payload_section_label">${escapeHtml(label)}</div>
                    <pre class="aipkit_stats_payload_content${modifier}">${escapeHtml(content)}</pre>
                </div>
            `;

            const messageItems = messages.map((message, index) => {
                const role = resolveMessageRole(message.role);
                const timestamp = formatMessageTime(message.timestamp);
                const content = normalizeMessageContent(message.content);
                const rawMessageId = String(message.message_id || `msg_${index}`);
                const safeMessageId = rawMessageId.replace(/[^a-zA-Z0-9_-]/g, '') || `msg_${index}`;
                let detailControlHtml = '';
                let expandableHtml = '';

                if (role === 'user') {
                    const nextBotMessage = findNextBotMessage(messages, index);
                    const requestPayload = nextBotMessage?.request_payload || null;
                    if (requestPayload) {
                        const payloadId = `aipkit_stats_payload_${safeMessageId}`;
                        const rawPayloadId = `${payloadId}_raw`;
                        const payloadSummary = summarizeRequestPayload(requestPayload, getPricingProviderLabel);
                        const rawPayload = JSON.stringify(requestPayload, null, 2);
                        detailControlHtml = `
                            <button
                                type="button"
                                class="aipkit_stats_disclosure aipkit_stats_disclosure--payload"
                                data-aipkit-stats-toggle="${payloadId}"
                                aria-controls="${payloadId}"
                                aria-expanded="false"
                                aria-label="${escapeHtml(__('View request payload', 'gpt3-ai-content-generator'))}"
                            >
                                <span class="dashicons dashicons-arrow-down-alt2 aipkit_stats_disclosure_chevron" aria-hidden="true"></span>
                            </button>
                        `;
                        expandableHtml += `
                            <div class="aipkit_stats_expandable aipkit_stats_expandable--payload" id="${payloadId}" hidden>
                                <div class="aipkit_stats_expandable_body aipkit_stats_payload_card">
                                    <div class="aipkit_stats_payload_header">
                                        <div class="aipkit_stats_payload_title">${escapeHtml(__('Request payload', 'gpt3-ai-content-generator'))}</div>
                                        <button
                                            type="button"
                                            class="aipkit_stats_payload_copy"
                                            data-aipkit-copy-payload="${rawPayloadId}"
                                            aria-label="${escapeHtml(__('Copy request payload', 'gpt3-ai-content-generator'))}"
                                            title="${escapeHtml(__('Copy request payload', 'gpt3-ai-content-generator'))}"
                                        >
                                            <span class="aipkit_stats_payload_copy_icon" aria-hidden="true">
                                                <svg viewBox="0 0 20 20" focusable="false">
                                                    <rect x="7" y="7" width="10" height="10" rx="2"></rect>
                                                    <path d="M13 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"></path>
                                                </svg>
                                            </span>
                                            <span class="dashicons dashicons-yes-alt aipkit_stats_payload_copy_success" aria-hidden="true"></span>
                                        </button>
                                        <textarea id="${rawPayloadId}" class="aipkit_stats_payload_raw" hidden readonly>${escapeHtml(rawPayload)}</textarea>
                                    </div>
                                    <div class="aipkit_stats_payload_grid">
                                        ${renderPayloadField(__('Provider', 'gpt3-ai-content-generator'), payloadSummary.provider)}
                                        ${renderPayloadField(__('Model', 'gpt3-ai-content-generator'), payloadSummary.model)}
                                        ${renderPayloadField(__('Max output tokens', 'gpt3-ai-content-generator'), payloadSummary.maxOutputTokens)}
                                        ${renderPayloadField(__('Reasoning effort', 'gpt3-ai-content-generator'), payloadSummary.reasoning)}
                                    </div>
                                    ${payloadSummary.systemPrompt ? renderPayloadSection(__('System prompt', 'gpt3-ai-content-generator'), payloadSummary.systemPrompt, ' aipkit_stats_payload_content--system') : ''}
                                    ${payloadSummary.userMessage ? renderPayloadSection(__('User message', 'gpt3-ai-content-generator'), payloadSummary.userMessage) : ''}
                                </div>
                            </div>
                        `;
                    }
                }

                if (role === 'bot') {
                    const scoreItems = Array.isArray(message.vector_search_scores) ? message.vector_search_scores : [];
                    if (scoreItems.length > 0) {
                        const sortedScoreItems = [...scoreItems].sort((left, right) => (
                            Number(right?.score ?? 0) - Number(left?.score ?? 0)
                        ));
                        const highest = sortedScoreItems[0];
                        const scorePercent = highest?.score !== undefined ? Math.round(Number(highest.score) * 100) : 0;
                        const scoreCount = sortedScoreItems.length;
                        const disclosureScore = scoreCount > 1
                            ? `${scoreCount} ${__('sources', 'gpt3-ai-content-generator')} · ${__('top', 'gpt3-ai-content-generator')} ${scorePercent}%`
                            : `${scorePercent}% ${__('match', 'gpt3-ai-content-generator')}`;
                        const sourceSummary = getScoreSourceSummary(highest);
                        const scoreId = `aipkit_stats_scores_${safeMessageId}`;
                        detailControlHtml = `
                            <button
                                type="button"
                                class="aipkit_stats_disclosure aipkit_stats_disclosure--vector"
                                data-aipkit-stats-toggle="${scoreId}"
                                aria-controls="${scoreId}"
                                aria-expanded="false"
                                aria-label="${escapeHtml(__('View vector match details', 'gpt3-ai-content-generator'))}"
                            >
                                <span class="aipkit_stats_disclosure_icon" aria-hidden="true"><span class="dashicons dashicons-database"></span></span>
                                <span class="aipkit_stats_disclosure_score">${escapeHtml(disclosureScore)}</span>
                                <span class="dashicons dashicons-arrow-down-alt2 aipkit_stats_disclosure_chevron" aria-hidden="true"></span>
                            </button>
                        `;

                        const scoreDetails = sortedScoreItems.map((scoreItem, scoreIndex) => {
                            const fileName = getScoreFileName(scoreItem);
                            const chunkLabel = buildScoreChunkLabel(scoreItem);
                            const itemScorePercent = scoreItem?.score !== undefined ? Math.round(Number(scoreItem.score) * 100) : null;
                            const itemScoreClass = itemScorePercent !== null ? buildScoreToneClass(itemScorePercent) : 'is-weak';
                            const preview = scoreItem?.content_preview || '';
                            const resultMeta = [fileName, chunkLabel].filter(Boolean).join(' · ');
                            return `
                                <div class="aipkit_stats_score_item">
                                    <div class="aipkit_stats_score_item_header">
                                        <div class="aipkit_stats_score_result_identity">
                                            <div class="aipkit_stats_score_result_label">${escapeHtml(`${__('Result', 'gpt3-ai-content-generator')} ${scoreIndex + 1}`)}</div>
                                            ${resultMeta ? `<div class="aipkit_stats_score_result_meta">${escapeHtml(resultMeta)}</div>` : ''}
                                        </div>
                                        <div class="aipkit_stats_score_value ${itemScoreClass}">
                                            ${itemScorePercent !== null ? escapeHtml(`${itemScorePercent}%`) : escapeHtml('—')}
                                        </div>
                                    </div>
                                    <div class="aipkit_stats_score_preview">${escapeHtml(preview)}</div>
                                </div>
                            `;
                        }).join('');

                        expandableHtml += `
                            <div class="aipkit_stats_expandable aipkit_stats_expandable--scores" id="${scoreId}" hidden>
                                <div class="aipkit_stats_expandable_body aipkit_stats_scores_panel">
                                    <div class="aipkit_stats_scores_header">
                                        <div class="aipkit_stats_scores_title">${escapeHtml(__('Vector search results', 'gpt3-ai-content-generator'))}</div>
                                        <div class="aipkit_stats_scores_source">${escapeHtml(sourceSummary)}</div>
                                    </div>
                                    <div class="aipkit_stats_scores_list">
                                        ${scoreDetails}
                                    </div>
                                </div>
                            </div>
                        `;
                    }
                }

                const isMarkdownBody = Boolean(markdownRenderer && typeof content === 'string');
                const renderedBody = isMarkdownBody
                    ? markdownRenderer.render(content)
                    : escapeHtml(content);
                const timestampHtml = timestamp
                    ? `<span class="aipkit_stats_message_timestamp">${escapeHtml(timestamp)}</span>`
                    : '';
                const liveUsage = message.usage || {};
                const liveUsageHtml = Number.isFinite(liveUsage.live_seconds)
                    ? `<div class="aipkit_stats_message_caption">${escapeHtml(
                        `${__('Reported voice duration', 'gpt3-ai-content-generator')}: ${formatNumber(liveUsage.live_seconds)} ${__('seconds', 'gpt3-ai-content-generator')} · `
                        + `${__('Billable duration', 'gpt3-ai-content-generator')}: ${formatNumber(liveUsage.billable_seconds ?? Math.max(15, Math.ceil(liveUsage.live_seconds)))} ${__('seconds', 'gpt3-ai-content-generator')} · `
                        + (liveUsage.live_usage_complete ? __('Final usage received', 'gpt3-ai-content-generator') : __('Partial usage — final report missing', 'gpt3-ai-content-generator'))
                    )}</div>`
                    : (liveUsage.live_session_id && Number.isFinite(liveUsage.total_tokens)
                        ? `<div class="aipkit_stats_message_caption">${escapeHtml(`${message.model || ''} · ${formatNumber(liveUsage.input_tokens || 0)} ${__('input tokens', 'gpt3-ai-content-generator')} · ${formatNumber(liveUsage.output_tokens || 0)} ${__('output tokens', 'gpt3-ai-content-generator')}`)}</div>` : '');
                const captionHtml = timestamp || detailControlHtml
                    ? `
                        <div class="aipkit_stats_message_caption">
                            ${timestampHtml}
                            ${timestamp && detailControlHtml && role !== 'user' ? '<span class="aipkit_stats_message_caption_separator" aria-hidden="true">·</span>' : ''}
                            ${detailControlHtml}
                        </div>
                    `
                    : '';
                return `
                    <div class="aipkit_stats_message aipkit_stats_message--${escapeHtml(role)}" style="animation-delay: ${index * 0.05}s">
                        <div class="aipkit_stats_message_main">
                            <div class="aipkit_stats_message_surface">
                                <div class="aipkit_stats_message_body${isMarkdownBody ? ' aipkit_stats_message_body--markdown' : ''}">${renderedBody}</div>
                                ${liveUsageHtml}
                                ${captionHtml}
                            </div>
                        </div>
                        ${expandableHtml}
                    </div>
                `;
            }).join('');

            const ipBlockState = detail.ip_block && typeof detail.ip_block === 'object' ? detail.ip_block : {};
            const ipBlockActionHtml = ipBlockState.can_block
                ? `
                    <button
                        type="button"
                        class="aipkit_stats_detail_ip_action${ipBlockState.is_blocked ? ' is-unblock' : ' is-block'}"
                        data-log-id="${escapeHtml(detail.id)}"
                        data-ip-block-action="${ipBlockState.is_blocked ? 'unblock' : 'block'}"
                    >
                        <span class="dashicons ${ipBlockState.is_blocked ? 'dashicons-unlock' : 'dashicons-lock'}" aria-hidden="true"></span>
                        ${escapeHtml(ipBlockState.is_blocked ? __('Unblock IP', 'gpt3-ai-content-generator') : __('Block IP', 'gpt3-ai-content-generator'))}
                    </button>
                `
                : '';

            elements.detailPanel.innerHTML = `
                <div class="aipkit_stats_detail_content">
                    <div class="aipkit_stats_detail_messages">
                        ${messageItems || `<div class="aipkit_stats_table_placeholder">${escapeHtml('No messages found.')}</div>`}
                    </div>
                    <div class="aipkit_stats_detail_footer">
                        ${ipBlockActionHtml}
                        <button type="button" class="aipkit_btn aipkit_btn-danger aipkit_stats_detail_delete" data-log-id="${escapeHtml(detail.id)}">
                            <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                            ${escapeHtml('Delete')}
                        </button>
                    </div>
                </div>
            `;
        };

        const fetchLogs = async () => {
            renderTablePlaceholder(elements.tableBody, 4, 'Loading logs...');
            try {
                const response = await window.aipkit_apiRequest('aipkit_stats_get_logs', {
                    days: state.days,
                    page: state.page,
                    per_page: state.perPage,
                    bot_id: state.botId,
                    module: state.module,
                    search: state.search,
                });
                const logs = Array.isArray(response.logs) ? response.logs : [];
                if (!state.selectedLogId && !hasAutoSelected && logs.length) {
                    state.selectedLogId = Number(logs[0].id);
                    hasAutoSelected = true;
                }
                renderLogs(logs);
                renderPagination(response.pagination || {});
                if (state.selectedLogId && hasAutoSelected) {
                    fetchDetail(state.selectedLogId);
                }
                state.logsLoaded = true;
            } catch (error) {
                state.logsLoaded = false;
                state.visibleLogs = [];
                state.selectedLogIds.clear();
                syncLogSelectionUi();
                renderTablePlaceholder(elements.tableBody, 4, error.message || 'Failed to load logs.');
            }
        };

        const fetchDetail = async (logId) => {
            if (!logId) {
                renderDetail(null);
                return;
            }
            if (elements.detailIdentity) {
                elements.detailIdentity.hidden = true;
                elements.detailIdentity.innerHTML = '';
            }
            elements.detailPanel.innerHTML = `<div class="aipkit_stats_detail_content"><div class="aipkit_stats_table_placeholder">${escapeHtml('Loading conversation...')}</div></div>`;
            try {
                const response = await window.aipkit_apiRequest('aipkit_stats_get_log_detail', {
                    log_id: logId,
                });
                renderDetail(response);
            } catch (error) {
                elements.detailPanel.innerHTML = `<div class="aipkit_stats_detail_content"><div class="aipkit_stats_table_placeholder">${escapeHtml(error.message || 'Failed to load conversation.')}</div></div>`;
            }
        };

        const scheduleSearch = (value) => {
            clearSelectedLogs();
            state.search = value.trim();
            state.page = 1;
            syncLogFiltersResetState();
            if (searchTimer) {
                clearTimeout(searchTimer);
            }
            searchTimer = window.setTimeout(() => {
                fetchLogs();
            }, 300);
        };

        const refreshCronStatus = async () => {
            if (!elements.cronStatus) {
                return;
            }
            try {
                const response = await window.aipkit_apiRequest('aipkit_stats_get_log_cron_status', {});
                updateCronStatus(response);
            } catch (error) {
                // Keep last known status when refresh fails.
            }
        };

        const saveSettings = async () => {
            if (!elements.settingsToggle || !elements.retentionSelect) {
                return;
            }
            const enable = elements.settingsToggle.checked ? '1' : '0';
            const retention = parseInt(elements.retentionSelect.value, 10) || 90;
            const customerDashboardUrl = elements.customerDashboardUrl
                ? String(elements.customerDashboardUrl.value || '').trim()
                : '';
            const customerBuyCreditsUrl = elements.customerBuyCreditsUrl
                ? String(elements.customerBuyCreditsUrl.value || '').trim()
                : '';
            showStatus('Saving settings...', 'loading');
            try {
                const response = await window.aipkit_apiRequest('aipkit_stats_save_settings', {
                    enable_pruning: enable,
                    retention_period_days: retention,
                    customer_dashboard_page_url: customerDashboardUrl,
                    customer_buycredits_url: customerBuyCreditsUrl,
                });
                if (elements.customerDashboardUrl) {
                    elements.customerDashboardUrl.dataset.defaultValue = customerDashboardUrl;
                }
                if (elements.customerBuyCreditsUrl) {
                    elements.customerBuyCreditsUrl.dataset.defaultValue = customerBuyCreditsUrl;
                }
                updateSettingsVisibility(elements.settingsToggle.checked);
                updateShortcodeSnippet();
                showStatus(response.message || 'Settings saved.', 'success');
                refreshCronStatus();
                window.setTimeout(() => showStatus(''), 2000);
            } catch (error) {
                showStatus(error.message || 'Failed to save settings.', 'error');
            }
        };

        const queueSettingsSave = () => {
            if (settingsSaveTimer) {
                window.clearTimeout(settingsSaveTimer);
            }
            settingsSaveTimer = window.setTimeout(() => {
                settingsSaveTimer = null;
                saveSettings();
            }, 450);
        };

        if (elements.daysFilters && elements.daysFilters.length) {
            elements.daysFilters.forEach((select) => {
                select.addEventListener('change', () => {
                    clearSelectedLogs();
                    state.days = parseInt(select.value, 10) || DEFAULT_DAYS;
                    syncDaysFilterControls(state.days);
                    setStoredValue(DAYS_STORAGE_KEY, state.days);
                    state.page = 1;
                    state.pricingManagementLoaded = false;
                    syncLogFiltersResetState();
                    if (state.activeTab === 'activity') {
                        fetchPricingManagement();
                    } else if (state.activeTab === 'logs') {
                        fetchLogs();
                    }
                });
            });
        }

        elements.requestsDays?.addEventListener('change', () => {
            state.requestsDays = Number(REQUESTS_DAY_VALUES.includes(elements.requestsDays.value)
                ? elements.requestsDays.value : DEFAULT_DAYS);
            setStoredValue(REQUESTS_DAYS_STORAGE_KEY, state.requestsDays);
            state.requestsLoaded = false;
            fetchRequests(1);
        });

        if (elements.sectionButtons && elements.sectionButtons.length) {
            elements.sectionButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    const section = button.dataset.aipkitStatsSection;
                    activateTab(section === 'billing' ? 'pricing' : section || 'logs');
                });

                button.addEventListener('keydown', (event) => {
                    const buttons = Array.from(elements.sectionButtons).filter((item) => !item.hidden);
                    const currentIndex = buttons.indexOf(button);
                    const nextIndex = event.key === 'ArrowRight' ? (currentIndex + 1) % buttons.length
                        : event.key === 'ArrowLeft' ? (currentIndex - 1 + buttons.length) % buttons.length
                            : event.key === 'End' ? buttons.length - 1
                                : event.key === 'Home' ? 0 : -1;
                    if (currentIndex === -1 || nextIndex === -1) {
                        return;
                    }
                    event.preventDefault();
                    buttons[nextIndex].focus();
                    const section = buttons[nextIndex].dataset.aipkitStatsSection;
                    activateTab(section === 'billing' ? 'pricing' : section || 'logs');
                });
            });
        }

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
                if (event.target === elements.retentionModal) {
                    closeRetentionModal();
                }
            });
        }

        if (elements.botFilter) {
            elements.botFilter.addEventListener('change', () => {
                clearSelectedLogs();
                state.botId = elements.botFilter.value;
                setStoredValue(BOT_FILTER_STORAGE_KEY, state.botId);
                state.page = 1;
                syncLogFiltersResetState();
                fetchLogs();
            });
        }

        if (elements.moduleFilter) {
            elements.moduleFilter.addEventListener('change', () => {
                clearSelectedLogs();
                state.module = elements.moduleFilter.value;
                setStoredValue(MODULE_FILTER_STORAGE_KEY, state.module);
                state.page = 1;
                syncLogFiltersResetState();
                fetchLogs();
            });
        }

        if (elements.searchInput) {
            elements.searchInput.addEventListener('input', (event) => {
                scheduleSearch(event.target.value || '');
            });
        }

        if (elements.selectAllLogs) {
            elements.selectAllLogs.addEventListener('change', () => {
                const visibleIds = state.visibleLogs
                    .map((log) => Number(log.id))
                    .filter(Boolean);
                visibleIds.forEach((logId) => {
                    if (elements.selectAllLogs.checked) {
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
                if (event.target.closest('.aipkit_stats_log_checkbox')) {
                    return;
                }
                const row = event.target.closest('tr[data-log-id]');
                if (!row) {
                    return;
                }
                const logId = row.getAttribute('data-log-id');
                if (!logId) {
                    return;
                }
                state.selectedLogId = Number(logId);
                elements.tableBody.querySelectorAll('tr').forEach((tableRow) => {
                    tableRow.classList.toggle('aipkit_stats_row_active', tableRow === row);
                });
                fetchDetail(logId);
            });
        }

        if (elements.detailPanel) {
            elements.detailPanel.addEventListener('click', (event) => {
                const copyPayloadBtn = event.target.closest('[data-aipkit-copy-payload]');
                if (copyPayloadBtn) {
                    const rawPayloadElement = document.getElementById(copyPayloadBtn.getAttribute('data-aipkit-copy-payload'));
                    const rawPayload = rawPayloadElement?.value || '';
                    if (!rawPayload) {
                        return;
                    }
                    copyTextToClipboard(rawPayload).then(() => {
                        copyPayloadBtn.setAttribute('aria-label', __('Copied', 'gpt3-ai-content-generator'));
                        copyPayloadBtn.setAttribute('title', __('Copied', 'gpt3-ai-content-generator'));
                        copyPayloadBtn.classList.add('is-copied');
                        window.setTimeout(() => {
                            copyPayloadBtn.setAttribute('aria-label', __('Copy request payload', 'gpt3-ai-content-generator'));
                            copyPayloadBtn.setAttribute('title', __('Copy request payload', 'gpt3-ai-content-generator'));
                            copyPayloadBtn.classList.remove('is-copied');
                        }, 1400);
                    }).catch(() => {
                        showStatus(__('The request payload could not be copied.', 'gpt3-ai-content-generator'), 'error');
                    });
                    return;
                }
                const ipBlockBtn = event.target.closest('.aipkit_stats_detail_ip_action');
                if (ipBlockBtn) {
                    setLogIpBlocked(ipBlockBtn);
                    return;
                }
                const deleteBtn = event.target.closest('.aipkit_stats_detail_delete');
                if (!deleteBtn) {
                    const toggleBtn = event.target.closest('[data-aipkit-stats-toggle]');
                    if (!toggleBtn) {
                        return;
                    }
                    const targetId = toggleBtn.getAttribute('data-aipkit-stats-toggle');
                    if (!targetId) {
                        return;
                    }
                    const targetElement = document.getElementById(targetId);
                    if (!targetElement) {
                        return;
                    }
                    const isExpanded = toggleBtn.getAttribute('aria-expanded') === 'true';
                    targetElement.hidden = isExpanded;
                    toggleBtn.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
                    toggleBtn.classList.toggle('is-expanded', !isExpanded);
                    if (isExpanded) {
                        const messagesContainer = toggleBtn.closest('.aipkit_stats_detail_messages');
                        if (messagesContainer) {
                            messagesContainer.style.overflowY = 'hidden';
                            window.requestAnimationFrame(() => {
                                messagesContainer.style.removeProperty('overflow-y');
                            });
                        }
                    }
                    return;
                }
                deleteSingleLog(deleteBtn.getAttribute('data-log-id'));
            });
        }

        if (elements.tableMenuTrigger && elements.tableMenu) {
            elements.tableMenuTrigger.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                toggleTableMenu(elements.tableMenu.hidden);
            });
        }

        if (elements.filtersToggleBtn && elements.logsToolbar) {
            elements.filtersToggleBtn.addEventListener('click', () => {
                setLogFiltersExpanded(elements.logsToolbar.hidden);
            });
        }

        if (elements.filtersResetBtn) {
            elements.filtersResetBtn.addEventListener('click', () => {
                resetLogFilters();
            });
        }

        document.addEventListener('click', (event) => {
            if (!elements.tableMenu || elements.tableMenu.hidden) {
                return;
            }
            if (event.target.closest('#aipkit_stats_table_menu') || event.target.closest('#aipkit_stats_table_menu_trigger')) {
                return;
            }
            toggleTableMenu(false);
        });

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape' || !elements.tableMenu || elements.tableMenu.hidden) {
                return;
            }
            toggleTableMenu(false, true);
        });

        window.addEventListener('resize', positionTableMenu);
        window.addEventListener('scroll', positionTableMenu, { capture: true, passive: true });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && elements.pricingModal?.classList.contains('aipkit-active')) {
                closePricingModal(true);
            }
        });

        document.addEventListener('keydown', (event) => {
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

        if (elements.settingsToggle) {
            elements.settingsToggle.addEventListener('change', () => {
                if (!isPro) {
                    elements.settingsToggle.checked = false;
                    showStatus('Upgrade to Pro to enable auto-delete.', 'error');
                    return;
                }
                updateSettingsVisibility(elements.settingsToggle.checked);
                saveSettings();
            });
        }

        if (elements.retentionSelect) {
            elements.retentionSelect.addEventListener('change', () => {
                if (!isPro) {
                    return;
                }
                saveSettings();
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
                        saveSettings();
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
        activateTab(readActiveTab());
        updateSettingsVisibility(!!elements.settingsToggle?.checked);
    }

    window.aipkit_initStats = aipkit_initStats;
})();
