export function createSourceActionMenu({ __, escaper }) {
  // Callers own action eligibility and supply escaped, action-specific attributes.
  function renderSourceAction(attributes, className, icon, label, disabled) {
    return `<button type="button" role="menuitem" class="aipkit_sources_action_menu_item ${className}"
      ${attributes}
      ${disabled ? 'disabled aria-disabled="true"' : ""}
    >
      <span class="dashicons ${icon}" aria-hidden="true"></span>
      <span>${escaper(label)}</span>
    </button>`;
  }

  function renderSourceTargetAttributes(log, provider = log.provider || "") {
    return `data-provider="${escaper(provider)}"
      data-store-id="${escaper(log.vector_store_id || "")}"
      data-vector-id="${escaper(log.file_id || "")}"
      data-log-id="${escaper(log.id || "")}"`;
  }

  function renderSourceActionMenu(actions) {
    return actions.length
      ? `<div class="aipkit_sources_actions">
          <button type="button" class="aipkit_sources_action_menu_trigger" aria-haspopup="menu" aria-expanded="false" title="${escaper(
            __("Actions", "gpt3-ai-content-generator")
          )}" aria-label="${escaper(__("Source actions", "gpt3-ai-content-generator"))}">
            <span class="dashicons dashicons-ellipsis" aria-hidden="true"></span>
          </button>
          <div class="aipkit_sources_action_menu_panel" role="menu" hidden>
            ${actions.join("")}
          </div>
        </div>`
      : `<span class="aipkit_sources_actions_empty">—</span>`;
  }

  return { renderSourceAction, renderSourceTargetAttributes, renderSourceActionMenu };
}
