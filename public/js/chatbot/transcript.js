/**
 * Chatbot transcript collection, text formatting, filenames and TXT download.
 * Existing window APIs and runtime delivery remain unchanged.
 */
(function () {
    'use strict';

    /**
     * Generates a timestamped filename.
     * @param {object} config - Chatbot config.
     * @param {string} extension - 'txt' or 'pdf'.
     * @returns {string} The generated filename.
     */
    function aipkit_chatUI_generateDownloadFilename(config, extension) {
         const date = new Date();
        const timestamp = `${date.getFullYear()}${String(date.getMonth() + 1).padStart(2, '0')}${String(date.getDate()).padStart(2, '0')}-${String(date.getHours()).padStart(2, '0')}${String(date.getMinutes()).padStart(2, '0')}`;
        return `chat-${config.botId || 'transcript'}-${timestamp}.${extension}`;
    }

    window.aipkit_chatUI_generateDownloadFilename = aipkit_chatUI_generateDownloadFilename;

    /**
     * Triggers the download of a Blob object.
     * @param {Blob} blob - The data blob.
     * @param {string} filename - The desired filename.
     */
    function aipkit_chatUI_triggerBlobDownload(blob, filename) {
         const link = document.createElement('a');
        if (typeof link.download === 'string') { // Check basic anchor download support
            link.href = URL.createObjectURL(blob);
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(link.href); // Clean up blob URL
        } else {
            // Fallback for older browsers or if download attribute not supported
            console.warn("AIPKit Chat Download: Download attribute not supported, opening in new window.");
            const url = URL.createObjectURL(blob);
            window.open(url, '_blank');
            // Consider leaving URL revocation to browser GC in fallback.
        }
    }

    window.aipkit_chatUI_triggerBlobDownload = aipkit_chatUI_triggerBlobDownload;

    function repeatPrefix(prefix, text) {
        return String(text || '')
            .split('\n')
            .map((line) => line ? `${prefix}${line}` : prefix.trimEnd())
            .join('\n');
    }

    function collapseInlineWhitespace(value) {
        return String(value || '').replace(/\s+/g, ' ');
    }

    function serializeChildren(node, options) {
        let output = '';
        node.childNodes.forEach((child) => {
            output += serializeNode(child, options);
        });
        return output;
    }

    function serializeList(node, options, ordered) {
        const items = Array.from(node.children).filter((child) => child.tagName === 'LI');
        if (!items.length) {
            return '';
        }

        const lines = items.map((item, index) => {
            const prefix = ordered ? `${index + 1}. ` : '- ';
            const itemText = serializeChildren(item, options).trim().replace(/\n{3,}/g, '\n\n');
            return repeatPrefix(' '.repeat(prefix.length), `${prefix}${itemText}`).trimEnd();
        });

        return `${lines.join('\n')}\n\n`;
    }

    function serializeTable(node, options) {
        const rows = Array.from(node.querySelectorAll('tr'));
        if (!rows.length) {
            return '';
        }

        const lines = rows.map((row) => {
            const cells = Array.from(row.querySelectorAll('th, td')).map((cell) =>
                collapseInlineWhitespace(serializeChildren(cell, options).trim())
            ).filter(Boolean);
            return cells.join(' | ');
        }).filter(Boolean);

        return lines.length ? `${lines.join('\n')}\n\n` : '';
    }

    function serializeNode(node, options) {
        if (!node) {
            return '';
        }

        if (node.nodeType === Node.TEXT_NODE) {
            return options.preserveWhitespace
                ? String(node.textContent || '')
                : collapseInlineWhitespace(node.textContent || '');
        }

        if (node.nodeType !== Node.ELEMENT_NODE) {
            return '';
        }

        const tagName = node.tagName.toUpperCase();
        if (tagName === 'BR') {
            return '\n';
        }

        if (tagName === 'IMG') {
            const altText = collapseInlineWhitespace(node.getAttribute('alt') || '').trim();
            return altText ? `[Image: ${altText}]` : '[Image]';
        }

        if (tagName === 'A') {
            const label = collapseInlineWhitespace(serializeChildren(node, options)).trim();
            const href = String(node.getAttribute('href') || '').trim();
            if (!href) {
                return label;
            }
            if (!label || label === href) {
                return href;
            }
            return `${label} (${href})`;
        }

        if (tagName === 'HR') {
            return '\n---\n\n';
        }

        if (tagName === 'PRE') {
            const codeText = String(node.textContent || '').replace(/\s+$/, '');
            if (!codeText) {
                return '';
            }
            return `\`\`\`\n${codeText}\n\`\`\`\n\n`;
        }

        if (tagName === 'CODE') {
            if (node.closest('pre')) {
                return String(node.textContent || '');
            }
            const inlineCode = collapseInlineWhitespace(node.textContent || '').trim();
            return inlineCode ? `\`${inlineCode}\`` : '';
        }

        if (tagName === 'BLOCKQUOTE') {
            const quoteText = serializeChildren(node, options).trim().replace(/\n{3,}/g, '\n\n');
            return quoteText ? `${repeatPrefix('> ', quoteText)}\n\n` : '';
        }

        if (tagName === 'UL') {
            return serializeList(node, options, false);
        }

        if (tagName === 'OL') {
            return serializeList(node, options, true);
        }

        if (tagName === 'TABLE') {
            return serializeTable(node, options);
        }

        if (/^H[1-6]$/.test(tagName)) {
            const level = parseInt(tagName.slice(1), 10);
            const headingText = collapseInlineWhitespace(serializeChildren(node, options)).trim();
            if (!headingText) {
                return '';
            }
            return `${'#'.repeat(level)} ${headingText}\n\n`;
        }

        let content = serializeChildren(node, options);
        if (tagName === 'P' || tagName === 'DIV') {
            content = content.trim();
            return content ? `${content}\n\n` : '';
        }

        return content;
    }

    function normalizeTranscriptText(text) {
        return String(text || '')
            .replace(/[ \t]+\n/g, '\n')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
    }

    function getSenderMeta(messageEl, config) {
        const textLabels = config && config.text ? config.text : {};
        const botName = config?.headerName || 'Bot';

        if (messageEl.classList.contains('aipkit_chat_message-error')) {
            return {
                role: 'error',
                label: textLabels.errorPrefix || 'Error',
            };
        }

        if (messageEl.classList.contains('aipkit_chat_message-user')) {
            return {
                role: 'user',
                label: textLabels.userPrefix || 'User',
            };
        }

        return {
            role: 'bot',
            label: botName,
        };
    }

    function formatExportTimestamp() {
        try {
            return new Intl.DateTimeFormat(undefined, {
                dateStyle: 'medium',
                timeStyle: 'short',
            }).format(new Date());
        } catch (error) {
            return new Date().toLocaleString();
        }
    }

    function aipkit_chatUI_collectTranscriptEntries(messagesEl, config) {
        if (!messagesEl) {
            return [];
        }

        const entries = [];
        const messages = messagesEl.querySelectorAll('.aipkit_chat_message');

        messages.forEach((messageEl) => {
            if (messageEl.classList.contains('aipkit_typing-indicator')) {
                return;
            }

            const bubble = messageEl.querySelector('.aipkit_chat_bubble');
            if (!bubble) {
                return;
            }

            const text = normalizeTranscriptText(
                serializeChildren(bubble, {
                    preserveWhitespace: false,
                })
            );

            if (!text) {
                return;
            }

            const sender = getSenderMeta(messageEl, config);
            entries.push({
                role: sender.role,
                label: sender.label,
                text,
            });
        });

        return entries;
    }

    function aipkit_chatUI_buildTranscriptDocument(messagesEl, config) {
        const entries = aipkit_chatUI_collectTranscriptEntries(messagesEl, config);
        return {
            title: 'Chat Transcript',
            botName: config?.headerName || 'Bot',
            exportedAt: formatExportTimestamp(),
            entries,
        };
    }

    window.aipkit_chatUI_collectTranscriptEntries = aipkit_chatUI_collectTranscriptEntries;
    window.aipkit_chatUI_buildTranscriptDocument = aipkit_chatUI_buildTranscriptDocument;

    function indentMultilineText(text, indent) {
        return String(text || '')
            .split('\n')
            .map((line) => line ? `${indent}${line}` : '')
            .join('\n');
    }

    function buildTxtTranscript(documentData) {
        const separator = '='.repeat(72);
        const sectionDivider = '-'.repeat(72);
        const lines = [
            documentData.title || 'Chat Transcript',
            `Bot: ${documentData.botName || 'Bot'}`,
            `Exported: ${documentData.exportedAt || ''}`,
            separator,
            '',
        ];

        documentData.entries.forEach((entry, index) => {
            lines.push(`[${index + 1}] ${entry.label}`);
            lines.push(sectionDivider);
            lines.push(indentMultilineText(entry.text, '  '));
            lines.push('');
        });

        return lines.join('\n').replace(/\n{3,}/g, '\n\n').trim();
    }

    /**
     * Generates and triggers the download of the chat transcript as a TXT file.
     * @param {object} elements - Object containing references to UI elements { messagesEl }.
     * @param {object} config - The chatbot configuration object, needs { botId, text { userPrefix, errorPrefix, downloadEmpty }, headerName }.
     */
    function aipkit_chatUI_downloadTranscriptActionTxt(elements, config) {
        const { messagesEl } = elements;
        const showInlineNotice = (message, type = 'info', autoHide = true, autoHideMs = 4500) => {
            if (typeof window.aipkit_chatUI_showInlineNotice === 'function') {
                window.aipkit_chatUI_showInlineNotice(
                    message,
                    type,
                    { elements, config, messagesEl },
                    autoHide,
                    autoHideMs
                );
            }
        };
        if (!messagesEl || !config || !config.text) {
            console.error("AIPKit Download TXT: Missing messages element or config.");
            return;
        }

        if (typeof window.aipkit_chatUI_buildTranscriptDocument !== 'function') {
            console.error("AIPKit Download TXT: Transcript export helper not found.");
            showInlineNotice(
                config.text?.downloadPrepareError || "Error: Could not prepare download.",
                'error',
                true,
                7000
            );
            return;
        }

        const transcriptDocument = window.aipkit_chatUI_buildTranscriptDocument(messagesEl, config);
        if (!transcriptDocument.entries.length) {
            console.warn("AIPKit Chat Download TXT: No transcript content found.");
            showInlineNotice(config.text.downloadEmpty || "Nothing to download.", 'info', true);
            return;
        }

        const transcript = buildTxtTranscript(transcriptDocument);

        if (!transcript) {
            console.warn("AIPKit Chat Download TXT: Transcript formatting produced empty output.");
            showInlineNotice(config.text.downloadEmpty || "Nothing to download.", 'info', true);
            return;
        }

        const blob = new Blob([transcript], { type: 'text/plain;charset=utf-8' });

        if (typeof window.aipkit_chatUI_generateDownloadFilename !== 'function' || typeof window.aipkit_chatUI_triggerBlobDownload !== 'function') {
            console.error("AIPKit Download TXT: generateDownloadFilename or triggerBlobDownload function not found.");
            showInlineNotice(
                config.text?.downloadPrepareError || "Error: Could not prepare download.",
                'error',
                true,
                7000
            );
            return;
        }
        const filename = window.aipkit_chatUI_generateDownloadFilename(config, 'txt');
        window.aipkit_chatUI_triggerBlobDownload(blob, filename);
    }

    window.aipkit_chatUI_downloadTranscriptActionTxt = aipkit_chatUI_downloadTranscriptActionTxt;
})();
