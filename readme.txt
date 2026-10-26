=== AI Puffer – AI Chatbot & MCP Server for Claude and ChatGPT ===
Contributors: senols
Tags: mcp, chatgpt, claude, chatbot, ai
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.5.16
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add an AI chatbot and manage your WordPress website through Claude or ChatGPT with AI Puffer’s MCP server.

== Description ==

**AI Puffer** (formerly AI Power) lets ChatGPT and Claude work on your WordPress site through MCP, using your chosen safety level. It also brings an AI chatbot, content writer, automations, AI forms and image generation to your dashboard.

**Start with 25 free AI credits monthly.** Connect AI Puffer Cloud with no API key. Free credits are available to eligible accounts with a verified email. You can also use your own OpenAI, Anthropic Claude, Google Gemini, xAI Grok, DeepSeek, OpenRouter or Azure OpenAI key.

[Documentation](https://docs.aipower.org/) · [How Cloud works](https://aipower.org/ai-puffer-cloud/) · [Pricing](https://aipower.org/pricing/) · [User reviews](https://wordpress.org/support/plugin/gpt3-ai-content-generator/reviews/)

### Manage your site from ChatGPT or Claude (MCP)

Open Settings → Connections → Manage my website to connect ChatGPT, Claude, Cursor, Claude Code or another MCP client. Assistants can read and search content, create drafts and publish posts. Standard asks for WordPress approval before publishing or supported plugin changes. Trusted is the default for new connections and runs supported changes directly, including publication, plugin changes and deletion. Existing Trusted connections keep their previous approvals until you select Trusted again in Settings. The available actions depend on the site’s installed plugins and the permissions you grant. Access follows the connecting WordPress user’s permissions and can be paused or disconnected. Website actions are counted separately from AI credits; your assistant’s own subscription or model charges are separate. This feature is off until you explicitly connect it.

Supported WP-CLI-compatible commands let assistants inspect the site and manage posts, images, categories, tags, comments, classic navigation menus, settings, users, roles, cache and scheduled events without SSH or a WP-CLI installation. Official plugin/theme/core administration and bounded database inspection/search-replace commands are included. Ask your assistant for `wp help` to see the supported subset and flags. Commands use the same permissions, approval settings and website-action allowance as other website tools; shell scripts and full WP-CLI compatibility are not included.

### Cloud or your own API keys

**AI Puffer Cloud:** connect in the setup wizard or Settings → AI. Supported models cover text, image generation and analysis, speech, transcription and embeddings. Credit use varies by model and request size, with a minimum per text request. One credit is not always one message, article or image. Image editing, video, realtime voice and web search currently require your own supported provider key.

**Your own API keys:** your site sends requests to the provider you choose, which bills your provider account. OpenAI-compatible endpoints are supported too. Use Cloud and your own keys side by side, with a model picker for each chatbot, form, task or tool.

### Included in the free plugin

This edition includes the following core modules without a paid license. AI requests use your own provider account or AI Puffer Cloud credits. Website management is optional and includes 2,000 monthly website actions on the Free allowance, shared across your connected sites and assistants.

### AI chatbot that answers from your content

Create unlimited chatbots with their own models, instructions, colors, welcome messages and conversation starters. Use posts, pages, products and FAQs as knowledge. Show a bot as a popup, site-wide, or with a block or shortcode. Voice input, read-aloud replies and image input work with supported models. Web search and Google Search grounding require your own supported provider key.

### AI content writer and Content Assistant

Draft posts from topics and keywords, one at a time or from a batch or CSV list. Use reusable prompt templates, choose draft or publish status, and add featured or inline images from AI providers or stock photos. Start from Posts → Generate New Post.

Write Gutenberg-ready content, with meta descriptions and focus keywords where supported by Yoast SEO, Rank Math, All in One SEO or The SEO Framework. The Content Assistant improves titles, excerpts, content and tags in the block editor, classic editor and post lists.

### Automations: scheduled content and auto blogging

Create recurring or one-time automation tasks from topics or CSV, with draft, schedule or publish options. Reply to comments with replies held for approval by default, or add new posts to your knowledge base using category filters. Review item status in the queue. Server cron is supported when WP-Cron is disabled.

### AI forms

Build forms with a drag-and-drop editor, templates and prompts that use visitors' field values. Choose a model and knowledge base for each form, and enable web search with your own supported key. Embed with a block or shortcode, and import or export forms.

### AI image generator

Enable Images in Settings → Modules. Generate images with OpenAI GPT Image, Gemini image models, Grok Imagine, Replicate, OpenRouter, Azure or Cloud. Image editing works with supported providers and models. Google Veo video needs your own Google key. Search stock photos from Pexels and Pixabay, or embed a visitor image generator with a block or shortcode. These tools are included in the free plugin; provider accounts or Cloud credits cover AI usage.

### Knowledge base and semantic search

AI Puffer splits content into passages and retrieves relevant passages for a question. It does not train a new AI model.

Index posts, pages, products, custom post types, Q&A pairs and pasted text, including page-builder content. Use “Add to knowledge base” from post lists or embed semantic search on your site. Choose Local storage in your WordPress database, OpenAI vector stores, Google File Search, Pinecone, Qdrant or Chroma. External stores and embeddings require compatible provider connections.

### WooCommerce product tools and visitor credits

Improve product titles, descriptions, short descriptions and tags with the Content Assistant; answer questions from product pages; and sell visitor credit packages for your chatbots, forms and image generator. Visitor credits are balances managed on your site, separate from AI Puffer Cloud credits. WooCommerce is optional.

### Usage, permissions and integrations

Set guest, user and role limits with daily, weekly, monthly or no reset. Review conversation and usage logs, credit balances and access through the Role Manager. Word and IP blocklists, IP anonymization and optional moderation help manage requests.

Use the REST API, signed event webhooks, three blocks and five shortcodes. WordPress AI Connectors are opt-in and let compatible built-in AI features use your configured providers.

### Optional upgrades

The free plugin works on its own. Paid subscriptions add:

* File sources and uploads for knowledge bases, chatbots and forms.
* RSS, URL and Google Sheets content sources, scheduled post improvements and Smart SEO tools.
* Realtime voice, advanced chatbot triggers, external chatbot embeds, multi-step forms and PDF exports.
* Additional app integrations, Ollama, automatic log retention and higher monthly Cloud allowances.

See the [feature comparison and current plans](https://aipower.org/pricing/) for the full list. All subscription tiers unlock the same additional plugin features; their monthly allowances differ.

### Supported providers

* **AI Puffer Cloud:** available models from OpenAI, Anthropic, Google and others, using AI credits.
* **OpenAI:** GPT text models, GPT Image, speech, transcription and embeddings.
* **Anthropic:** Claude Sonnet, Opus and Haiku.
* **Google:** Gemini text and image models, Veo, speech and embeddings.
* **xAI:** Grok text and image models.
* **DeepSeek**, **OpenRouter** (including Mistral models), **Azure OpenAI** and OpenAI-compatible endpoints.
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

= How do ChatGPT and Claude manage my site? =

Connect them in Settings → Connections → Manage my website. Through MCP they can read and search content, create drafts and publish posts. Standard asks for WordPress approval before publishing or supported plugin changes. New connections default to Trusted, which runs supported changes directly, including deletion. Existing Trusted connections keep their previous approvals until you select Trusted again in Settings. You can pause or disconnect access anytime. The chatbot, content writer, automations, forms and images work as before, with or without this connection.

= Do I need an API key? =

Not if you use AI Puffer Cloud. Connect it in the setup wizard or in AI Puffer → Settings → AI, accept the connection terms and confirm your email. Eligible accounts get 25 free AI credits monthly. If you already pay for an AI provider such as OpenAI, Anthropic, Google or xAI, paste that key instead. You can use both side by side.

= What do the free Cloud credits include? =

Eligible accounts with a verified email get 25 free AI credits monthly. Connecting registers your site through Freemius, which handles the account and the confirmation email.

Credits pay for requests: text, image generation and analysis, speech, transcription and embeddings. The cost depends on the model and the size of the request, with a minimum per text request, so one credit is not one message, article or image.

Free credits reset each month and do not roll over. Purchased top-ups do not expire and are shared by the sites on your account. The allowance depends on eligibility and on the program being available, and Usage shows your balance and status. When credits run out, those requests stop until the allowance renews or you add credits. Providers you connect with your own key keep working.

= What is included without a paid license? =

Chatbots, content writing, topic/CSV automations, AI Forms, images, knowledge storage, website management, usage controls and developer integrations are included in the free plugin. AI provider charges are separate; use your own keys or Cloud credits. Some capabilities, such as web search or image editing, require a compatible provider and model.

Optional upgrades are summarized above. Visit the [pricing page](https://aipower.org/pricing/) for the full comparison and subscription allowances. Cloud allowances are shared across an account's sites, not multiplied by the number of licensed sites. Monthly allowances do not roll over; purchased AI credits do not expire. Existing lifetime licenses keep their plugin access but do not include paid subscription allowances.

= Can I use OpenAI, Claude, Gemini or Grok models? =

Yes. Add your own key for OpenAI, Anthropic (Claude), Google (Gemini) or xAI (Grok), or pick OpenAI, Anthropic and Google models on Cloud. DeepSeek, OpenRouter, Azure OpenAI and OpenAI-compatible endpoints work too. Pick the model for each chatbot, form, task or tool.

= Does the chatbot answer only from my content? =

It answers from your content when it finds a good match, but it is not limited to it. With a knowledge base connected, the plugin looks up the passages that best match each question (a confidence threshold sets how close a match must be) and sends them to the AI model with the question and your instructions. The model can still use its general knowledge or make mistakes. Tell it in the instructions what to do when your content has no answer, for example "Say you don't know and suggest contacting us", and test it with real questions before you go live.

= How is my data handled in Cloud mode vs own-key mode? =

With your own key, requests go straight from your server to the provider you chose, under your account with that provider. They do not pass through AI Puffer Cloud. In Cloud mode, requests (prompts, messages, the content, images or audio you submit, and model settings) go to Cloud, which passes them to the AI service behind the selected model (through service providers such as OpenRouter where applicable) and records the credits used. Cloud's billing records hold usage and credit data, not full conversations. In both modes, chat logs, usage records and Local knowledge stay in your WordPress database. The External services section lists what each service receives and links its terms and privacy policy.

= Does it work with WooCommerce? =

Yes, and WooCommerce is optional. You can write product titles, descriptions, short descriptions and tags with the Content Assistant, let the chatbot answer from your products, and sell AI credit packages to visitors through your WooCommerce checkout.

= Is this the same plugin as AI Power? =

Yes. AI Power was renamed AI Puffer. It is the same plugin, with the same plugin folder, settings and data, so updating keeps your bots, content, keys and history. The new listing name says what it does: AI chatbot, AI writer and automation.

== Screenshots ==

1. Website management: connect assistants such as ChatGPT or Claude, manage their access, and pause or disconnect website management.
2. Dashboard: view AI credits, website-action allowances, usage breakdowns and recent activity separately.
3. Chatbots: choose the AI model, instructions and knowledge sources, with a live chatbot preview.
4. Content Writer: draft a post from a topic and keywords, and choose the model, length and post status.
5. AI Forms: build forms from fields and write prompts using visitor input.
6. Images: generate images with your connected provider and browse your image history.
7. Automations: schedule content creation from topics, feeds, URLs or spreadsheets.
8. Knowledge Base: add questions and answers, text, websites or files, and choose where they are indexed.
9. Guided setup: choose chatbots, website management, AI forms, or writing and scheduling content.

== External services ==

The plugin contacts external services when you connect or use them, manage licensing or checkout, or submit optional deactivation feedback. Cloud setup and connection diagnostics are sent after you accept the Cloud connection terms and click Connect. An AI request contains what the feature needs: the prompt and instructions, chat or form input with recent conversation history, the content being written or improved, text you add to a knowledge base, images or audio you submit, and model settings. Each API key goes only to its own provider.

**AI Puffer Cloud** (puffercloud.dev, [about Cloud](https://aipower.org/ai-puffer-cloud/)), the optional hosted AI service run by the AI Puffer team.

* Connecting happens only after an administrator accepts the connection terms and clicks Connect. It sends the site's Freemius install ID, the site address, a signed proof of the installation and the attempt's support reference.
* Using its models sends the request content and model settings. Cloud passes each request to the AI service behind the chosen model (such as OpenAI, Anthropic or Google), through service providers such as OpenRouter where applicable, and returns the result and the credits used. Balance, model, checkout and disconnect requests send the site address and its Cloud credential; deleting the plugin also disconnects the site. While connected, the setup wizard sends your setup choices and completed steps.
* Connection diagnostics: each Connect attempt, including one that fails before account registration, sends its outcome, the step reached, error codes, safe network failure details, HTTP status, timing, software versions and a random support reference. Once an installation identity is available, the report also includes its site address and installation ID as unverified troubleshooting context. Your browser sends the report, so Cloud also sees the browser's IP address and the site's domain. Cloud keeps diagnostic reports for 30 days. The plugin keeps at most 10 local reports, removes those older than 30 days on the next attempt, and never reports on ordinary page loads.
* [Terms](https://aipower.org/terms-and-conditions/) · [Privacy](https://aipower.org/privacy-policy/)

**Freemius** ([freemius.com](https://freemius.com)): account registration, email verification, licensing, and checkout for subscriptions and credits. Registration happens only when you choose it, for example by connecting Cloud or signing up for product updates in the setup wizard. It sends your name, email address and site address, plus basic plugin details such as the version. General usage tracking is turned off in the setup flow, and marketing emails are optional. The in-plugin pricing page, checkout and license activation also contact Freemius. An answer to the optional deactivation feedback form is sent to Freemius when the plugin is deleted and, unless you tick "Anonymous feedback", registers the site. [Terms](https://freemius.com/terms/) · [Privacy](https://freemius.com/privacy/)

The AI providers and stores below are used only when you select one for a feature, or turn on a feature that uses it.

**OpenAI** (api.openai.com): text, images, embeddings, vector stores, speech, transcription, web search, and moderation if you turn it on. A custom OpenAI-compatible base URL receives these requests instead. [Terms](https://openai.com/policies/services-agreement/) · [Privacy](https://openai.com/policies/privacy-policy/)

**Google Gemini API** (generativelanguage.googleapis.com): text, images, Veo video, speech, transcription, embeddings, File Search stores and Search grounding. [Terms](https://ai.google.dev/gemini-api/terms) · [Privacy](https://policies.google.com/privacy)

**Anthropic** (api.anthropic.com): Claude text, image input and web search. [Terms](https://www.anthropic.com/legal/commercial-terms) · [Privacy](https://www.anthropic.com/legal/privacy)

**xAI** (api.x.ai): text, image input, web search and images. [Terms](https://x.ai/legal/terms-of-service-enterprise) · [Privacy](https://x.ai/legal/privacy-policy)

**DeepSeek** (api.deepseek.com): text. [Terms](https://cdn.deepseek.com/policies/en-US/deepseek-open-platform-terms-of-service.html) · [Privacy](https://cdn.deepseek.com/policies/en-US/deepseek-privacy-policy.html)

**OpenRouter** (openrouter.ai): text, images and web search, through the model vendor you pick. [Terms](https://openrouter.ai/terms) · [Privacy](https://openrouter.ai/privacy)

**Microsoft Azure OpenAI** (your Azure endpoint): text, images, embeddings and transcription. [Terms](https://azure.microsoft.com/en-us/support/legal/) · [Privacy](https://www.microsoft.com/en-us/privacy/privacystatement)

**ElevenLabs** (api.elevenlabs.io): reply text, when a bot reads replies aloud with ElevenLabs. [Terms](https://elevenlabs.io/terms-of-use) · [Privacy](https://elevenlabs.io/privacy-policy)

**Replicate** (api.replicate.com): image prompts and settings. [Terms](https://replicate.com/terms) · [Privacy](https://replicate.com/privacy)

**Pexels** (api.pexels.com) and **Pixabay** (pixabay.com): your stock photo search terms. Pexels [Terms](https://www.pexels.com/terms-of-service/) · [Privacy](https://www.pexels.com/privacy-policy/); Pixabay [Terms](https://pixabay.com/service/terms/) · [Privacy](https://pixabay.com/service/privacy/)

**Openverse** (api.openverse.org): when you ask a connected assistant to search stock images, your search terms and image filters go from your website to Openverse. No API key is needed. Results include creator, source and licence links; check the original source and retain required attribution. Searches are cached locally for one hour. Downloading a chosen image uses the existing media upload tool and contacts its image host. [Terms](https://openverse.org/terms-of-service) · [Privacy](https://openverse.org/privacy)

**Pinecone** (api.pinecone.io and your index host), **Qdrant and Chroma** (the address you enter), if chosen as your knowledge store: passages and embeddings of content you index, and search vectors for lookups. Pinecone [Terms](https://www.pinecone.io/legal/) · [Privacy](https://www.pinecone.io/privacy/); Qdrant [Terms](https://qdrant.tech/legal/terms_and_conditions/) · [Privacy](https://qdrant.tech/legal/privacy-policy/); Chroma [Terms](https://www.trychroma.com/terms) · [Privacy](https://www.trychroma.com/privacy)

**Hosted website management (mcp.aipuffer.org):** only after you enable Manage my website and accept its disclosure, the bridge receives your site address, WordPress/plugin versions, Cloud account/site identifiers and public signing key to register the site. Assistant connections include the WordPress user identifier and permitted actions. Assistant requests and website results, including requested post text, pass through the bridge to your chosen assistant; draft content is sent to your WordPress site. Operational records contain identifiers, action names, status and usage, without post bodies or credentials. Recent operational history is searchable for 30 days; archives and completed admin command records are retained for 24 months, and unresolved cases are held until resolved. WordPress keeps its local operation records for recovery. Standard access requires WordPress approval for post publication; Trusted access can publish posts directly. Other plugins’ changes and deletions require approval at every level. [Terms](https://aipower.org/terms-and-conditions/) · [Privacy](https://aipower.org/privacy-policy/). Your assistant provider’s own terms also apply.

**Webhooks:** if you add webhook URLs in Settings → Developers, signed event data is sent to those addresses.

**Services used by optional upgraded features:** Ollama sends requests only to the server you enter (default `http://localhost:11434`). RSS feeds, web pages and Google Sheets you choose as sources are read from their sites. Chatbot trigger webhooks and the Slack, HubSpot, Notion, Pipedrive, Zapier, Make and n8n integrations send the data you map to the app or address you connect.

== Source code ==

The free edition's original JavaScript and CSS, build tools and instructions are available in the [public source repository](https://github.com/aipuffer/gpt3-ai-content-generator). Version tags identify source snapshots matching the corresponding free release.

== Upgrade Notice ==

= 2.5.16 =
Improves website-action reliability, preserves plugin activation during updates, and adds supported integration settings and native Elementor Atomic workflows.

= 2.5.15 =
* Add simple-product sale scheduling, inspection and cancellation with site-timezone validation and stale-update protection.

Fixes unintended Cloud disconnections during temporary account-identity failures and prevents false connection success reports.

= 2.5.14 =

Fixes MCP content false positives, protects existing Gutenberg layouts, and adds clearer website-action diagnostics.

= 2.5.13 =

MCP improvements.

= 2.5.12 =
Adds guided Cloud and assistant setup, an always-available Dashboard, website-action breakdowns, custom content definitions, stock-image search, safe SVG imports.

= 2.5.11 =
Expands optional MCP website operations, image workflows, supported WP-CLI commands and Premium theme tools, with improved approvals and recovery.

= 2.4.99 =
Adds optional MCP.

= 2.4.98 =
Fixes Cloud email-verification return links and GPT-6.1 Sol requests rejected for unsupported Temperature and Top P settings.

= 2.4.97 =
Redesigned Settings, improved app connections and delivery logs, safer backups and visitor blocking, and fixes for AI Forms and Qdrant knowledge bases.

= 2.4.96 =
Redesigned chatbot settings and navigation, with improved knowledge-source management, audio controls and settings reliability.

= 2.4.95 =
Improves AI Puffer Cloud reconnection after reinstalling and preserves clearer connection diagnostics for support.

= 2.4.94 =
Fixes Content Writer settings reverting, provider model refresh issues, image defaults and history dimensions, and AI Forms feedback. Updates the feature and credit descriptions.

== Changelog ==

= 2.5.16 =

- Added permission-checked cache settings inspection and updates for WP-Optimize, Breeze, SpeedyCache, WP Fastest Cache and LiteSpeed Cache, with conflict checks and native configuration verification.
- Added scoped AIOSEO search-appearance and Easy Digital Downloads store settings, preserving unrelated configuration and native write opt-ins.
- Allowed supported plugin settings changes under the selected connection safety level while retaining native permissions, protected settings and approval checks.
- Improved native Elementor Atomic composition, page settings and shared styles; preserved omitted settings and pending edits, refreshed published output, and retained evidence when a batch partly fails.
- Fixed busy Elementor saves being reported as unknown outcomes when no change had started.
- Kept active, inactive and network-active plugins in their existing state after supported plugin updates.
- Improved operation-result recovery and diagnostics without repeating website actions.
- Fixed aggregate reads on custom database tables without a single-column primary key.
- Validated late-registered REST post fields before saving, including ACF values.
- Kept reserved WordPress settings route names protected when registering custom content types.
- Clarified supported command examples, free monthly website actions in pricing, and onboarding recipe wording.

= 2.5.15 =

- Updated the Markdown renderer to prevent slow parsing of malformed link references and inline HTML.
- Added simple-product sale scheduling, inspection and cancellation with site-timezone validation and stale-update protection.
- Added permission-checked post/page revision inspection and restoration, with conflict checks, recovery revisions and preserved publication status and metadata.
- Added version-checked Gutenberg inline edits and separate draft copies that preserve existing blocks, attributes, links and formatting, including safe summary and FAQ insertion.
- Added scoped assistant abilities to inspect/create undeployed chatbots, update instructions/model/greetings/starters, attach existing knowledge, and run real private tests under existing permissions and approval policies.
- Improved bounded database reads on large sites by narrowing source snapshots before size checks, while retaining permissions, credential redaction and accurate aggregate limits.
- Clarified Lighthouse audit inputs and added safe field-level validation diagnostics without exposing submitted values or changing audit protections.
- Preserved Cloud connections during temporary account-identity failures, blocked AI and assistant credential use until recovery, kept Disconnect available, and prevented Connect from reporting success after its credential was removed or replaced.
- Corrected temporary-unavailable connection messages so they do not request email verification.
- Kept assistant capability reporting recoverable during identity outages, with accurate Website management status and local Pause/Disconnect controls.
- Safely verified and upgraded older saved Cloud connections before allowing credential use.
- Protected model synchronization and credential cleanup against concurrent removal or replacement.
- Corrected failed Disconnect messages and attempted server revocation during uninstall even when account identity is temporarily unavailable.
- Kept the website setup invitation visible after disconnecting and reloading, with retained website-action history below it.
- Kept website setup over the current workspace to preserve unfinished work, corrected setup permission wording, retained local controls during Cloud outages, and aligned approval counts and reconnect warnings.
- Clarified that settings backups include Knowledge Base search, chunking, batch and visibility settings but exclude Content rules and stored sources and documents.

= 2.5.14 =

- Fixed legitimate REST query parameters, ordinary bracketed text and JavaScript prose being rejected as unsupported content, while retaining protections for scripts, executable URLs, registered shortcodes and protected metadata.
- Improved Gutenberg layout validation, including nested and encoded input, and prevented plain-content updates from accidentally replacing existing block documents.
- Added explicit confirmation and version checks for whole-layout replacement, plus complete paged reads of large block documents.
- Added specific refusal reasons and safe field locations to website-action results and Activity, with clearer guidance for supported Gutenberg operations.
- Added expected and actual counts to search-replace and matching-row conflicts, with guidance to refresh the dry run before making changes.
- Improved compatibility with hosted callback queue and diagnostic updates. Existing website permissions, approvals and action accounting remain in place.

= 2.5.13 =

MCP improvements:

- Added one-shot verification for images uploaded through the WordPress handoff, with user/site-bound upload windows and no automatic selection of ambiguous matches.
- Added read-only Lighthouse page audits (Beta) through Google PageSpeed Insights, with mobile or desktop scores and findings.
- Improved website ability diagnostics: preserve specific known failure codes and browser timeout phases.
- Clarified when an earlier uncertain change blocks a new write, with the original operation and review link; reads remain available and duplicate-change safeguards stay in place.
- Improved authenticated responses when assistant access is paused or disconnected, without trusting unsigned responses or hiding uncertain changes.
- Added Cursor and Claude Code connection instructions to website management setup in onboarding and Settings.
- Added reusable workflows through chat: save, find, read, update and remove your own instructions on the connected WordPress site. Use them across assistants connected as the same WordPress user; existing permissions and approvals still apply.

= 2.5.12 =
- Added guided onboarding for chatbots, website management, AI forms and content, with email verification, connection checks and a first assistant request.
- Made Dashboard the default landing page, with account management, separate AI credit and website action balances, and a combined activity timeline.
- Simplified ChatGPT and Claude setup instructions and assistant management while preserving existing connections and permissions.
- Improved Cloud connection diagnostics, email-change confirmation and recovery messages.
- Fixed stale knowledge-source failure warnings, added dismissal with access to failed sources, and preserved previews and clearer OpenAI upload diagnostics.
- Added sanitized SVG imports.
- Added keyless stock-image search with source, creator and licence attribution through connected assistants.
- Added assistant-driven registration of custom post types and typed custom fields.
- Added a website-action usage breakdown by operation category, with date filters and separate AI credit balances.

= 2.5.11 =

- Expanded MCP website management with richer site information, post/page editing and scheduling, media uploads and image details.
- Added image understanding and missing-only bulk alt text, captions and titles while preserving existing text.
- Added Gutenberg layouts, Elementor document editing, Yoast SEO fields, sitemaps, redirects and public-page inspection through supported website tools.
- Updated Trusted approvals, interrupted-change recovery, activity reporting and assistant guidance.
