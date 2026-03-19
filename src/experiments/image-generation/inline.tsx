/**
 * Inline image generation.
 *
 * Registers block filters that add a "Generate Image" toolbar
 * button and inline button to supported core blocks. Clicking the
 * button opens a modal where the user can generate an image, preview
 * it, refine it, and insert it into the block with a single click.
 */

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { dispatch } from '@wordpress/data';
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import {
	store as blockEditorStore,
	useBlockProps,
} from '@wordpress/block-editor';
import { Button, MenuItem } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { create } from '@wordpress/icons';

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
 * Higher-order component that wraps the MediaUpload component for targeted
 * blocks and injects the inline/toolbar button + modal.
 */
const withGenerateImageInlineButton = createHigherOrderComponent(
	( Component ) => {
		// Don't render if disabled.
		if ( ! aiImageGenerationData?.enabled ) {
			return Component;
		}

		return ( props: any ) => {
			const [ isModalOpen, setModalOpen ] = useState( false );
			const { render, mode, ...rest } = props;
			let blockProps;

			try {
				blockProps = useBlockProps();
			} catch ( e ) {
				return <Component { ...props } />;
			}

			const { 'data-type': blockName, 'data-block': blockClientId } =
				blockProps;

			if ( ! TARGET_BLOCKS.includes( blockName ) ) {
				return <Component { ...props } />;
			}

			const setAttributes = ( attrs: Record< string, unknown > ) =>
				( dispatch( blockEditorStore ) as any ).updateBlockAttributes(
					blockClientId,
					attrs
				);

			// MediaPlaceholder uses mode="browse" and expects Buttons. MediaReplaceFlow
			// (toolbar Add/Replace dropdown) uses no mode and expects MenuItems.
			const isToolbarContext = mode !== 'browse';

			return (
				<>
					<Component
						{ ...rest }
						mode="generate"
						render={ () =>
							isToolbarContext ? (
								<MenuItem
									icon={ create }
									onClick={ () => setModalOpen( true ) }
								>
									{ __( 'Generate Image', 'ai' ) }
								</MenuItem>
							) : (
								<Button
									variant="secondary"
									onClick={ () => setModalOpen( true ) }
									__next40pxDefaultSize
								>
									{ __( 'Generate Image', 'ai' ) }
								</Button>
							)
						}
					/>

					<Component { ...props } />

					{ isModalOpen && (
						<GenerateImageInlineModal
							blockName={ blockName }
							clientId={ blockClientId }
							setAttributes={ setAttributes }
							onClose={ () => setModalOpen( false ) }
						/>
					) }
				</>
			);
		};
	},
	'withGenerateImageInlineButton'
);

addFilter(
	'editor.MediaUpload',
	'ai/image-generation-inline-button',
	withGenerateImageInlineButton
);
