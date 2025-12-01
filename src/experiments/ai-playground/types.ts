/**
 * Internal dependencies
 */
import type { Capability, Modality } from './ai-client-enums';
import type { Message } from './ai-client-types';

// Stubs for the WordPress attachment type.
type WordPressAttachmentSizeData = {
	url: string;
	width: number;
	height: number;
};
export type WordPressAttachment = {
	id: number;
	url: string;
	mime: string;
	icon: string;
	sizes?: Record< string, WordPressAttachmentSizeData >;
	[ key: string ]: unknown;
};

export type AiPlaygroundMessage = {
	content: Message;
	type: 'user' | 'model' | 'error';
} & AiPlaygroundMessageAdditionalData;

export type AiPlaygroundMessageAdditionalData = {
	provider?: {
		id: string;
		name: string;
	};
	model?: {
		id: string;
		name: string;
	};
	capability?: Capability;
	attachment?: WordPressAttachment;
	attachments?: ( WordPressAttachment | null )[];
};

export type AiProviderOption = {
	identifier: string;
	label: string;
};

export type AiModelOption = {
	identifier: string;
	label: string;
};

export type CapabilityOption = {
	identifier: Capability;
	label: string;
};

export type OptionOption = {
	identifier: string;
	label: string;
};

export type ModalityOption = {
	identifier: Modality;
	label: string;
};
