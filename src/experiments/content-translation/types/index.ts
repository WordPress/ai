export type AIContentTranslationData = {
	enabled: boolean;
	minContentLength: number;
	languages: Array< {
		code: string;
		name: string;
	} >;
};

export type ToolbarContext = {
	clientId: string;
	blockName: string;
	onClose: () => void;
};

declare global {
	interface Window {
		aiContentTranslationData: AIContentTranslationData;
	}
}
