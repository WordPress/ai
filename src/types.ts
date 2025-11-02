export type TogglePayload = {
	optionKey: string;
	restField: string;
	enabled: boolean;
	group: string;
	default: boolean;
};

export type FeatureTogglesPayload = {
	optionKey: string;
	restField: string;
	toggles: Record<string, boolean>;
};

export type SectionPayload = {
	id: string;
	title: string;
	description?: string;
	featureId?: string | null;
	priority: number;
	supports?: Record<string, unknown>;
	enabled: boolean;
};

export type SettingsPayload = {
	toggle: TogglePayload;
	featureToggles: FeatureTogglesPayload;
	sections: SectionPayload[];
};
