const normalizePayloadContent = (content) => {
    if (typeof content === 'string') {
        return content.trim();
    }
    if (Array.isArray(content)) {
        return content
            .map((item) => normalizePayloadContent(item))
            .filter(Boolean)
            .join('\n\n');
    }
    if (!content || typeof content !== 'object') {
        return '';
    }
    if (typeof content.text === 'string') {
        return content.text.trim();
    }
    if (typeof content.content === 'string' || Array.isArray(content.content)) {
        return normalizePayloadContent(content.content);
    }
    if (typeof content.input_text === 'string') {
        return content.input_text.trim();
    }
    return '';
};

const resolvePayloadRole = (entry) => {
    const role = String(entry?.role || '').toLowerCase();
    if (role) {
        return role;
    }

    const type = String(entry?.type || '').toLowerCase();
    if (type === 'user_input') {
        return 'user';
    }
    if (type === 'model_output') {
        return 'assistant';
    }
    return '';
};

const firstDefined = (...values) => values.find((value) => value !== undefined && value !== null && value !== '');

export const summarizeRequestPayload = (requestPayload, providerLabelResolver = (provider) => provider) => {
    const payload = requestPayload && typeof requestPayload === 'object' ? requestPayload : {};
    const sent = payload.payload_sent && typeof payload.payload_sent === 'object'
        ? payload.payload_sent
        : payload;
    const messageList = Array.isArray(sent.messages)
        ? sent.messages
        : Array.isArray(sent.input)
            ? sent.input
            : Array.isArray(payload.messages)
                ? payload.messages
                : [];
    const systemParts = [];
    const directSystem = normalizePayloadContent(firstDefined(
        sent.system_instruction,
        sent.instructions,
        sent.system,
        payload.system_instruction,
        payload.instructions,
        payload.system
    ));
    if (directSystem) {
        systemParts.push(directSystem);
    }
    messageList.forEach((entry) => {
        const role = resolvePayloadRole(entry);
        if (!['system', 'developer'].includes(role)) {
            return;
        }
        const text = normalizePayloadContent(entry.content ?? entry.text);
        if (text && !systemParts.includes(text)) {
            systemParts.push(text);
        }
    });
    const userMessages = messageList
        .filter((entry) => resolvePayloadRole(entry) === 'user')
        .map((entry) => normalizePayloadContent(entry.content ?? entry.text))
        .filter(Boolean);
    const directInput = typeof sent.input === 'string' ? sent.input.trim() : '';
    const directPrompt = normalizePayloadContent(sent.prompt || payload.prompt);
    const providerKey = payload.provider || payload.ai_provider || sent.provider || sent.ai_provider || '';
    const model = payload.model || sent.model || '';
    const maxOutputTokens = firstDefined(
        sent.max_output_tokens,
        sent.max_completion_tokens,
        sent.max_tokens,
        sent.generation_config?.max_output_tokens,
        payload.max_output_tokens,
        payload.max_completion_tokens,
        payload.max_tokens,
        payload.generation_config?.max_output_tokens,
        payload.ai_params?.max_completion_tokens
    );
    const reasoning = firstDefined(
        sent.reasoning_effort,
        sent.reasoning?.effort,
        payload.reasoning_effort,
        payload.reasoning?.effort,
        payload.ai_params?.reasoning_effort
    );
    const maxOutputNumber = Number(maxOutputTokens);

    return {
        provider: providerKey ? providerLabelResolver(providerKey) : '—',
        model: model ? String(model) : '—',
        maxOutputTokens: maxOutputTokens !== undefined
            ? (Number.isFinite(maxOutputNumber) ? maxOutputNumber.toLocaleString() : String(maxOutputTokens))
            : '—',
        reasoning: reasoning !== undefined ? String(reasoning) : '—',
        systemPrompt: systemParts.join('\n\n'),
        userMessage: userMessages[userMessages.length - 1] || directInput || directPrompt,
    };
};
