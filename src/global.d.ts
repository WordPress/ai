import type { SettingsPayload } from './types';

declare global {
	interface Window {
		wpAiExperimentsSettings?: SettingsPayload;
	}
}

export {};
