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
import {
	Children,
	Fragment,
	isValidElement,
	useState,
} from '@wordpress/element';
import { dispatch, useSelect } from '@wordpress/data';
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { store as blockEditorStore } from '@wordpress/block-editor';
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

type SelectedTargetBlock = {
	name: string;
	clientId: string;
};

/**
 * Flattens a React node tree to an array of children.
 *
 * @param {React.ReactNode} content The React node tree to flatten.
 * @return {React.ReactNode[]} An array of children.
 */
const flattenFragmentChildren = (
	content: React.ReactNode
): React.ReactNode[] =>
	Children.toArray( content ).flatMap( ( child ) => {
		if ( isValidElement( child ) && child.type === Fragment ) {
			return flattenFragmentChildren(
				( child.props as { children?: React.ReactNode } ).children
			);
		}

		return child;
	} );

/**
 * Inserts the inserted content after the first child of the content.
 *
 * @param {React.ReactNode} content         The content to insert the button after.
 * @param {React.ReactNode} insertedContent The content to insert.
 * @return {React.ReactNode} The content with the button inserted after the first child.
 */
const insertAfterUploadButton = (
	content: React.ReactNode,
	insertedContent: React.ReactNode
): React.ReactNode => {
	const children = flattenFragmentChildren( content );

	if ( children.length === 0 ) {
		return insertedContent;
	}

	const insertAfterIndex = children.length > 1 ? 1 : 0;

	return (
		<>
			{ children.slice( 0, insertAfterIndex + 1 ) }
			{ insertedContent }
			{ children.slice( insertAfterIndex + 1 ) }
		</>
	);
};

/**
 * Returns the selected target block.
 *
 * @return {SelectedTargetBlock|null} The selected target block.
 */
const useSelectedTargetBlock = (): SelectedTargetBlock | null =>
	useSelect( ( select ) => {
		const { getSelectedBlock } = select( blockEditorStore ) as any;
		const selectedBlock = getSelectedBlock();

		if (
			! selectedBlock ||
			! TARGET_BLOCKS.includes( selectedBlock.name )
		) {
			return null;
		}

		return {
			name: selectedBlock.name,
			clientId: selectedBlock.clientId,
		};
	}, [] );

/**
 * Higher-order component that wraps MediaPlaceholder for targeted blocks and
 * injects the inline button + modal.
 */
const withGenerateImageButton = createHigherOrderComponent( ( Component ) => {
	if ( ! aiImageGenerationData?.enabled ) {
		return Component;
	}

	return ( props: any ) => {
		const [ isModalOpen, setModalOpen ] = useState( false );
		const selectedBlock = useSelectedTargetBlock();

		if ( ! selectedBlock ) {
			return <Component { ...props } />;
		}

		const setAttributes = ( attrs: Record< string, unknown > ) =>
			( dispatch( blockEditorStore ) as any ).updateBlockAttributes(
				selectedBlock.clientId,
				attrs
			);

		const modal = isModalOpen && (
			<GenerateImageInlineModal
				blockName={ selectedBlock.name }
				clientId={ selectedBlock.clientId }
				setAttributes={ setAttributes }
				onClose={ () => setModalOpen( false ) }
			/>
		);

		const button = (
			<Button
				variant="secondary"
				onClick={ () => setModalOpen( true ) }
				__next40pxDefaultSize
			>
				{ __( 'Generate Image', 'ai' ) }
			</Button>
		);

		const { children, placeholder, ...rest } = props;

		if ( placeholder ) {
			return (
				<>
					<Component
						{ ...rest }
						placeholder={ ( content: React.ReactNode ) =>
							placeholder(
								insertAfterUploadButton( content, button )
							)
						}
					>
						{ children }
					</Component>
					{ modal }
				</>
			);
		}

		return (
			<>
				<Component { ...rest }>
					{ button }
					{ children }
				</Component>
				{ modal }
			</>
		);
	};
}, 'withGenerateImageButton' );

addFilter(
	'editor.MediaPlaceholder',
	'ai/image-generation-placeholder-button',
	withGenerateImageButton
);

const withGenerateImageReplaceFlowButton = createHigherOrderComponent(
	( Component ) => {
		if ( ! aiImageGenerationData?.enabled ) {
			return Component;
		}

		return ( props: any ) => {
			const [ isModalOpen, setModalOpen ] = useState( false );
			const selectedBlock = useSelectedTargetBlock();

			if ( ! selectedBlock ) {
				return <Component { ...props } />;
			}

			const { children, ...rest } = props;
			const setAttributes = ( attrs: Record< string, unknown > ) =>
				( dispatch( blockEditorStore ) as any ).updateBlockAttributes(
					selectedBlock.clientId,
					attrs
				);

			return (
				<>
					<Component { ...rest }>
						{ ( childProps: any ) => (
							<>
								<MenuItem
									icon={ create }
									onClick={ () => {
										childProps.onClose?.();
										setModalOpen( true );
									} }
								>
									{ __( 'Generate Image', 'ai' ) }
								</MenuItem>
								{ typeof children === 'function'
									? children( childProps )
									: children }
							</>
						) }
					</Component>

					{ isModalOpen && (
						<GenerateImageInlineModal
							blockName={ selectedBlock.name }
							clientId={ selectedBlock.clientId }
							setAttributes={ setAttributes }
							onClose={ () => setModalOpen( false ) }
						/>
					) }
				</>
			);
		};
	},
	'withGenerateImageReplaceFlowButton'
);

addFilter(
	'editor.MediaReplaceFlow',
	'ai/image-generation-replace-flow-button',
	withGenerateImageReplaceFlowButton
);
