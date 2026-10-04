<?php

namespace WPAICG\AutoGPT\Helpers;

use WPAICG\ContentWriter\AIPKit_Content_Writer_Prompts;

if (!defined('ABSPATH')) {
    exit;
}

class AIPKit_AutoGPT_Prompt_Definitions
{
    public static function get_content_enhancement_defaults(): array
    {
        return [
            'title' => 'You are an expert SEO copywriter. Generate the single best and most compelling SEO title based on the provided information. The title must:
- Be under 60 characters
- Start with the main focus keyword
- Include at least one power word (e.g., Stunning, Must-Have, Exclusive)
- Include a positive or negative sentiment word (e.g., Best, Effortless, Affordable)

Return ONLY the new title text. Do not include any introduction, explanation, or quotation marks.

Original title: "{original_title}"
Post content snippet: "{original_content}"
Focus keyword: "{original_focus_keyword}"',
            'excerpt' => 'Rewrite the post excerpt to be more compelling and engaging based on the information provided. Use a friendly tone and aim for 1-2 concise sentences. Return ONLY the new excerpt without any explanation or formatting.

Post title: "{original_title}"
Post content snippet: "{original_content}"',
            'content' => 'You are an expert editor. Rewrite and improve the following article to make it more engaging, clear, and informative. Maintain the original tone and intent, but enhance the writing quality. Ensure the following:
- The revised content is at least 600 words long
- The focus keyword appears in one or more subheadings (H2 or H3)
- The focus keyword is used naturally throughout the article, especially in the introduction and conclusion

The article title is: {original_title}
Focus keyword: {original_focus_keyword}

Original Content:
{original_content}',
            'meta' => 'Generate a single, concise, and SEO-friendly meta description (120-156 characters) for a web page based on the provided information. The description must:
- Begin with or include the focus keyword near the start
- Use an active voice
- Include a clear call-to-action

Return ONLY the new meta description without any introduction or formatting.

Page title: "{original_title}"
Page content snippet: "{original_content}"
Focus keyword: "{original_focus_keyword}"',
        ];
    }

    public static function get_content_enhancement_prompt_items(): array
    {
        $defaults = self::get_content_enhancement_defaults();
        $prompt_library = class_exists(AIPKit_Content_Writer_Prompts::class)
            ? AIPKit_Content_Writer_Prompts::get_prompt_library()
            : [];

        $base_placeholders = [
            '{original_title}',
            '{original_content}',
            '{original_excerpt}',
            '{original_tags}',
            '{categories}',
        ];
        $meta_placeholders = [
            '{original_title}',
            '{original_content}',
            '{original_meta_description}',
            '{original_tags}',
            '{categories}',
        ];
        $focus_placeholders = [
            '{original_title}',
            '{original_content}',
            '{original_excerpt}',
            '{original_tags}',
            '{categories}',
            '{original_focus_keyword}',
        ];
        $product_placeholders = [
            '{price}',
            '{regular_price}',
            '{sku}',
            '{attributes}',
            '{stock_quantity}',
            '{stock_status}',
            '{weight}',
            '{length}',
            '{width}',
            '{height}',
            '{purchase_note}',
            '{product_categories}',
        ];

        return [
            [
                'key' => 'title',
                'label' => __('Title', 'gpt3-ai-content-generator'),
                'toggle' => [
                    'id' => 'aipkit_task_ce_update_title',
                    'name' => 'ce_update_title',
                ],
                'textarea' => [
                    'id' => 'aipkit_task_ce_title_prompt',
                    'name' => 'ce_title_prompt',
                    'value' => $defaults['title'],
                    'placeholder' => __('Enter your title prompt...', 'gpt3-ai-content-generator'),
                ],
                'library' => [
                    'select_id' => 'aipkit_task_ce_title_prompt_library',
                    'options' => $prompt_library['title'] ?? [],
                    'default_prompt' => $defaults['title'],
                ],
                'placeholders' => $focus_placeholders,
                'placeholders_prompt_type' => 'title',
                'placeholders_extra' => $product_placeholders,
                'placeholders_extra_label' => __('For products:', 'gpt3-ai-content-generator'),
            ],
            [
                'key' => 'content',
                'label' => __('Content', 'gpt3-ai-content-generator'),
                'toggle' => [
                    'id' => 'aipkit_task_ce_update_content',
                    'name' => 'ce_update_content',
                ],
                'textarea' => [
                    'id' => 'aipkit_task_ce_content_prompt',
                    'name' => 'ce_content_prompt',
                    'value' => $defaults['content'],
                    'placeholder' => __('Enter your content prompt...', 'gpt3-ai-content-generator'),
                ],
                'library' => [
                    'select_id' => 'aipkit_task_ce_content_prompt_library',
                    'options' => $prompt_library['content'] ?? [],
                    'default_prompt' => $defaults['content'],
                ],
                'placeholders' => $focus_placeholders,
                'placeholders_prompt_type' => 'content',
                'placeholders_extra' => $product_placeholders,
                'placeholders_extra_label' => __('For products:', 'gpt3-ai-content-generator'),
            ],
            [
                'key' => 'excerpt',
                'label' => __('Excerpt', 'gpt3-ai-content-generator'),
                'toggle' => [
                    'id' => 'aipkit_task_ce_update_excerpt',
                    'name' => 'ce_update_excerpt',
                ],
                'textarea' => [
                    'id' => 'aipkit_task_ce_excerpt_prompt',
                    'name' => 'ce_excerpt_prompt',
                    'value' => $defaults['excerpt'],
                    'placeholder' => __('Enter your excerpt prompt...', 'gpt3-ai-content-generator'),
                ],
                'library' => [
                    'select_id' => 'aipkit_task_ce_excerpt_prompt_library',
                    'options' => $prompt_library['excerpt'] ?? [],
                    'default_prompt' => $defaults['excerpt'],
                ],
                'placeholders' => $base_placeholders,
                'placeholders_prompt_type' => 'excerpt',
                'placeholders_extra' => $product_placeholders,
                'placeholders_extra_label' => __('For products:', 'gpt3-ai-content-generator'),
            ],
            [
                'key' => 'meta',
                'label' => __('Meta Description', 'gpt3-ai-content-generator'),
                'toggle' => [
                    'id' => 'aipkit_task_ce_update_meta',
                    'name' => 'ce_update_meta',
                ],
                'textarea' => [
                    'id' => 'aipkit_task_ce_meta_prompt',
                    'name' => 'ce_meta_prompt',
                    'value' => $defaults['meta'],
                    'placeholder' => __('Enter your meta description prompt...', 'gpt3-ai-content-generator'),
                ],
                'library' => [
                    'select_id' => 'aipkit_task_ce_meta_prompt_library',
                    'options' => $prompt_library['meta'] ?? [],
                    'default_prompt' => $defaults['meta'],
                ],
                'placeholders' => $meta_placeholders,
                'placeholders_prompt_type' => 'meta',
                'placeholders_extra' => $product_placeholders,
                'placeholders_extra_label' => __('For products:', 'gpt3-ai-content-generator'),
            ],
        ];
    }

    public static function get_comment_reply_defaults(): array
    {
        return [
            'reply' => 'Write a helpful and friendly reply to this comment on my blog post titled \'{post_title}\'.

Comment: {comment_content}',
        ];
    }

    public static function get_comment_reply_prompt_items(): array
    {
        $defaults = self::get_comment_reply_defaults();
        $prompt_library = class_exists(AIPKit_Content_Writer_Prompts::class)
            ? AIPKit_Content_Writer_Prompts::get_prompt_library()
            : [];

        return [
            [
                'key' => 'reply',
                'label' => __('Reply Prompt', 'gpt3-ai-content-generator'),
                'textarea' => [
                    'id' => 'aipkit_task_cc_custom_content_prompt',
                    'name' => 'cc_custom_content_prompt',
                    'value' => $defaults['reply'],
                    'placeholder' => __('Enter your reply prompt...', 'gpt3-ai-content-generator'),
                ],
                'library' => [
                    'select_id' => 'aipkit_task_cc_reply_prompt_library',
                    'options' => $prompt_library['reply'] ?? [],
                    'default_prompt' => $defaults['reply'],
                ],
                'placeholders' => [
                    '{comment_content}',
                    '{comment_author}',
                    '{post_title}',
                ],
                'placeholders_prompt_type' => 'reply',
            ],
        ];
    }

    public static function get_post_enhancer_defaults(): array
    {
        $defaults = self::get_content_enhancement_defaults();

        $defaults['keyword'] = 'You are an SEO expert. Your task is to identify the single most important and relevant focus keyphrase for the following article. The keyphrase should be concise (ideally 2-4 words) and must be present within the provided content.

Return ONLY the keyphrase. Do not add any explanation, labels, or quotation marks.

Article Title: "{original_title}"
Article Content:
{original_content}';

        $defaults['tags'] = 'You are an SEO expert. Generate a list of 5-10 relevant tags for a blog post titled "{original_title}". Return ONLY a comma-separated list of the tags. Do not include any introduction, explanation, or numbering.

Article Content Snippet:
{original_content}';

        return $defaults;
    }

    public static function get_post_enhancer_prompt_items(bool $is_product = false): array
    {
        $defaults = self::get_post_enhancer_defaults();
        $prompt_library = class_exists(AIPKit_Content_Writer_Prompts::class)
            ? AIPKit_Content_Writer_Prompts::get_prompt_library()
            : [];

        $base_placeholders = [
            '{original_title}',
            '{original_content}',
            '{original_excerpt}',
            '{original_focus_keyword}',
            '{original_tags}',
            '{categories}',
        ];
        $meta_placeholders = [
            '{original_title}',
            '{original_content}',
            '{original_focus_keyword}',
            '{original_meta_description}',
            '{original_tags}',
            '{categories}',
        ];
        $product_placeholders = $is_product
            ? [
                '{price}',
                '{regular_price}',
                '{sku}',
                '{attributes}',
                '{stock_quantity}',
                '{stock_status}',
                '{weight}',
                '{length}',
                '{width}',
                '{height}',
                '{purchase_note}',
                '{product_categories}',
            ]
            : [];

        $build_item = static function (
            string $key,
            string $label,
            string $description,
            string $flyout_title,
            string $prompt,
            array $library_options,
            array $placeholders,
            int $rows = 8
        ) use ($product_placeholders): array {
            return [
                'key' => $key,
                'label' => $label,
                'description' => $description,
                'toggle' => [
                    'id' => sprintf('aipkit_bulk_enhance_%s', $key),
                    'name' => 'bulk_enhance_fields',
                    'value' => $key,
                ],
                'flyout_id' => sprintf('aipkit_bulk_%s_prompt_flyout', $key),
                'flyout_title' => $flyout_title,
                'textarea' => [
                    'id' => sprintf('aipkit_bulk_prompt_%s', $key),
                    'value' => $prompt,
                    'rows' => $rows,
                ],
                'library' => [
                    'select_id' => sprintf('aipkit_bulk_%s_prompt_library', $key),
                    'options' => $library_options,
                    'default_prompt' => $prompt,
                    'default_label' => __('Default', 'gpt3-ai-content-generator'),
                ],
                'placeholders' => $placeholders,
                'placeholders_extra' => $product_placeholders,
                'placeholders_extra_label' => __('For products:', 'gpt3-ai-content-generator'),
                'placeholders_prompt_type' => $key,
            ];
        };

        return [
            $build_item(
                'title',
                __('Title', 'gpt3-ai-content-generator'),
                __('Post title', 'gpt3-ai-content-generator'),
                __('Title Prompt', 'gpt3-ai-content-generator'),
                $defaults['title'] ?? '',
                $prompt_library['title'] ?? [],
                $base_placeholders
            ),
            $build_item(
                'content',
                __('Content', 'gpt3-ai-content-generator'),
                __('Rewrite the main body', 'gpt3-ai-content-generator'),
                __('Content Prompt', 'gpt3-ai-content-generator'),
                $defaults['content'] ?? '',
                $prompt_library['content'] ?? [],
                $base_placeholders,
                10
            ),
            $build_item(
                'meta',
                __('Meta Description', 'gpt3-ai-content-generator'),
                __('SEO snippet for search', 'gpt3-ai-content-generator'),
                __('Meta Description Prompt', 'gpt3-ai-content-generator'),
                $defaults['meta'] ?? '',
                $prompt_library['meta'] ?? [],
                $meta_placeholders
            ),
            $build_item(
                'keyword',
                __('Focus Keyword', 'gpt3-ai-content-generator'),
                __('Primary SEO keyword', 'gpt3-ai-content-generator'),
                __('Focus Keyword Prompt', 'gpt3-ai-content-generator'),
                $defaults['keyword'] ?? '',
                $prompt_library['keyword'] ?? [],
                $base_placeholders
            ),
            $build_item(
                'excerpt',
                __('Excerpt', 'gpt3-ai-content-generator'),
                __('Short summary text', 'gpt3-ai-content-generator'),
                __('Excerpt Prompt', 'gpt3-ai-content-generator'),
                $defaults['excerpt'] ?? '',
                $prompt_library['excerpt'] ?? [],
                $base_placeholders
            ),
            $build_item(
                'tags',
                __('Tags', 'gpt3-ai-content-generator'),
                __('Suggested tags list', 'gpt3-ai-content-generator'),
                __('Tags Prompt', 'gpt3-ai-content-generator'),
                $defaults['tags'] ?? '',
                $prompt_library['tags'] ?? [],
                $base_placeholders
            ),
        ];
    }
}
