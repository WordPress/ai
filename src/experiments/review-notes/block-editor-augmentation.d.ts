/**
 * Module augmentation to declare BlockSettingsMenuControls, which is exported
 * from the @wordpress/block-editor JS bundle but missing from its type
 * declarations.
 */

import type { ReactNode } from 'react';

declare module '@wordpress/block-editor' {
	interface BlockSettingsMenuControlsFillProps {
		selectedBlocks: string[];
		selectedClientIds: string[];
		onClose: () => void;
	}

	export const BlockSettingsMenuControls: (props: {
		children: ( fillProps: BlockSettingsMenuControlsFillProps ) => ReactNode;
	}) => ReactNode;
}
