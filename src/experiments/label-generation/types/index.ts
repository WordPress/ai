export type AILabelGenerationData = {
	enabled: boolean;
	minContentLength: number;
};

declare global {
	interface Window {
		aiLabelGenerationData: AILabelGenerationData;
	}
}

export interface BlockAttributes {
	content?: string;
	value?: string;
	alt?: string;
	caption?: string;
	metadata?: {
		name?: string;
		[ key: string ]: unknown;
	};
	[ key: string ]: unknown;
}

export interface Block {
	clientId: string;
	name: string;
	attributes: BlockAttributes;
	innerBlocks: Block[];
}
