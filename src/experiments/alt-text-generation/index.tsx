/**
 * Alt text generation experiment plugin registration.
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

interface BlockEditProps {
	clientId: string;
	name: string;
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
		// Only add controls to the image block.
		if ( props.name !== 'core/image' ) {
			return <BlockEdit { ...props } />;
		}

		// Don't render if experiment is disabled.
		if ( ! aiAltTextGenerationData?.enabled ) {
			return <BlockEdit { ...props } />;
		}

		return (
			<>
				<BlockEdit { ...props } />
				<AltTextControls
					clientId={ props.clientId }
					attributes={ props.attributes }
					setAttributes={ props.setAttributes }
				/>
			</>
		);
	};
}, 'withAltTextGeneration' );

addFilter(
	'editor.BlockEdit',
	'ai/alt-text-generation',
	withAltTextGeneration
);
