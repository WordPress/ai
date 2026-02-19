/**
 * Inline image generation — block toolbar integration.
 *
 * Registers a block filter that adds a "Generate Image" toolbar button to
 * supported core blocks. Clicking the button opens a modal where the user
 * can generate an image, preview it, iterate on it, and insert it into the
 * block with a single click.
 */

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { BlockControls } from '@wordpress/block-editor';
import { ToolbarGroup, ToolbarButton } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { image } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { GenerateImageInlineModal } from './components/GenerateImageInlineModal';
import './index.scss';

const { aiImageGenerationData } = window as any;

const TARGET_BLOCKS = [
	'core/image',
	'core/cover',
	'core/media-text',
	'core/gallery',
];

/**
 * Higher-order component that wraps each block edit component for targeted
 * blocks and injects the toolbar button + modal.
 */
const withGenerateImageButton = createHigherOrderComponent( ( BlockEdit ) => {
	// Only wrap the block edit component when the experiment is enabled.
	if ( ! aiImageGenerationData?.enabled ) {
		return BlockEdit;
	}

	return ( props: any ) => {
		const [ isModalOpen, setModalOpen ] = useState( false );

		return (
			<>
				<BlockEdit { ...props } />
				{ TARGET_BLOCKS.includes( props.name ) && (
					<BlockControls>
						<ToolbarGroup>
							<ToolbarButton
								icon={ image }
								label={ __( 'Generate Image', 'ai' ) }
								onClick={ () => setModalOpen( true ) }
							/>
						</ToolbarGroup>
					</BlockControls>
				) }

				{ isModalOpen && (
					<GenerateImageInlineModal
						blockName={ props.name }
						clientId={ props.clientId }
						setAttributes={ props.setAttributes }
						onClose={ () => setModalOpen( false ) }
					/>
				) }
			</>
		);
	};
}, 'withGenerateImageButton' );

addFilter(
	'editor.BlockEdit',
	'ai/image-generation-inline',
	withGenerateImageButton
);
