/**
 * Alt text generation plugin registration.
 */

/**
 * WordPress dependencies
 */
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';

/**
 * Internal dependencies
 */
import { AltTextControls } from './components/AltTextControls';
import type { ImageBlockAttributes } from './types';
import { exposeToDevTools } from '../../utils/devtools';

exposeToDevTools( {
	name: 'Alt Text Generation',
	description: 'Generates descriptive alt text for image blocks using AI.',
	abilitySlug: 'ai/alt-text-generation',
} );

interface BlockEditProps {
	clientId: string;
	name: string;
	isSelected: boolean;
	attributes: ImageBlockAttributes;
	setAttributes: ( attributes: Partial< ImageBlockAttributes > ) => void;
}

interface AltTextGenerationData extends Window {
	aiAltTextGenerationData?: {
		enabled?: boolean;
	};
}

const { aiAltTextGenerationData } = window as AltTextGenerationData;

/**
 * Higher-order component that adds alt text generation controls to the image block.
 */
const withAltTextGeneration = createHigherOrderComponent( ( BlockEdit ) => {
	return ( props: BlockEditProps ) => {
		// Only show controls if we have a selected image block and the experiment is enabled.
		const showControls =
			props.name === 'core/image' &&
			props.isSelected &&
			aiAltTextGenerationData?.enabled;

		return (
			<>
				<BlockEdit { ...props } />
				{ showControls && (
					<AltTextControls
						clientId={ props.clientId }
						attributes={ props.attributes }
						setAttributes={ props.setAttributes }
					/>
				) }
			</>
		);
	};
}, 'withAltTextGeneration' );

addFilter(
	'editor.BlockEdit',
	'ai/alt-text-generation',
	withAltTextGeneration
);
