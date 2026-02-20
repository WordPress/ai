/**
 * Inline image generation.
 *
 * Registers block filters that add a "Generate Image" toolbar
 * button and inline button to supported core blocks. Clicking the
 * button opens a modal where the user can generate an image, preview
 * it, edit it, and insert it into the block with a single click.
 */

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { dispatch } from '@wordpress/data';
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import {
	BlockControls,
	store as blockEditorStore,
	useBlockProps,
} from '@wordpress/block-editor';
import { Button, ToolbarGroup, ToolbarButton } from '@wordpress/components';
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
const withGenerateImageToolbarButton = createHigherOrderComponent(
	( BlockEdit ) => {
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
	},
	'withGenerateImageToolbarButton'
);

/**
 * Higher-order component that wraps the MediaUpload component for targeted
 * blocks and injects the inline button + modal.
 */
const withGenerateImageInlineButton = createHigherOrderComponent(
	( Component ) => {
		// Only run when the experiment is enabled.
		if ( ! aiImageGenerationData?.enabled ) {
			return Component;
		}

		return ( props: any ) => {
			const [ isModalOpen, setModalOpen ] = useState( false );
			const { render, ...rest } = props;
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

			return (
				<>
					<Component
						{ ...rest }
						mode="generate"
						render={ () => (
							<Button
								variant="secondary"
								onClick={ () => setModalOpen( true ) }
								__next40pxDefaultSize
							>
								{ __( 'Generate Image', 'ai' ) }
							</Button>
						) }
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
	'editor.BlockEdit',
	'ai/image-generation-inline-toolbar',
	withGenerateImageToolbarButton
);

addFilter(
	'editor.MediaUpload',
	'ai/image-generation-inline-button',
	withGenerateImageInlineButton
);
