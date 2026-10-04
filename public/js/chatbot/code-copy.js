(function () {
  "use strict";

  const copySvg = `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-copy"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 7m0 2.667a2.667 2.667 0 0 1 2.667 -2.667h8.666a2.667 2.667 0 0 1 2.667 2.667v8.666a2.667 2.667 0 0 1 -2.667 2.667h-8.666a2.667 2.667 0 0 1 -2.667 -2.667z" /><path d="M4.012 16.737a2.005 2.005 0 0 1 -1.012 -1.737v-10c0 -1.1 .9 -2 2 -2h10c.75 0 1.158 .385 1.5 1" /></svg>`;

  function fallbackCopy(text, onSuccess) {
    try {
      const textArea = document.createElement("textarea");
      textArea.value = text;
      textArea.setAttribute("readonly", "");
      textArea.style.position = "absolute";
      textArea.style.left = "-9999px";
      document.body.appendChild(textArea);
      textArea.select();
      const successful = document.execCommand("copy");
      document.body.removeChild(textArea);
      if (successful) onSuccess();
    } catch (error) {
      console.error("AIPKit Code Copy: Fallback copy failed.", error);
    }
  }

  function aipkit_chatUI_attachCodeCopyButtons(bubbleEl, config) {
    if (!bubbleEl) return;
    if (config && config.enableCopyButton === false) return;
    const codeBlocks = bubbleEl.querySelectorAll("pre");
    if (!codeBlocks.length) return;
    const label =
      (config && config.text && config.text.copyCodeLabel) || "Copy code";

    codeBlocks.forEach((preEl) => {
      if (preEl.classList.contains("aipkit_code_block")) return;
      const button = document.createElement("button");
      button.type = "button";
      button.className = "aipkit_code_copy_btn";
      button.setAttribute("aria-label", label);
      button.setAttribute("title", label);
      button.innerHTML = copySvg;
      preEl.classList.add("aipkit_code_block");
      preEl.insertBefore(button, preEl.firstChild);
    });
  }

  function handleCodeCopyClick(event) {
    const button = event.target.closest(".aipkit_code_copy_btn");
    if (!button) return;
    event.preventDefault();
    const preEl = button.closest("pre");
    if (!preEl) return;
    const codeEl = preEl.querySelector("code");
    const textToCopy = codeEl ? codeEl.textContent : preEl.textContent;
    if (!textToCopy) return;
    const onSuccess = () => {
      if (typeof window.aipkit_chatUI_showSuccessIcon === "function") {
        window.aipkit_chatUI_showSuccessIcon(button);
      }
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(textToCopy).then(onSuccess).catch(() => {
        fallbackCopy(textToCopy, onSuccess);
      });
      return;
    }
    fallbackCopy(textToCopy, onSuccess);
  }

  document.addEventListener("click", handleCodeCopyClick);

  window.aipkit_chatUI_attachCodeCopyButtons =
    aipkit_chatUI_attachCodeCopyButtons;
})();
