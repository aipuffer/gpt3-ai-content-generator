/**
 * AIPKit Shared Public Markdown Renderer
 *
 * Initializes the markdown-it parser and provides lazy-loading helpers.
 */
(function() { // IIFE to avoid global scope pollution
    'use strict';

    let markdownLoadPromise = null;
    const existingGlobalMarkdownRenderer = typeof window.aipkit_getMarkdownRenderer === 'function'
        ? window.aipkit_getMarkdownRenderer
        : null;

    function getEscaper() {
        return window.aipkit_escapeHtml || function(str) {
            if (typeof str !== 'string') return '';
            return str
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        };
    }

    function createFallbackRenderer() {
        return {
            aipkitIsFallback: true,
            render: function(text) {
                return getEscaper()(String(text || '')).replace(/\n/g, '<br>');
            }
        };
    }

    function createMarkdownRenderer(options = {}) {
        if (typeof window.markdownit === 'function') {
            const renderer = window.markdownit(Object.assign({
                html: false,        // Keep false for security in public facing output
                xhtmlOut: false,    // Common practice for modern HTML
                breaks: true,       // Convert '\n' in paragraphs into <br>
                langPrefix: 'language-', // CSS class prefix for fenced blocks
                linkify: true,      // Autoconvert URL-like text to links
                typographer: true,  // Enable some language-neutral replacement + quotes beautification
                quotes: '“”‘’'      // Double + single quotes replacement pairs, when typographer enabled
            }, options));

            const defaultLinkOpenRenderer = renderer.renderer.rules.link_open ||
                function(tokens, idx, options, env, self) {
                    return self.renderToken(tokens, idx, options);
                };

            renderer.renderer.rules.link_open = function(tokens, idx, options, env, self) {
                const token = tokens[idx];
                const targetIndex = token.attrIndex('target');
                if (targetIndex < 0) {
                    token.attrPush(['target', '_blank']);
                } else {
                    token.attrs[targetIndex][1] = '_blank';
                }

                const relIndex = token.attrIndex('rel');
                if (relIndex < 0) {
                    token.attrPush(['rel', 'noopener noreferrer']);
                } else {
                    const relValue = token.attrs[relIndex][1] || '';
                    const relParts = relValue.split(/\s+/).filter(Boolean);
                    if (!relParts.includes('noopener')) relParts.push('noopener');
                    if (!relParts.includes('noreferrer')) relParts.push('noreferrer');
                    token.attrs[relIndex][1] = relParts.join(' ');
                }

                return defaultLinkOpenRenderer(tokens, idx, options, env, self);
            };

            // Optional: Add highlight.js if needed and available globally
            if (window.hljs && typeof window.markdownitHighlight === 'function') {
                renderer.use(window.markdownitHighlight, { hljs: window.hljs });
            }

            renderer.aipkitIsFallback = false;
            return renderer;
        }

        return createFallbackRenderer();
    }

    function getPublicAssetUrls() {
        return window.aipkitPublicAssetUrls && typeof window.aipkitPublicAssetUrls === 'object'
            ? window.aipkitPublicAssetUrls
            : {};
    }

    function loadMarkdownScript(markdownUrl) {
        return new Promise(function(resolve, reject) {
            if (typeof window.markdownit === 'function') {
                resolve(window.markdownit);
                return;
            }

            if (!markdownUrl) {
                reject(new Error('AIPKit markdown asset URL was not provided.'));
                return;
            }

            let absoluteUrl = markdownUrl;
            try {
                absoluteUrl = new URL(markdownUrl, window.location.href).href;
            } catch (error) {
                absoluteUrl = markdownUrl;
            }

            const handleLoad = function() {
                if (typeof window.markdownit === 'function') {
                    resolve(window.markdownit);
                    return;
                }
                reject(new Error('AIPKit markdown asset loaded, but window.markdownit is unavailable.'));
            };

            const handleError = function() {
                reject(new Error('AIPKit markdown asset failed to load.'));
            };

            const existingScript = Array.from(document.querySelectorAll('script[src]')).find(function(script) {
                try {
                    return new URL(script.src, window.location.href).href === absoluteUrl;
                } catch (error) {
                    return script.src === markdownUrl;
                }
            });

            if (existingScript) {
                if (typeof window.markdownit === 'function') {
                    resolve(window.markdownit);
                    return;
                }
                existingScript.addEventListener('load', handleLoad, { once: true });
                existingScript.addEventListener('error', handleError, { once: true });
                return;
            }

            const script = document.createElement('script');
            script.src = markdownUrl;
            script.async = true;
            script.dataset.aipkitMarkdownLoader = '1';
            script.addEventListener('load', handleLoad, { once: true });
            script.addEventListener('error', handleError, { once: true });
            document.head.appendChild(script);
        });
    }

    function aipkit_initializeMarkdownParser() {
        window.aipkit_md = createMarkdownRenderer();
        return window.aipkit_md;
    }

    function aipkit_getMarkdownRenderer() {
        if (!window.aipkit_md || typeof window.aipkit_md.render !== 'function') {
            window.aipkit_md = createFallbackRenderer();
        }
        return window.aipkit_md;
    }

    function aipkit_ensureMarkdownLibrary() {
        if (typeof window.markdownit === 'function') {
            return Promise.resolve(window.markdownit);
        }

        if (markdownLoadPromise) {
            return markdownLoadPromise;
        }

        const publicAssetUrls = getPublicAssetUrls();
        const markdownUrl = publicAssetUrls.markdownIt || publicAssetUrls.markdownit || '';

        markdownLoadPromise = loadMarkdownScript(markdownUrl).catch(function(error) {
            markdownLoadPromise = null;
            console.warn('AIPKit Chat Markdown: markdown-it lazy load failed.', error);
            throw error;
        });

        return markdownLoadPromise;
    }

    function aipkit_ensureMarkdownRenderer(options = {}) {
        if (typeof window.markdownit === 'function') {
            return Promise.resolve(createMarkdownRenderer(options));
        }

        return aipkit_ensureMarkdownLibrary()
            .then(function() {
                return createMarkdownRenderer(options);
            })
            .catch(function() {
                return createFallbackRenderer();
            });
    }

    function aipkit_ensureMarkdownParser() {
        const currentRenderer = aipkit_getMarkdownRenderer();
        if (!currentRenderer.aipkitIsFallback) {
            return Promise.resolve(currentRenderer);
        }

        return aipkit_ensureMarkdownRenderer().then(function(renderer) {
            window.aipkit_md = renderer;
            return renderer;
        });
    }

    function aipkit_isMarkdownParserReady() {
        return !aipkit_getMarkdownRenderer().aipkitIsFallback;
    }

    // Expose helpers globally so chat and AI Forms can share the same lazy loader.
    window.aipkit_createFallbackMarkdownRenderer = createFallbackRenderer;
    window.aipkit_createMarkdownRenderer = createMarkdownRenderer;
    window.aipkit_initializeMarkdownParser = aipkit_initializeMarkdownParser;
    // Preserve an existing admin-side renderer helper on wp-admin pages.
    if (!existingGlobalMarkdownRenderer) {
        window.aipkit_getMarkdownRenderer = aipkit_getMarkdownRenderer;
    }
    window.aipkit_ensureMarkdownLibrary = aipkit_ensureMarkdownLibrary;
    window.aipkit_ensureMarkdownRenderer = aipkit_ensureMarkdownRenderer;
    window.aipkit_ensureMarkdownParser = aipkit_ensureMarkdownParser;
    window.aipkit_isMarkdownParserReady = aipkit_isMarkdownParserReady;

    // Install a lightweight fallback immediately so initial page load does not
    // require markdown-it. The real parser is loaded lazily after interaction.
    aipkit_getMarkdownRenderer();

})();
