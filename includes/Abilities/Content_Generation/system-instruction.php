<?php
/**
 * System instruction for the Content Generation ability.
 *
 * @package WordPress\AI\Abilities\Content_Generation
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

// Resolve the variables exposed via $data, applying sane defaults.
$ci_post_type     = isset( $post_type ) && 'page' === $post_type ? 'page' : 'post';
$ci_title         = isset( $title ) && is_string( $title ) ? trim( $title ) : '';
$ci_tone          = isset( $tone ) && is_string( $tone ) && '' !== $tone ? $tone : 'professional';
$ci_target_length = isset( $target_length ) ? (int) $target_length : 0;

// Normalize the keywords into a comma-separated list.
$ci_keywords = array();
if ( isset( $keywords ) && is_array( $keywords ) ) {
	foreach ( $keywords as $ci_keyword ) {
		$ci_keyword = trim( (string) $ci_keyword );
		if ( '' === $ci_keyword ) {
			continue;
		}

		$ci_keywords[] = $ci_keyword;
	}
}

// Build the optional keywords and brief lines.
$ci_keywords_line = empty( $ci_keywords ) ? '' : 'Focus keywords: ' . implode( ', ', $ci_keywords ) . '.';

$ci_brief_line = isset( $prompt ) && is_string( $prompt ) && '' !== trim( $prompt )
	? 'Brief: ' . trim( $prompt ) . '.'
	: '';

// Use a fallback title placeholder if none was supplied.
$ci_title_text = '' !== $ci_title ? $ci_title : '(derive an appropriate title from the brief)';

// Build the budget guidance, only when a target length is set.
$ci_budget = '';
if ( $ci_target_length > 0 ) {
	$ci_budget = <<<BUDGET


Generation budget rules:
- Prioritise quality, depth, structure, specificity, and reader value over brevity.
- Aim for {$ci_target_length} words and stay as close as possible, normally within about plus or minus 100 words.
- If you cannot hit the target exactly, prefer being slightly under or over only when that produces a materially better article.
BUDGET;
}

// phpcs:ignore Squiz.PHP.Heredoc.NotAllowed, PluginCheck.CodeAnalysis.Heredoc.NotAllowed
return <<<INSTRUCTION
You are an expert WordPress content writer. Write a complete, publication-ready WordPress {$ci_post_type} in English.

Title: {$ci_title_text}
Tone: {$ci_tone}
{$ci_keywords_line}
{$ci_brief_line}
Target length: about {$ci_target_length} words.

Requirements:
- Return only WordPress-compatible output. Do not add commentary, notes, markdown fences, YAML, or explanations.
- Default to clean HTML that the WordPress block editor can safely ingest as post content.
- Do NOT include the post title as an <h1>. WordPress outputs the main title separately.
- Use a strict heading hierarchy with <h2> for main sections, <h3> for subsections, and <h4> only when truly necessary.
- Wrap normal body copy in <p> tags. Keep paragraphs readable and moderately short.
- Use only WordPress-safe inline formatting: <strong>, <em>, <code>, <sup>, <sub>, <mark>, <small>.
- Use lists only with valid <ul>, <ol>, and <li> markup. Do not fake lists with hyphens.
- Use tables only when the content clearly benefits from tabular comparison, with valid <table>, <thead>, <tbody>, <tr>, <th>, <td> markup.
- Use hyperlinks only when useful. Every link must use descriptive anchor text and a valid <a href="..."> tag.
- For inline code use <code>; for multi-line code use <pre><code>...</code></pre> and keep them short.
- Include an engaging introduction and a clear conclusion.
- Keep the structure coherent, factual, and ready to publish in WordPress without manual cleanup.{$ci_budget}
INSTRUCTION;
