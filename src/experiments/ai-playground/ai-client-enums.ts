/**
 * This is a copy of the `src/enums.ts` file of the WordPress AI Client.
 *
 * This is TEMPORARY. Eventually it should come from that package directly.
 */

export const FileType = {
	INLINE: 'inline',
	REMOTE: 'remote',
} as const;
export type FileType = ( typeof FileType )[ keyof typeof FileType ];

export const MediaOrientation = {
	SQUARE: 'square',
	LANDSCAPE: 'landscape',
	PORTRAIT: 'portrait',
} as const;
export type MediaOrientation =
	( typeof MediaOrientation )[ keyof typeof MediaOrientation ];

export const FinishReason = {
	STOP: 'stop',
	LENGTH: 'length',
	CONTENT_FILTER: 'content_filter',
	TOOL_CALLS: 'tool_calls',
	ERROR: 'error',
} as const;
export type FinishReason = ( typeof FinishReason )[ keyof typeof FinishReason ];

export const OperationState = {
	STARTING: 'starting',
	PROCESSING: 'processing',
	SUCCEEDED: 'succeeded',
	FAILED: 'failed',
	CANCELED: 'canceled',
} as const;
export type OperationState =
	( typeof OperationState )[ keyof typeof OperationState ];

export const ToolType = {
	FUNCTION_DECLARATIONS: 'function_declarations',
	WEB_SEARCH: 'web_search',
} as const;
export type ToolType = ( typeof ToolType )[ keyof typeof ToolType ];

export const ProviderType = {
	CLOUD: 'cloud',
	SERVER: 'server',
	CLIENT: 'client',
} as const;
export type ProviderType = ( typeof ProviderType )[ keyof typeof ProviderType ];

export const MessagePartType = {
	TEXT: 'text',
	FILE: 'file',
	FUNCTION_CALL: 'function_call',
	FUNCTION_RESPONSE: 'function_response',
} as const;
export type MessagePartType =
	( typeof MessagePartType )[ keyof typeof MessagePartType ];

export const MessagePartChannel = {
	CONTENT: 'content',
	THOUGHT: 'thought',
} as const;
export type MessagePartChannel =
	( typeof MessagePartChannel )[ keyof typeof MessagePartChannel ];

export const Modality = {
	TEXT: 'text',
	DOCUMENT: 'document',
	IMAGE: 'image',
	AUDIO: 'audio',
	VIDEO: 'video',
} as const;
export type Modality = ( typeof Modality )[ keyof typeof Modality ];

export const MessageRole = {
	USER: 'user',
	MODEL: 'model',
} as const;
export type MessageRole = ( typeof MessageRole )[ keyof typeof MessageRole ];

export const Capability = {
	TEXT_GENERATION: 'text_generation',
	IMAGE_GENERATION: 'image_generation',
	TEXT_TO_SPEECH_CONVERSION: 'text_to_speech_conversion',
	SPEECH_GENERATION: 'speech_generation',
	MUSIC_GENERATION: 'music_generation',
	VIDEO_GENERATION: 'video_generation',
	EMBEDDING_GENERATION: 'embedding_generation',
	CHAT_HISTORY: 'chat_history',
} as const;
export type Capability = ( typeof Capability )[ keyof typeof Capability ];
