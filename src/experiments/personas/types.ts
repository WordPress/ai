/**
 * Types for the personas experiment.
 */

export type PersonaOption = {
	value: string;
	label: string;
};

export type PersonasData = {
	enabled: boolean;
	metaKey: string;
	personas: PersonaOption[];
	defaultPersona: string;
	manageUrl: string;
};
