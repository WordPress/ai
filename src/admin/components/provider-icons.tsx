/**
 * External dependencies
 */
import type { ComponentType, SVGProps } from 'react';

/**
 * Internal dependencies
 */
import {
	AiIcon,
	AnthropicIcon,
	CloudflareIcon,
	DeepSeekIcon,
	FalIcon,
	GoogleIcon,
	GrokIcon,
	GroqIcon,
	HuggingFaceIcon,
	OllamaIcon,
	OpenAiIcon,
	OpenRouterIcon,
	XaiIcon,
} from './icons';

const ICON_COMPONENTS: Record<
	string,
	ComponentType< SVGProps< SVGSVGElement > >
> = Object.freeze( {
	anthropic: AnthropicIcon,
	openai: OpenAiIcon,
	google: GoogleIcon,
	fal: FalIcon,
	'fal-ai': FalIcon,
	deepseek: DeepSeekIcon,
	cloudflare: CloudflareIcon,
	huggingface: HuggingFaceIcon,
	ollama: OllamaIcon,
	openrouter: OpenRouterIcon,
	groq: GroqIcon,
	grok: GrokIcon,
	xai: XaiIcon,
	default: AiIcon,
} );

export const getProviderIconComponent = (
	iconKey?: string,
	fallbackKey?: string
): ComponentType< SVGProps< SVGSVGElement > > => {
	const normalized = ( iconKey || fallbackKey || '' )
		.toLowerCase()
		.replace( /\s+/g, '' );

	const normalizedIcon = ICON_COMPONENTS[ normalized ];
	const originalIcon = iconKey ? ICON_COMPONENTS[ iconKey ] : undefined;
	const fallbackIcon = fallbackKey
		? ICON_COMPONENTS[ fallbackKey ]
		: undefined;
	const defaultIcon = AiIcon;

	return normalizedIcon || originalIcon || fallbackIcon || defaultIcon;
};
