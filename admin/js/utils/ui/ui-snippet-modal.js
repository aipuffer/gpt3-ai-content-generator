/**
 * AIPKit UI Utilities - Snippet Modal
 * Reusable modal for displaying indexed snippet content across providers.
 */
(function () {
  "use strict";
  const i18n = window.wp && window.wp.i18n ? window.wp.i18n : null;
  const __ = i18n && typeof i18n.__ === 'function' ? i18n.__ : (text) => text;
  const _n = i18n && typeof i18n._n === 'function'
    ? i18n._n
    : (single, plural, number) => (number === 1 ? single : plural);
  const sprintf = i18n && typeof i18n.sprintf === 'function'
    ? i18n.sprintf
    : (template, ...args) => {
        let index = 0;
        return String(template).replace(/%s/g, () => String(args[index++] ?? ''));
      };

  function escaper(value) {
    return typeof window.aipkit_escapeHtml === 'function'
      ? window.aipkit_escapeHtml(value)
      : String(value ?? '');
  }

  function normalizeProviderKey(value) {
    return String(value || '').trim().toLowerCase();
  }

  function getSourceVectorIdLabel(providerValue) {
    const normalized = normalizeProviderKey(providerValue);
    if (normalized === 'openai') {
      return __('File ID', 'gpt3-ai-content-generator');
    }
    if (normalized === 'qdrant') {
      return __('Point ID', 'gpt3-ai-content-generator');
    }
    if (normalized === 'pinecone') {
      return __('Vector ID', 'gpt3-ai-content-generator');
    }
    if (normalized === 'chroma') {
      return __('Record ID', 'gpt3-ai-content-generator');
    }
    return __('Vector ID', 'gpt3-ai-content-generator');
  }

  function formatContentLength(content) {
    const length = String(content || '').trim().length;
    if (!length) {
      return '';
    }
    return sprintf(
      /* translators: %s: Number of characters in the saved preview. */
      _n('%s character', '%s characters', length, 'gpt3-ai-content-generator'),
      length.toLocaleString()
    );
  }

  function decodeStoredText(value) {
    const textarea = document.createElement('textarea');
    textarea.innerHTML = String(value || '');
    return textarea.value
      .replace(/<br\s*\/?>/gi, '\n')
      .replace(/<\/p\s*>/gi, '\n')
      .replace(/\r\n?/g, '\n')
      .replace(/^\s{0,3}#{1,6}\s+/gm, '')
      .trim();
  }

  function splitTerms(value) {
    return String(value || '')
      .split(/[,;|]/)
      .map((item) => item.trim())
      .filter(Boolean);
  }

  function normalizeSourceUrl(value) {
    const candidate = String(value || '').trim();
    if (!candidate) {
      return '';
    }
    try {
      const parsed = new URL(candidate, window.location.origin);
      return parsed.protocol === 'http:' || parsed.protocol === 'https:'
        ? parsed.href
        : '';
    } catch (error) {
      return '';
    }
  }

  function parseLabeledLines(value) {
    return String(value || '')
      .split('\n')
      .map((line) => {
        const match = line.match(/^([^:\n]{1,80}):\s*(.*)$/);
        return match ? {label: match[1].trim(), value: match[2].trim()} : null;
      })
      .filter(Boolean);
  }

  function normalizeLabel(value) {
    return String(value || '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
  }

  function parseContentSections(bodyText) {
    const body = String(bodyText || '').trim();
    if (!body) {
      return [];
    }

    const sections = [];
    const pattern = /(?:^|\n{2,})([^:\n]{1,60}):\s*([\s\S]*?)(?=\n{2,}[^:\n]{1,60}:\s*|$)/g;
    let match;
    while ((match = pattern.exec(body)) !== null) {
      const label = match[1].trim();
      const content = match[2].trim();
      if (content) {
        sections.push({label, content});
      }
    }

    return sections.length ? sections : [{label: __('Content', 'gpt3-ai-content-generator'), content: body}];
  }

  function getSourceKind(log, content) {
    const message = String(log?.message || '').toLowerCase();
    const fileId = String(log?.file_id || '').toLowerCase();
    const title = String(log?.post_title || '');
    if (log?.post_id || message.includes('wordpress post content') || fileId.startsWith('wp_post_')) {
      return 'website';
    }
    if (/^Q\s*:\s*\S[\s\S]*\bA\s*:\s*\S/i.test(content)) {
      return 'qa';
    }
    if (
      message.includes('file content') ||
      message.includes('original filename:') ||
      message.includes('file uploaded') ||
      /\.(pdf|docx?|txt|md|csv|json|html?|xlsx?)\b/i.test(title)
    ) {
      return 'file';
    }
    return 'text';
  }

  function getFilename(log) {
    const title = String(log?.post_title || '').trim();
    if (/\.[a-z0-9]{2,8}$/i.test(title)) {
      return title;
    }
    const message = String(log?.message || '');
    const match = message.match(/original filename:\s*([^\n|]+)/i);
    return match ? match[1].trim() : '';
  }

  function parseSourcePreview(log, snippetText) {
    const sourceLog = log && typeof log === 'object' ? log : {};
    const decoded = decodeStoredText(snippetText || sourceLog.indexed_content || '');
    const separator = decoded.match(/\n\s*---\s*\n/);
    const metadataText = separator ? decoded.slice(0, separator.index).trim() : '';
    const bodyText = separator
      ? decoded.slice((separator.index || 0) + separator[0].length).trim()
      : decoded;
    const fields = parseLabeledLines(metadataText);
    const findField = (labels) => {
      const wanted = labels.map(normalizeLabel);
      const field = fields.find((item) => wanted.includes(normalizeLabel(item.label)));
      return field ? field.value : '';
    };
    const kind = getSourceKind(sourceLog, decoded);
    const qaMatch = kind === 'qa'
      ? decoded.match(/^Q\s*:\s*([\s\S]*?)\nA\s*:\s*([\s\S]*)$/i)
      : null;
    const title = String(sourceLog.post_title || '').trim()
      || findField(['Title'])
      || (qaMatch ? qaMatch[1].trim() : '')
      || getFilename(sourceLog)
      || (kind === 'website'
        ? __('Website source', 'gpt3-ai-content-generator')
        : kind === 'file'
          ? __('File source', 'gpt3-ai-content-generator')
          : kind === 'qa'
            ? __('Q&A source', 'gpt3-ai-content-generator')
            : __('Text source', 'gpt3-ai-content-generator'));
    const sourceUrl = normalizeSourceUrl(findField(['Source URL', 'URL']));
    const categories = [];
    const tags = [];
    const additional = [];

    fields.forEach((field) => {
      const label = normalizeLabel(field.label);
      if (['source url', 'url', 'title'].includes(label)) {
        return;
      }
      if (label === 'category' || label === 'categories') {
        categories.push(...splitTerms(field.value));
        return;
      }
      if (label === 'tag' || label === 'tags' || label === 'categories tags') {
        tags.push(...splitTerms(field.value));
        return;
      }
      additional.push(field);
    });

    let sections;
    if (qaMatch) {
      sections = [
        {label: __('Question', 'gpt3-ai-content-generator'), content: qaMatch[1].trim()},
        {label: __('Answer', 'gpt3-ai-content-generator'), content: qaMatch[2].trim()},
      ];
    } else {
      sections = parseContentSections(bodyText);
    }

    return {
      title,
      sourceUrl,
      categories: [...new Set(categories)],
      tags: [...new Set(tags)],
      additional,
      sections,
      vectorId: String(sourceLog.file_id || '').trim(),
      vectorLabel: getSourceVectorIdLabel(sourceLog.provider),
      length: formatContentLength(decoded),
      limited: Array.from(String(snippetText || sourceLog.indexed_content || '')).length === 1000
        && String(snippetText || sourceLog.indexed_content || '').endsWith('...'),
    };
  }

  function parseSourceEditorContent(snippetText) {
    const text = decodeStoredText(snippetText);
    const qaMatch = text.match(/^Q\s*:\s*([\s\S]*?)\nA\s*:\s*([\s\S]*)$/i);

    if (qaMatch) {
      return {
        type: 'qa',
        text,
        question: qaMatch[1].trim(),
        answer: qaMatch[2].trim(),
      };
    }

    return {
      type: 'text',
      text,
      question: '',
      answer: '',
    };
  }

  function renderChips(items) {
    const values = Array.isArray(items) ? items.filter(Boolean) : [];
    if (!values.length) {
      return '';
    }
    const visible = values.slice(0, 4);
    const remaining = values.length - visible.length;
    return `${visible.map((item) => `<span class="aipkit_sources_preview_chip">${escaper(item)}</span>`).join('')}${
      remaining > 0
        ? `<span class="aipkit_sources_preview_chip aipkit_sources_preview_chip--more">+${remaining.toLocaleString()} ${escaper(__('more', 'gpt3-ai-content-generator'))}</span>`
        : ''
    }`;
  }

  function renderSourcePreview(log, snippetText) {
    const preview = parseSourcePreview(log, snippetText);
    const taxonomyRows = [
      preview.categories.length
        ? `<div class="aipkit_sources_preview_term_row"><span class="aipkit_sources_preview_term_label">${escaper(__('Categories', 'gpt3-ai-content-generator'))}</span><div class="aipkit_sources_preview_chips">${renderChips(preview.categories)}</div></div>`
        : '',
      preview.tags.length
        ? `<div class="aipkit_sources_preview_term_row"><span class="aipkit_sources_preview_term_label">${escaper(__('Tags', 'gpt3-ai-content-generator'))}</span><div class="aipkit_sources_preview_chips">${renderChips(preview.tags)}</div></div>`
        : '',
    ].join('');
    const additional = preview.additional.length
      ? `<dl class="aipkit_sources_preview_attributes">${preview.additional.slice(0, 6).map((item) => `<div><dt>${escaper(item.label)}</dt><dd>${escaper(item.value || '—')}</dd></div>`).join('')}</dl>`
      : '';
    const contentSections = preview.sections.length
      ? preview.sections.map((section) => `<section class="aipkit_sources_preview_section"><h4>${escaper(section.label)}</h4><div class="aipkit_sources_preview_text">${escaper(section.content)}</div></section>`).join('')
      : `<p class="aipkit_sources_preview_empty">${escaper(__('No indexed content is available.', 'gpt3-ai-content-generator'))}</p>`;
    const stats = [
      preview.vectorId ? {label: preview.vectorLabel, value: preview.vectorId, mono: true} : null,
      preview.length ? {label: __('Preview length', 'gpt3-ai-content-generator'), value: preview.length, mono: false} : null,
    ].filter(Boolean);
    const statMarkup = stats.length
      ? `<div class="aipkit_sources_preview_stats">${stats.map((item) => `<div class="aipkit_sources_preview_stat"><span>${escaper(item.label)}</span><strong${item.mono ? ' class="is-mono"' : ''} title="${escaper(item.value)}">${escaper(item.value)}</strong></div>`).join('')}</div>`
      : '';

    return `<article class="aipkit_sources_preview_article">
      <header class="aipkit_sources_preview_identity">
        <h3>${escaper(preview.title)}</h3>
        ${preview.sourceUrl ? `<a class="aipkit_sources_preview_url" href="${escaper(preview.sourceUrl)}" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span><span>${escaper(preview.sourceUrl)}</span></a>` : ''}
      </header>
      ${taxonomyRows ? `<div class="aipkit_sources_preview_terms">${taxonomyRows}</div>` : ''}
      ${additional}
      <div class="aipkit_sources_preview_content_wrap">
        <h3>${escaper(__('Content preview', 'gpt3-ai-content-generator'))}</h3>
        ${preview.limited ? `<p>${escaper(__('Preview limited to 1,000 characters. The indexed source may contain more content.', 'gpt3-ai-content-generator'))}</p>` : ''}
        <div class="aipkit_sources_preview_content" tabindex="0">${contentSections}</div>
      </div>
      ${statMarkup}
    </article>`;
  }

  function aipkit_showSnippetModalEnhanced({snippetText = '', fileId = '', provider = '', storeId = '', storeName = '', log = null} = {}) {
    const modalOverlay = document.createElement('div');
    modalOverlay.className = 'aipkit-modal-overlay aipkit_sources_modal aipkit_sources_view_modal';
    modalOverlay.setAttribute('aria-hidden', 'true');

    const modalContent = document.createElement('div');
    modalContent.className = 'aipkit-modal-content aipkit_sources_modal_panel aipkit_sources_view_modal_content';
    modalContent.setAttribute('role', 'dialog');
    modalContent.setAttribute('aria-modal', 'true');
    modalContent.setAttribute('aria-labelledby', 'aipkit_snippet_modal_title');
    modalContent.setAttribute('aria-describedby', 'aipkit_snippet_modal_description');

    const sourceLog = log && typeof log === 'object'
      ? {
          ...log,
          provider: log.provider || provider,
          file_id: log.file_id || fileId,
          vector_store_id: log.vector_store_id || storeId,
          vector_store_name: log.vector_store_name || storeName,
        }
      : {
          provider,
          file_id: fileId,
          vector_store_id: storeId,
          vector_store_name: storeName,
        };
    const previewMarkup = renderSourcePreview(sourceLog, snippetText);
    const titleText = __('Source preview', 'gpt3-ai-content-generator');
    const subtitleText = __('Review the saved preview. It may not include the full indexed content.', 'gpt3-ai-content-generator');

    modalContent.innerHTML = `
      <div class="aipkit-modal-header aipkit_sources_modal_header">
        <div class="aipkit_sources_modal_heading">
          <h2 class="aipkit-modal-title aipkit_sources_modal_title" id="aipkit_snippet_modal_title">${escaper(titleText)}</h2>
          <p class="aipkit_builder_modal_subtitle aipkit_sources_modal_subtitle" id="aipkit_snippet_modal_description">${escaper(subtitleText)}</p>
        </div>
        <button type="button" class="aipkit-modal-close-btn aipkit_sources_modal_close aipkit_sources_view_close" aria-label="${escaper(__('Close', 'gpt3-ai-content-generator'))}">
          <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
        </button>
      </div>
      <div class="aipkit-modal-body aipkit_sources_modal_body">
        <div class="aipkit_sources_view_preview" aria-label="${escaper(__('Source content preview', 'gpt3-ai-content-generator'))}">${previewMarkup}</div>
      </div>
      <div class="aipkit_sources_modal_footer">
        <div class="aipkit_sources_modal_status" aria-hidden="true"></div>
        <div class="aipkit_builder_action_row aipkit_sources_view_actions">
          <button type="button" class="aipkit_btn aipkit_btn-secondary aipkit_sources_view_close_btn">${escaper(__('Close', 'gpt3-ai-content-generator'))}</button>
        </div>
      </div>
    `;

    modalOverlay.appendChild(modalContent);
    document.body.appendChild(modalOverlay);

    function closeModal() {
      modalOverlay.classList.remove('active', 'aipkit-active');
      modalOverlay.setAttribute('aria-hidden', 'true');
      document.removeEventListener('keydown', handleEscape);
      setTimeout(() => {
        if (modalOverlay.parentNode) {
          modalOverlay.parentNode.removeChild(modalOverlay);
        }
      }, 300);
    }

    modalContent.querySelector('.aipkit_sources_view_close').addEventListener('click', closeModal);
    modalContent.querySelector('.aipkit_sources_view_close_btn').addEventListener('click', closeModal);
    modalOverlay.addEventListener('click', (e) => {
      if (e.target === modalOverlay) {
        closeModal();
      }
    });

    function handleEscape(e) {
      if (e.key === 'Escape') {
        closeModal();
      }
    }
    document.addEventListener('keydown', handleEscape);

    requestAnimationFrame(() => {
      modalOverlay.classList.add('aipkit-active');
      modalOverlay.setAttribute('aria-hidden', 'false');
    });
  }

  function aipkit_showSnippetModal(snippetText, fileId, provider = '') {
    return aipkit_showSnippetModalEnhanced({snippetText, fileId, provider});
  }

  function aipkit_attachSnippetModalListeners(container, provider = '') {
    if (!container) return;

    container.querySelectorAll('.aipkit_view_snippet_icon').forEach(icon => {
      const newIcon = icon.cloneNode(true);
      icon.parentNode.replaceChild(newIcon, icon);

      newIcon.addEventListener('click', (e) => {
        e.preventDefault();
        const snippetText = newIcon.dataset.snippet;
        const fileId = newIcon.dataset.fileId;
        const storeId = newIcon.dataset.storeId || '';
        const storeName = newIcon.dataset.storeName || '';
        let log = null;
        if (newIcon.dataset.sourceLog) {
          try {
            log = JSON.parse(newIcon.dataset.sourceLog);
          } catch (error) {
            log = null;
          }
        }
        aipkit_showSnippetModalEnhanced({snippetText, fileId, provider, storeId, storeName, log});
      });
    });
  }

  window.aipkit_showSnippetModal = aipkit_showSnippetModal;
  window.aipkit_showSnippetModalEnhanced = aipkit_showSnippetModalEnhanced;
  window.aipkit_attachSnippetModalListeners = aipkit_attachSnippetModalListeners;
  window.aipkit_renderSourcePreview = renderSourcePreview;
  window.aipkit_parseSourcePreview = parseSourcePreview;
  window.aipkit_parseSourceEditorContent = parseSourceEditorContent;
})();
