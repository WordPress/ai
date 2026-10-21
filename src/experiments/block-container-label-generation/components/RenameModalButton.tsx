/**
 * Button component for generating a block label within Gutenberg's Rename modal.
 */

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { dispatch, useSelect } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as editorStore } from '@wordpress/editor';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { ensureProvider } from '../../../utils/provider-status';
import { hasMinimumContent } from '../../../utils/character-count';
import { getErrorMessage } from '../../../utils/errors';
import {
	generateLabel,
	getBlockSubtreeContent,
	getSettings,
	setInputValue,
} from '../utils';
import { BLOCK_CONTAINER_LABEL_GENERATION_NOTICE_ID } from '../constants';
import type { Block } from '../types';

interface RenameModalButtonProps {
	inputElement?: HTMLInputElement | null;
	formElement?: HTMLFormElement | null;
	clientId?: string | null;
}

/**
 * Renders the "Generate label" button for the block Rename modal.
 *
 * @param {RenameModalButtonProps} props                Component props.
 * @param {HTMLInputElement}       [props.inputElement] Target input element to populate.
 * @param {HTMLFormElement}        [props.formElement]  Parent form element containing the input.
 * @param {string}                 [props.clientId]     The client ID of the block being renamed.
 * @return {React.JSX.Element|null} The button component.
 */
export default function RenameModalButton( {
	inputElement,
	formElement,
	clientId,
}: RenameModalButtonProps ): React.JSX.Element | null {
	const { postId, block } = useSelect(
		( select ) => {
			const editor = select( editorStore );
			const blockEditor = select( blockEditorStore ) as {
				getBlock: ( id: string ) => Block | null;
			};

			return {
				postId: editor.getCurrentPostId() as number,
				block: clientId ? blockEditor.getBlock( clientId ) : null,
			};
		},
		[ clientId ]
	);

	const [ isGenerating, setIsGenerating ] = useState< boolean >( false );
	const [ hasGenerated, setHasGenerated ] = useState< boolean >( false );

	if ( ! getSettings().enabled ) {
		return null;
	}

	if ( ! block ) {
		return null;
	}

	// Only apply to container blocks (matching the bulk generator logic).
	const isContainer = block.innerBlocks.length > 0;
	if ( ! isContainer ) {
		return null;
	}

	const blockContent = getBlockSubtreeContent( block );
	const { minContentLength } = getSettings();
	const isContentTooShort = ! hasMinimumContent(
		blockContent,
		minContentLength
	);

	const existingLabel = block.attributes?.metadata?.name?.trim();
	const hasLabel = Boolean( existingLabel ) || hasGenerated;

	let buttonLabel: string = __( 'Generate label', 'ai' );
	if ( isGenerating ) {
		buttonLabel = __( 'Generating…', 'ai' );
	} else if ( hasLabel ) {
		buttonLabel = __( 'Regenerate label', 'ai' );
	}

	const tooShortLabel = sprintf(
		/* translators: %d: minimum number of characters required. */
		__(
			'Label generation will be available when the block content has at least %d characters.',
			'ai'
		),
		minContentLength
	);

	const buttonTooltip = isContentTooShort ? tooShortLabel : buttonLabel;
	const isDisabled = isGenerating || isContentTooShort;

	/**
	 * Handles generating a new label and populating the text input in the modal.
	 */
	const handleGenerate = async () => {
		if ( isGenerating ) {
			return;
		}

		if ( ! ensureProvider( BLOCK_CONTAINER_LABEL_GENERATION_NOTICE_ID ) ) {
			return;
		}

		setIsGenerating( true );
		dispatch( noticesStore ).removeNotice(
			BLOCK_CONTAINER_LABEL_GENERATION_NOTICE_ID
		);

		try {
			const result = (
				await generateLabel( blockContent, postId )
			).trim();

			if ( ! result ) {
				throw new Error( __( 'No label was generated.', 'ai' ) );
			}

			const targetInput =
				inputElement && document.body.contains( inputElement )
					? inputElement
					: formElement?.querySelector< HTMLInputElement >(
							'input.components-text-control__input, input[type="text"]'
					  );

			if ( targetInput ) {
				setInputValue( targetInput, result );
				targetInput.focus();
				targetInput.select();
			}

			setHasGenerated( true );
		} catch ( error ) {
			const message = getErrorMessage(
				error,
				__( 'Failed to generate block label.', 'ai' )
			);
			dispatch( noticesStore ).createErrorNotice( message, {
				id: BLOCK_CONTAINER_LABEL_GENERATION_NOTICE_ID,
				isDismissible: true,
			} );
		} finally {
			setIsGenerating( false );
		}
	};

	return (
		<Button
			type="button"
			variant="secondary"
			onClick={ handleGenerate }
			disabled={ isDisabled }
			isBusy={ isGenerating }
			accessibleWhenDisabled
			{ ...( isContentTooShort && {
				label: buttonTooltip,
				showTooltip: true,
			} ) }
			__next40pxDefaultSize
			className="ai-block-container-label-generation-rename-button"
		>
			{ buttonLabel }
		</Button>
	);
}
