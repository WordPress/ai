/**
 * Internal dependencies
 */
import type { BlockWithClientId } from '../../../utils/blocks';

export type AIBlockContainerLabelGenerationData = {
	enabled: boolean;
	minContentLength: number;
};

declare global {
	interface Window {
		aiBlockContainerLabelGenerationData?: AIBlockContainerLabelGenerationData;
	}
}

export interface Block extends BlockWithClientId {
	attributes: BlockWithClientId[ 'attributes' ] & {
		metadata?: {
			name?: string;
			[ key: string ]: unknown;
		};
	};
	innerBlocks: Block[];
}
