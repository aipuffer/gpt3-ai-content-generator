/* The SDK owns prices and checkout; these controls use its native selections. */
const { __, sprintf } = wp.i18n;

function initPricing() {
  const root = document.getElementById('fs_pricing_wrapper');
  if (!root) return;

  let sequence = 0;
  let frame = 0;

  const featureGroups = [
    { title: __('Chatbots & voice', 'gpt3-ai-content-generator'), description: __('Voice agents, embed anywhere, triggers, PDF export', 'gpt3-ai-content-generator'), path: 'M4 4h16v12H8l-4 4V4Z' },
    { title: __('Content & automation', 'gpt3-ai-content-generator'), description: __('RSS, Sheets & URL content, bulk edits, form workflows', 'gpt3-ai-content-generator'), path: 'M20 7v5h-5M20 12a8 8 0 1 0-2 5M20 7l-3-3' },
    { title: __('Knowledge & models', 'gpt3-ai-content-generator'), description: __('Vector file uploads, indexing controls, Ollama', 'gpt3-ai-content-generator'), path: 'M20 5c0 2-16 2-16 0s16-2 16 0ZM4 5v14c0 2 16 2 16 0V5M4 12c0 2 16 2 16 0' },
    { title: __('Privacy & support', 'gpt3-ai-content-generator'), description: __('Consent tools, auto-delete logs, priority support', 'gpt3-ai-content-generator'), path: 'M5 10h14v11H5V10ZM8 10V6a4 4 0 0 1 8 0v4' },
  ];

  function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text) node.textContent = text;
    return node;
  }

  function icon(path) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '1.7');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    const shape = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    shape.setAttribute('d', path);
    svg.append(shape);
    return svg;
  }

  function enhanceCards() {
    const cards = Array.from(root.querySelectorAll('.fs-package'));
    // The SDK renders inherited features as placeholders on Plus and Max.
    const sources = cards.filter((card) => card.classList.contains('fs-free-plan') || /^(professional|pro|single site|2 sites)$/i.test(card.querySelector('.fs-plan-title')?.textContent.trim() || ''));
    const baseFeatures = [...new Set(sources.flatMap((card) => Array.from(card.querySelectorAll('.fs-plan-features > li .fs-feature-title')).map((item) => item.textContent.trim()).slice(1)))];
    cards.forEach((card) => {
      const content = card.querySelector('.fs-package-content');
      const nativeTitle = card.querySelector('.fs-plan-title')?.textContent.trim() || '';
      const tier = /pro plus/i.test(nativeTitle) ? 'plus' : /pro max/i.test(nativeTitle) ? 'max' : /^(professional|pro|single site|2 sites)$/i.test(nativeTitle) ? 'pro' : null;
      if (!content || !tier || !baseFeatures.length) return;
      const name = tier === 'plus' ? 'Pro Plus' : tier === 'max' ? 'Pro Max' : 'Pro';
      const creditValue = card.querySelector('.fs-plan-features-with-value strong')?.textContent || '';
      const credits = Number(creditValue.replace(/[^0-9]/g, ''));
      const selected = card.querySelector('.fs-license-quantity-selected');
      const quantity = selected?.querySelector('.fs-license-quantity')?.textContent.trim() || '';
      const nativeCycle = card.querySelector('.fs-selected-pricing-cycle')?.textContent.trim() || '';
      const annual = root.querySelector('.fs-selected-billing-cycle')?.dataset.billingCycle === 'annual';
      const selectedPrice = selected?.querySelector('.fs-license-quantity-price')?.textContent.trim();
      const billing = annual && selectedPrice
        ? sprintf(__('Billed %s', 'gpt3-ai-content-generator'), selectedPrice)
        : nativeCycle;
      const signature = JSON.stringify([tier, credits, billing, quantity, baseFeatures]);
      if (card.dataset.aipkitCardSignature !== signature) {
        card.dataset.aipkitPricingTier = tier;
        card.dataset.aipkitCardSignature = signature;
        let header = content.querySelector('.aipkit-pricing-card-header');
        if (!header) {
          header = element('div', 'aipkit-pricing-card-header');
          content.prepend(header);
        }
        const subtitle = tier === 'plus' ? __('Pro, plus AI credits to get started.', 'gpt3-ai-content-generator') : tier === 'max' ? __('Pro, plus AI credits for heavy use.', 'gpt3-ai-content-generator') : __('All Pro features, with your own keys or Cloud.', 'gpt3-ai-content-generator');
        const panel = element('div', 'aipkit-pricing-credit-panel');
        panel.append(icon(credits ? 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0ZM15 8h-4a2 2 0 0 0 0 4h2a2 2 0 0 1 0 4H9M12 6v2m0 8v2' : 'M15 4a5 5 0 1 1-3 9l-7 7H2v-3l7-7a5 5 0 0 1 6-6ZM16 7h.01'));
        const copy = element('div');
        copy.append(element('strong', '', credits
          ? sprintf(__('%s AI credits monthly', 'gpt3-ai-content-generator'), new Intl.NumberFormat(document.documentElement.lang || 'en').format(credits))
          : __('Your own API keys', 'gpt3-ai-content-generator')));
        copy.append(element('span', '', credits ? __('Renews monthly', 'gpt3-ai-content-generator') : __('No monthly plan credits', 'gpt3-ai-content-generator')));
        panel.append(copy);
        header.replaceChildren(element('h2', '', name), element('p', '', subtitle), panel);

        let detail = content.querySelector('.aipkit-pricing-billing-detail');
        if (!detail) {
          detail = element('p', 'aipkit-pricing-billing-detail');
          content.append(detail);
        }
        detail.textContent = quantity ? `${billing} · ${quantity}` : billing;

        let summary = content.querySelector('.aipkit-pricing-feature-summary');
        let full = content.querySelector('.aipkit-pricing-full-features');
        let toggle = content.querySelector('.aipkit-pricing-features-toggle');
        if (!summary) {
          summary = element('section', 'aipkit-pricing-feature-summary');
          summary.append(element('h3', '', __('Everything in Free, plus', 'gpt3-ai-content-generator')));
          for (const group of featureGroups) {
            const row = element('div', 'aipkit-pricing-feature-group');
            const mark = element('span', 'aipkit-pricing-feature-icon');
            mark.append(icon(group.path));
            const text = element('div');
            text.append(element('strong', '', group.title), element('p', '', group.description));
            row.append(mark, text);
            summary.append(row);
          }
          full = element('ul', 'aipkit-pricing-full-features');
          full.id = `aipkit-pricing-features-${++sequence}`;
          full.hidden = true;
          toggle = element('button', 'aipkit-pricing-features-toggle');
          toggle.type = 'button';
          toggle.setAttribute('aria-controls', full.id);
          toggle.setAttribute('aria-expanded', 'false');
          content.append(summary, toggle, full);
        }
        full.replaceChildren(...baseFeatures.map((feature) => element('li', '', feature)));
        toggle.dataset.aipkitFeatureCount = String(baseFeatures.length);
        toggle.textContent = full.hidden ? sprintf(__('See all %s features', 'gpt3-ai-content-generator'), baseFeatures.length) : __('Hide feature list', 'gpt3-ai-content-generator');
        if (tier === 'plus' && !card.querySelector('.aipkit-pricing-recommended')) {
          card.append(element('span', 'aipkit-pricing-recommended', __('Recommended', 'gpt3-ai-content-generator')));
        }
      }
      const button = card.querySelector('.fs-upgrade-button');
      if (button && !button.disabled && /^(Upgrade Now|Upgrade)$/i.test(button.textContent.trim())) {
        button.dataset.aipkitActionLabel = sprintf(__('Get %s', 'gpt3-ai-content-generator'), name);
        button.setAttribute('aria-label', button.dataset.aipkitActionLabel);
      } else if (button) {
        delete button.dataset.aipkitActionLabel;
        button.removeAttribute('aria-label');
      }
    });
    root.dataset.aipkitCardCount = String(cards.filter((card) => !card.classList.contains('fs-free-plan')).length);
  }

  function enhanceSiteSelector() {
    const packages = root.querySelector('.fs-section--packages');
    const tables = Array.from(root.querySelectorAll('.fs-license-quantities')).filter((table) => table.querySelector('.fs-license-quantity input[type="radio"]'));
    const options = tables.map((table) => Array.from(table.querySelectorAll('.fs-license-quantity')).map((cell) => ({
      label: cell.textContent.trim(),
      radio: cell.querySelector('input[type="radio"]'),
    })).filter((option) => option.radio));
    const labels = (options[0] || []).map((option) => option.label).filter((label) => options.every((rows) => rows.some((row) => row.label === label)));
    let selector = root.querySelector('.aipkit-pricing-sites');
    if (!packages || labels.length < 2) {
      selector?.remove();
      delete root.dataset.aipkitSharedQuantity;
      return;
    }
    if (!selector) {
      selector = document.createElement('div');
      selector.className = 'aipkit-pricing-sites';
      selector.setAttribute('role', 'group');
      selector.setAttribute('aria-label', __('Number of sites', 'gpt3-ai-content-generator'));
      packages.before(selector);
    }
    if (Array.from(selector.children).map((button) => button.textContent).join('|') !== labels.join('|')) {
      selector.replaceChildren(...labels.map((label) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = label;
        button.dataset.aipkitSiteQuantity = label;
        return button;
      }));
    }
    for (const button of selector.children) {
      button.setAttribute('aria-pressed', String(options.every((rows) => rows.some((row) => row.label === button.textContent && row.radio.checked))));
    }
    root.dataset.aipkitSharedQuantity = 'true';
  }

  function enhance() {
    frame = 0;
    enhanceSiteSelector();
    enhanceCards();
    root.querySelectorAll('[data-billing-cycle]').forEach((tab) => {
      tab.setAttribute('role', 'button');
      tab.tabIndex = 0;
      tab.setAttribute('aria-pressed', String(tab.classList.contains('fs-selected-billing-cycle')));
    });
    root.querySelectorAll('.fs-section--faq-item').forEach((item, index) => {
      const title = item.querySelector('h3');
      const answer = item.querySelector('p');
      if (!title || !answer || title.hasAttribute('data-aipkit-faq-toggle')) return;
      if (!answer.id) answer.id = `aipkit-pricing-faq-${++sequence}`;
      title.dataset.aipkitFaqToggle = '';
      title.setAttribute('role', 'button');
      title.tabIndex = 0;
      title.setAttribute('aria-controls', answer.id);
      title.setAttribute('aria-expanded', index === 0 ? 'true' : 'false');
      answer.hidden = index !== 0;
    });
  }

  function activate(event) {
    const toggle = event.target.closest('.aipkit-pricing-features-toggle, [data-aipkit-faq-toggle], [data-aipkit-site-quantity], [data-billing-cycle]');
    if (!toggle || !root.contains(toggle)) return;
    if (event.type === 'keydown') {
      // Native buttons already produce a click for Enter and Space.
      if (toggle.tagName === 'BUTTON' || !['Enter', ' '].includes(event.key)) return;
      event.preventDefault();
    }
    if (toggle.hasAttribute('data-billing-cycle')) {
      if (event.type === 'keydown') toggle.click();
      return;
    }
    if (toggle.hasAttribute('data-aipkit-site-quantity')) {
      root.querySelectorAll('.fs-license-quantity').forEach((cell) => {
        const radio = cell.querySelector('input[type="radio"]');
        if (radio && cell.textContent.trim() === toggle.dataset.aipkitSiteQuantity && !radio.checked) radio.click();
      });
      enhanceSiteSelector();
      return;
    }
    const expanded = toggle.getAttribute('aria-expanded') !== 'true';
    const target = document.getElementById(toggle.getAttribute('aria-controls'));
    if (!target) return;
    toggle.setAttribute('aria-expanded', String(expanded));
    if (toggle.matches('.aipkit-pricing-features-toggle')) {
      target.hidden = !expanded;
      toggle.textContent = expanded
        ? __('Hide feature list', 'gpt3-ai-content-generator')
        : sprintf(__('See all %s features', 'gpt3-ai-content-generator'), toggle.dataset.aipkitFeatureCount);
    } else {
      target.hidden = !expanded;
    }
  }

  const observer = new MutationObserver(() => {
    if (!frame) frame = requestAnimationFrame(enhance);
  });
  function resume() {
    observer.observe(root, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });
    root.addEventListener('click', activate);
    root.addEventListener('keydown', activate);
    enhance();
  }
  resume();
  window.addEventListener('pagehide', () => {
    observer.disconnect();
    cancelAnimationFrame(frame);
    root.removeEventListener('click', activate);
    root.removeEventListener('keydown', activate);
  });
  window.addEventListener('pageshow', (event) => {
    if (event.persisted) resume();
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initPricing, { once: true });
} else {
  initPricing();
}
