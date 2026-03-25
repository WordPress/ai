export interface ProviderModelMetadata {
	id: string;
	name: string;
	capabilities?: string[];
}

export interface ProviderMetadata {
	id: string;
	name: string;
	type: string;
	icon?: string;
	initials?: string;
	color?: string;
	url?: string;
	tooltip?: string;
	keepDescription?: boolean;
	isConfigured?: boolean;
	models?: ProviderModelMetadata[];
}

export type ProviderMetadataMap = Record< string, ProviderMetadata >;
