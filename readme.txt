=== AI Puffer – AI Chatbot, AI Writer & Automation ===
Contributors: senols
Tags: chatbot, chatgpt, ai writer, openai, ai
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.4.95
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI chatbot that answers from your content. Write and schedule posts. 25 free AI credits monthly, or use your own OpenAI, Claude or Gemini key.

== Description ==

**AI Puffer** (formerly AI Power) brings an AI chatbot, AI writer, scheduled content, AI forms and image generation to WordPress. Answer visitors from your content and create posts without leaving your dashboard.

**Start with 25 free AI credits monthly.** Connect AI Puffer Cloud with no API key. Free credits are available to eligible accounts with a verified email. You can also use your own OpenAI, Anthropic Claude, Google Gemini, xAI Grok, DeepSeek, OpenRouter or Azure OpenAI key.

[Documentation](https://docs.aipower.org/) · [How Cloud works](https://aipower.org/ai-puffer-cloud/) · [Pricing](https://aipower.org/pricing/) · [User reviews](https://wordpress.org/support/plugin/gpt3-ai-content-generator/reviews/)

### Cloud or your own API keys

**AI Puffer Cloud:** connect in the setup wizard or Settings → AI. Supported models cover text, image generation and analysis, speech, transcription and embeddings. Credit use varies by model and request size, with a minimum per text request. One credit is not always one message, article or image. Image editing, video, realtime voice and web search currently require your own supported provider key.

**Your own API keys:** your site sends requests to the provider you choose, which bills your provider account. OpenAI-compatible endpoints are supported too. Use Cloud and your own keys side by side, with a model picker for each chatbot, form, task or tool.

### Free and Pro

* **Free plugin:** chatbots, knowledge base, Content Writer, scheduled posts from topic lists or CSV, AI Forms, Images, usage limits and logs, visitor credit packages through WooCommerce, REST API and webhooks. AI usage requires Cloud credits or your own provider keys.
* **Pro, Pro Plus and Pro Max:** all three unlock the same premium plugin features listed below.
* **Monthly plan credits:** Pro Plus includes 3,000 AI credits monthly; Pro Max includes 10,000. Credits renew monthly with monthly or annual billing, do not roll over, and are shared across sites on the same account. A 2-site license does not double the allowance.
* **Pro and Pro lifetime:** no monthly paid-plan AI credits. Eligible free credits and optional top-ups remain available. Lifetime is available for Pro only.
* **Top-ups:** optional on every plan. Purchased AI credits do not expire and are shared across sites on the same account.

### AI chatbot that answers from your content

**Included free:** unlimited chatbots with their own models, instructions, colors, welcome messages and conversation starters. Use posts, pages, products and FAQs as knowledge. Show a bot as a popup, site-wide, or with a block or shortcode. Voice input, read-aloud replies and image input work with supported models. Web search and Google Search grounding require your own supported provider key.

**Pro adds:** file uploads in chat, realtime voice, advanced triggers, PDF export of conversations, and embedding your chatbot on other websites, including non-WordPress sites.

### AI writer and Content Assistant

Draft posts from topics and keywords, one at a time or from a batch or CSV list. Use reusable prompt templates, choose draft or publish status, and add featured or inline images from AI providers or stock photos. Start from Posts → Generate New Post.

**Included free:** Gutenberg output, meta descriptions and focus keywords where supported by Yoast SEO, Rank Math, All in One SEO or The SEO Framework. The Content Assistant improves titles, excerpts, content and tags in the block editor, classic editor and post lists.

**Pro adds:** RSS, URL and Google Sheets sources, Smart SEO audits and fixes, and bulk image alt text.

### Scheduled content and auto blogging

**Included free:** recurring or one-time automation tasks from topics or CSV, with draft, schedule or publish options. Reply to comments with replies held for approval by default, or add new posts to your knowledge base using category filters. Review item status in the queue. Server cron is supported when WP-Cron is disabled.

**Pro adds:** RSS, URL and Google Sheets sources, improving existing posts on a schedule, and Smart SEO inside tasks.

### AI forms

**Included free:** a drag-and-drop builder, templates and prompts that use visitors' field values. Choose a model and knowledge base for each form, and enable web search with your own supported key. Embed with a block or shortcode, and import or export forms.

**Pro adds:** file and image upload fields, multi-step forms, workflows and PDF download of results.

### AI image generator

Enable Images in Settings → Modules. Generate images with OpenAI GPT Image, Gemini image models, Grok Imagine, Replicate, OpenRouter, Azure or Cloud. Image editing works with supported providers and models. Google Veo video needs your own Google key. Search stock photos from Pexels and Pixabay, or embed a visitor image generator with a block or shortcode. These tools are included in the free plugin; provider accounts or Cloud credits cover AI usage.

### Knowledge base and semantic search

AI Puffer splits content into passages and retrieves relevant passages for a question. It does not train a new AI model.

**Included free:** posts, pages, products, custom post types, Q&A pairs and pasted text, including page-builder content. Use “Add to knowledge base” from post lists or embed semantic search on your site. Choose Local storage in your WordPress database, OpenAI vector stores, Google File Search, Pinecone, Qdrant or Chroma. External stores and embeddings require compatible provider connections.

**Pro adds:** PDF, DOCX, TXT, MD, CSV and JSON file sources, custom chunking and batch settings, and background reindexing.

### WooCommerce product tools and visitor credits

**Included free:** improve product titles, descriptions, short descriptions and tags with the Content Assistant; answer questions from product pages; and sell visitor credit packages for your chatbots, forms and image generator. Visitor credits are balances managed on your site, separate from AI Puffer Cloud credits. WooCommerce is optional.

**Pro adds:** product optimization mode in the Content Writer for updating existing products.

### Usage, permissions and integrations

**Included free:** guest, user and role limits with daily, weekly, monthly or no reset; conversation and usage logs; credit balances; and a Role Manager. Word and IP blocklists, IP anonymization and optional moderation help manage requests.

Use the REST API, signed event webhooks, three blocks and five shortcodes. WordPress AI Connectors are opt-in and let compatible built-in AI features use your configured providers.

**Pro adds:** automatic log deletion, a chatbot consent box, and Zapier, Make, n8n, Slack, HubSpot, Notion and Pipedrive integrations.

### Supported providers

* **AI Puffer Cloud:** available models from OpenAI, Anthropic, Google and others, using AI credits.
* **OpenAI:** GPT text models, GPT Image, speech, transcription and embeddings.
* **Anthropic:** Claude Sonnet, Opus and Haiku.
* **Google:** Gemini text and image models, Veo, speech and embeddings.
* **xAI:** Grok text and image models.
* **DeepSeek**, **OpenRouter** (including Mistral models), **Azure OpenAI** and OpenAI-compatible endpoints.
* **Ollama (Pro):** local models on your own server.
* **Media services:** ElevenLabs voices, Replicate images, and Pexels and Pixabay stock photos.

Models and capabilities depend on the selected provider and its current catalog.

### Privacy and data flow

AI requests are sent when you use a connected provider. Visitor messages go to the provider selected for that chatbot. Chat logs, usage records and Local knowledge are stored in your WordPress database. Text you index is processed by the chosen embedding provider and, with an external store, stored there. The External services section below explains connection, licensing and diagnostic requests and links each service's terms and privacy policy.

== Installation ==

1. In WordPress, go to Plugins → Add New Plugin, search for "AI Puffer", then click Install Now and Activate. You can also upload the plugin folder to `/wp-content/plugins/gpt3-ai-content-generator`.
2. On a new install, a setup wizard starts the first time you open AI Puffer. Choose what you want to do first (a chatbot, posts, product descriptions, automations or forms) and how to power AI: AI Puffer Cloud (accept the connection terms and confirm your email) or your own API key.
3. To connect Cloud or change API keys later, go to AI Puffer → Settings → AI. Models load after you save a key.
4. Open a module from the top bar of the AI Puffer screen: Chatbots, Content Writer, Automations, AI Forms, Knowledge Base or Usage. Turn on Images in Settings → Modules.

== Frequently Asked Questions ==

= Do I need an API key? =

Not if you use AI Puffer Cloud. Connect it in the setup wizard or in AI Puffer → Settings → AI, accept the connection terms and confirm your email. Eligible accounts get 25 free AI credits monthly. If you already pay for an AI provider such as OpenAI, Anthropic, Google or xAI, paste that key instead. You can use both side by side.

= What do the free Cloud credits include? =

Eligible accounts with a verified email get 25 free AI credits monthly. Connecting registers your site through Freemius, which handles the account and the confirmation email.

Credits pay for requests: text, image generation and analysis, speech, transcription and embeddings. The cost depends on the model and the size of the request, with a minimum per text request, so one credit is not one message, article or image.

Free credits reset each month and do not roll over. Purchased top-ups do not expire and are shared by the sites on your account. The allowance depends on eligibility and on the program being available, and Usage shows your balance and status. When credits run out, those requests stop until the allowance renews or you add credits. Providers you connect with your own key keep working.

= What's free and what's Pro? =

Everything listed under "Included free" works without a Pro license, using Cloud credits or your own provider keys. Some features need your own key with a provider that offers them, such as web search, Veo video and image editing. Pro adds file uploads for the knowledge base, chat and forms; RSS, URL and Google Sheets sources; improving existing posts on a schedule; product optimization mode; alt text for many images at once; Smart SEO; chatbot triggers; realtime voice; embedding chatbots on other sites; PDF export; multi-step forms and workflows; custom chunking and background reindexing; Ollama; automatic log deletion; a consent box; and the Zapier, Make, n8n, Slack, HubSpot, Notion and Pipedrive integrations.

Pro, Pro Plus and Pro Max unlock the same premium features. Monthly and annual Pro Plus subscriptions include 3,000 AI credits monthly; Pro Max includes 10,000. Credits renew monthly, do not roll over, and are shared across sites on the same account, including with a 2-site license. Pro and Pro lifetime include no monthly paid-plan credits; lifetime is available for Pro only. Optional purchased top-ups do not expire. See [pricing](https://aipower.org/pricing/).

= Can I use OpenAI, Claude, Gemini or Grok models? =

Yes. Add your own key for OpenAI, Anthropic (Claude), Google (Gemini) or xAI (Grok), or pick OpenAI, Anthropic and Google models on Cloud. DeepSeek, OpenRouter, Azure OpenAI and OpenAI-compatible endpoints work too, and Ollama local models are in Pro. Pick the model for each chatbot, form, task or tool.

= Does the chatbot answer only from my content? =

It answers from your content when it finds a good match, but it is not limited to it. With a knowledge base connected, the plugin looks up the passages that best match each question (a confidence threshold sets how close a match must be) and sends them to the AI model with the question and your instructions. The model can still use its general knowledge or make mistakes. Tell it in the instructions what to do when your content has no answer, for example "Say you don't know and suggest contacting us", and test it with real questions before you go live.

= How is my data handled in Cloud mode vs own-key mode? =

With your own key, requests go straight from your server to the provider you chose, under your account with that provider. They do not pass through AI Puffer Cloud. In Cloud mode, requests (prompts, messages, the content, images or audio you submit, and model settings) go to Cloud, which passes them to the AI service behind the selected model (through service providers such as OpenRouter where applicable) and records the credits used. Cloud's billing records hold usage and credit data, not full conversations. In both modes, chat logs, usage records and Local knowledge stay in your WordPress database. The External services section lists what each service receives and links its terms and privacy policy.

= Does it work with WooCommerce? =

Yes, and WooCommerce is optional. You can write product titles, descriptions, short descriptions and tags with the Content Assistant, let the chatbot answer from your products, and sell AI credit packages to visitors through your WooCommerce checkout. Product optimization mode in the Content Writer is in Pro.

= Is this the same plugin as AI Power? =

Yes. AI Power was renamed AI Puffer. It is the same plugin, with the same plugin folder, settings and data, so updating keeps your bots, content, keys and history. The new listing name says what it does: AI chatbot, AI writer and automation.

== Screenshots ==

1. Chatbots: set the welcome message, colors, icon, AI model and instructions, and watch a live preview. Show the bot as a popup or site-wide, or copy its shortcode.
2. Content Writer: draft a post from a topic and keywords, and choose the model, the length and the post status.
3. AI Forms: build a form from standard fields, pick a model, and write a prompt that uses what visitors enter.
4. Images: create images with your connected provider and browse the images you generated.
5. Automations: add a batch of topics and keywords for a scheduled content task.
6. Knowledge Base: add question-and-answer sources and choose where the knowledge is stored.
7. The chatbot on your site: a popup with a greeting and conversation starters.

== External services ==

The plugin contacts external services when you connect or use them, manage licensing or checkout, or submit optional deactivation feedback. Cloud setup and connection diagnostics are sent after you accept the Cloud connection terms and click Connect. An AI request contains what the feature needs: the prompt and instructions, chat or form input with recent conversation history, the content being written or improved, text you add to a knowledge base, images or audio you submit, and model settings. Each API key goes only to its own provider.

**AI Puffer Cloud** (puffercloud.dev, [about Cloud](https://aipower.org/ai-puffer-cloud/)), the optional hosted AI service run by the AI Puffer team.

* Connecting happens only after an administrator accepts the connection terms and clicks Connect. It sends the site's Freemius install ID, the site address, a signed proof of the installation and the attempt's support reference.
* Using its models sends the request content and model settings. Cloud passes each request to the AI service behind the chosen model (such as OpenAI, Anthropic or Google), through service providers such as OpenRouter where applicable, and returns the result and the credits used. Balance, model, checkout and disconnect requests send the site address and its Cloud credential; deleting the plugin also disconnects the site. While connected, the setup wizard sends your setup choices and completed steps.
* Connection diagnostics: each Connect attempt, including one that fails before account registration, sends its outcome, the step reached, error codes, safe network failure details, HTTP status, timing, software versions and a random support reference. Once an installation identity is available, the report also includes its site address and installation ID as unverified troubleshooting context. Your browser sends the report, so Cloud also sees the browser's IP address and the site's domain. Cloud keeps diagnostic reports for 30 days. The plugin keeps at most 10 local reports, removes those older than 30 days on the next attempt, and never reports on ordinary page loads.
* [Terms](https://aipower.org/terms-and-conditions/) · [Privacy](https://aipower.org/privacy-policy/)

**Freemius** ([freemius.com](https://freemius.com)): account registration, email verification, licensing, and checkout for Pro plans and credits. Registration happens only when you choose it, for example by connecting Cloud or signing up for product updates in the setup wizard. It sends your name, email address and site address, plus basic plugin details such as the version. General usage tracking is turned off in the setup flow, and marketing emails are optional. The in-plugin pricing page, checkout and license activation also contact Freemius. An answer to the optional deactivation feedback form is sent to Freemius when the plugin is deleted and, unless you tick "Anonymous feedback", registers the site. [Terms](https://freemius.com/terms/) · [Privacy](https://freemius.com/privacy/)

The AI providers and stores below are used only when you select one for a feature, or turn on a feature that uses it.

**OpenAI** (api.openai.com): text, images, embeddings, vector stores, speech, transcription, web search, and moderation if you turn it on (realtime voice is Pro). A custom OpenAI-compatible base URL receives these requests instead. [Terms](https://openai.com/policies/services-agreement/) · [Privacy](https://openai.com/policies/privacy-policy/)

**Google Gemini API** (generativelanguage.googleapis.com): text, images, Veo video, speech, transcription, embeddings, File Search stores and Search grounding. [Terms](https://ai.google.dev/gemini-api/terms) · [Privacy](https://policies.google.com/privacy)

**Anthropic** (api.anthropic.com): Claude text, image input and web search. [Terms](https://www.anthropic.com/legal/commercial-terms) · [Privacy](https://www.anthropic.com/legal/privacy)

**xAI** (api.x.ai): text, image input, web search and images. [Terms](https://x.ai/legal/terms-of-service-enterprise) · [Privacy](https://x.ai/legal/privacy-policy)

**DeepSeek** (api.deepseek.com): text. [Terms](https://cdn.deepseek.com/policies/en-US/deepseek-open-platform-terms-of-service.html) · [Privacy](https://cdn.deepseek.com/policies/en-US/deepseek-privacy-policy.html)

**OpenRouter** (openrouter.ai): text, images and web search, through the model vendor you pick. [Terms](https://openrouter.ai/terms) · [Privacy](https://openrouter.ai/privacy)

**Microsoft Azure OpenAI** (your Azure endpoint): text, images, embeddings and transcription. [Terms](https://azure.microsoft.com/en-us/support/legal/) · [Privacy](https://www.microsoft.com/en-us/privacy/privacystatement)

**ElevenLabs** (api.elevenlabs.io): reply text, when a bot reads replies aloud with ElevenLabs. [Terms](https://elevenlabs.io/terms-of-use) · [Privacy](https://elevenlabs.io/privacy-policy)

**Replicate** (api.replicate.com): image prompts and settings. [Terms](https://replicate.com/terms) · [Privacy](https://replicate.com/privacy)

**Pexels** (api.pexels.com) and **Pixabay** (pixabay.com): your stock photo search terms. Pexels [Terms](https://www.pexels.com/terms-of-service/) · [Privacy](https://www.pexels.com/privacy-policy/); Pixabay [Terms](https://pixabay.com/service/terms/) · [Privacy](https://pixabay.com/service/privacy/)

**Pinecone** (api.pinecone.io and your index host), **Qdrant and Chroma** (the address you enter), if chosen as your knowledge store: passages and embeddings of content you index, and search vectors for lookups. Pinecone [Terms](https://www.pinecone.io/legal/) · [Privacy](https://www.pinecone.io/privacy/); Qdrant [Terms](https://qdrant.tech/legal/terms_and_conditions/) · [Privacy](https://qdrant.tech/legal/privacy-policy/); Chroma [Terms](https://www.trychroma.com/terms) · [Privacy](https://www.trychroma.com/privacy)

**Webhooks:** if you add webhook URLs in Settings → Developers, signed event data is sent to those addresses.

**Pro only (not in the free version):** Ollama sends requests only to the server you enter (default `http://localhost:11434`). RSS feeds, web pages and Google Sheets you choose as sources are read from their sites. Chatbot trigger webhooks and the Slack, HubSpot, Notion, Pipedrive, Zapier, Make and n8n integrations send the data you map to the app or address you connect.

== Source code ==

The free edition's original JavaScript and CSS, build tools and instructions are available in the [public source repository](https://github.com/aipuffer/gpt3-ai-content-generator). Version tags identify source snapshots matching the corresponding free release.

== Upgrade Notice ==

= 2.4.95 =
Improves AI Puffer Cloud reconnection after reinstalling and preserves clearer connection diagnostics for support.

= 2.4.94 =
Fixes Content Writer settings reverting, provider model refresh issues, image defaults and history dimensions, and AI Forms feedback. Updates the feature and credit descriptions.

== Changelog ==

= 2.4.95 =

- Fixed AI Puffer Cloud reconnection after reinstalling when WordPress background synchronization is delayed or disabled.
- Improved connection diagnostics to retain network failure causes and submitted site details for troubleshooting.
- Updated guidance when a site’s account connection is inactive.

= 2.4.94 =

* Published a maintained source repository and build instructions for the free edition's bundled assets.
* Updated the Markdown rendering library to address excessive processing time with specially crafted text.
- Fixed Content Writer model, image and publishing settings reverting when reopening a starter template.
- Fixed provider model refresh errors and outdated model lists in Content Writer, Automations and Content Assistant.
- Use the lowest-cost available AI Puffer Cloud image model by default for new selections.
- Fixed generated image history showing requested dimensions instead of the saved image's actual dimensions.
- Clarified AI Forms credit errors and instructions for adding fields manually.
- Updated AI Puffer branding and the plugin's feature and credit descriptions.

= 2.4.93 =

- Improved connection error messages, including guidance for WordPress plugin previews.
- Added consented connection diagnostics and support references to help troubleshoot setup failures.

= 2.4.92 =

- Fixed blank WordPress admin pages when using plugins that manage admin notices.
- Fixed chatbot knowledge sources getting stuck on "Loading sources" during slow requests.
- Fixed knowledge source counts when source records have no WordPress post ID.

= 2.4.91 =

- Fixed Content Writer starter templates overriding the selected AI provider, model and image settings.
- Fixed Content Writer recovery after failed generation, preserving form inputs when starting over.
- Clarified what is included in Settings backups and imports.

= 2.4.90 =

- Fixed an authorization issue in semantic search settings.
- Fixed an authorization issue in global knowledge base indexing settings.
- Improved Usage module.

= 2.4.89 =

- Updated new chatbot defaults.
- Improved connection error messages.

= 2.4.88 =

- Unified provider connection notices.
- Restricted notices to AI Puffer pages.
- Fixed provider dialog navigation and the email Edit link's focus styling.

= 2.4.87 =

- Fixed an incorrect out-of-credits warning while AI Puffer Cloud email verification is pending.
- Cloud verification and available credits now refresh automatically after confirming your account email.

= 2.4.86 =

- Improved recovery and error handling for failed or unavailable Cloud image requests.
- Fixed Image Generator model availability and default selection when frontend model restrictions are configured.
- Fixed Image Generator model lists not updating after provider model synchronization.
- Added AI Puffer Cloud as an optional provider for text, image generation and analysis, speech, transcription, and embeddings along with other existing providers.
- Added Local knowledge storage in your WordPress database, with configurable dimensions and support for compatible embedding providers.
- Improved provider and model consistency across chatbots, content writing, automations, forms, media, editor tools, and the REST API.
- Fixed Settings autosave when switching tabs and included Visitor billing and connector settings in backups.
- Strengthened public-request protection and fixed IPv6 and Unicode blocklist matching.

= 2.4.85 =

- Fixed Image Generator model availability and default selection when frontend model restrictions are configured.
- Fixed Image Generator model lists not updating after provider model synchronization.

= 2.4.84 =

- Added AI Puffer Cloud as an optional provider for text, image generation and analysis, speech, transcription, and embeddings along with other existing providers.
- Added Local knowledge storage in your WordPress database, with configurable dimensions and support for compatible embedding providers.
- Improved provider and model consistency across chatbots, content writing, automations, forms, media, editor tools, and the REST API.
- Fixed Settings autosave when switching tabs and included Visitor billing and connector settings in backups.
- Strengthened public-request protection and fixed IPv6 and Unicode blocklist matching.

= 2.4.83 =

- Fixed a conflict with WP Gridbuilder filters, maps, and other frontend AJAX requests.

= 2.4.82 =

- Fixed unsupported sampling parameters for OpenAI GPT-6 Sol/Luna and newer Claude models, including Sonnet 5.
- Improved Content Assistant errors to show provider response details.
- Fixed AI Forms failing on cached pages with expired security tokens by refreshing the token and retrying once.
- Added category filtering to knowledge-base automations, including child categories.


= 2.4.81 =

- Added GPT Live voice conversations for chatbot.
- Fixed chatbot preview failures on servers without PHP mbstring.
- Added support for AI responses, transcription, and file uploads on servers without PHP cURL.
- Improved error messages and diagnostics for chatbot preview and AI request failures.
- Fixed OpenAI file uploads failing because of an invalid default upload purpose or a missing HTTP dependency.
