<?php

declare( strict_types=1 );

use WordPress\AiClient\AiClient;

if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
	/**
	 * Creates a new AI prompt builder using the default provider registry.
	 *
	 * This is the main entry point for generating AI content in WordPress. It returns
	 * a fluent builder that can be used to configure and execute AI prompts.
	 *
	 * The prompt can be provided as a simple string for basic text prompts, or as more
	 * complex types for advanced use cases like multi-modal content or conversation history.
	 *
	 * @since x.x.x
	 *
	 * @param string|MessagePart|Message|array|list<string|MessagePart|array>|list<Message>|null $prompt Optional. Initial prompt content.
	 *                                                                                                   A string for simple text prompts,
	 *                                                                                                   a MessagePart or Message object for
	 *                                                                                                   structured content, an array for a
	 *                                                                                                   message array shape, or a list of
	 *                                                                                                   parts or messages for multi-turn
	 *                                                                                                   conversations. Default null.
	 * @return \WordPress\AI_Client\Builders\Prompt_Builder_With_WP_Error|WP_AI_Client_Prompt_Builder The prompt builder instance.
	 */
	function wp_ai_client_prompt( $prompt = null ) {
		if ( ! class_exists( 'WP_AI_Client_Prompt_Builder' ) ) {
			return new \WordPress\AI_Client\Builders\Prompt_Builder_With_WP_Error( AiClient::defaultRegistry(), $prompt );
		}

		return new WP_AI_Client_Prompt_Builder( AiClient::defaultRegistry(), $prompt );
	}
}
