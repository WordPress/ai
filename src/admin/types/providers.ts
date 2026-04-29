export interface ProviderMetadata {
	id: string;
	name: string;
	type: string;
	logo?: string;
	url?: string;
}

export type ProviderMetadataMap = Record< string, ProviderMetadata >;
