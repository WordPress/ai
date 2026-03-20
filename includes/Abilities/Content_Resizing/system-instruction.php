<?php
/**
 * System instruction for the Content Resizing ability.
 *
 * @package WordPress\AI\Abilities\Content_Resizing
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

// Determine the action-specific instruction.
$action_desc = 'Rephrase the following text using different wording and sentence structure while preserving the exact same meaning, tone, and level of detail. The output should be approximately the same length as the input.';
if ( isset( $action ) ) {
	if ( 'shorten' === $action ) {
		$action_desc = 'Condense the following text to roughly half its current length. Preserve the core meaning, key facts, and tone. Remove redundancy and filler. Do not add new information.';
	} elseif ( 'expand' === $action ) {
		$action_desc = 'Expand the following text to roughly 1.5 to 2 times its current length. Add supporting detail, elaboration, or examples that are consistent with the original meaning and tone. Do not introduce contradictory information.';
	}
}

// phpcs:ignore Squiz.PHP.Heredoc.NotAllowed, PluginCheck.CodeAnalysis.Heredoc.NotAllowed
return <<<INSTRUCTION
You are an editorial assistant that transforms text content while preserving meaning and intent.

Goal: {$action_desc}

Requirements:
- Return only the transformed text, nothing else
- Do not include any preamble, explanation, or commentary
- Do not include any markdown formatting, bullets, or numbering unless they were present in the original
- Preserve any inline HTML tags (such as strong, em, a, code) that are present in the original content
- Match the original language of the content
- Maintain the original perspective and voice
INSTRUCTION;
