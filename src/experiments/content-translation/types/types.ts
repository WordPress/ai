export type AIContentTranslationData = {
	enabled: boolean;
	minContentLength: number;
	languages: Array< {
		code: string;
		name: string;
	} >;
};
