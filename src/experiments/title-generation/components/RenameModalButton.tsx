/**
 * Button component for regenerating post title within the Rename modal.
 */

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { dispatch, useSelect } from '@wordpress/data';
import { store as editorStore, PostTypeSupportCheck } from '@wordpress/editor';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { ensureProvider } from '../../../utils/provider-status';
import { hasMinimumContent } from '../../../utils/character-count';
import { generateTitle, getSettings, NOTICE_ID, setInputValue } from '../utils';

interface RenameModalButtonProps {
	inputElement?: HTMLInputElement | null;
	formElement?: HTMLFormElement | null;
}

/**
 * Renders the "Generate title" button for the Rename modal.
 *
 * @param props              Component props.
 * @param props.inputElement Target title input element to populate.
 * @param props.formElement  Parent form element containing the input.
 * @return The button component.
 */
export default function RenameModalButton( {
	inputElement,
	formElement,
}: RenameModalButtonProps ): React.JSX.Element | null {
	const { postId, content } = useSelect( ( select ) => {
		const editor = select( editorStore );

		return {
			postId: editor.getCurrentPostId(),
			content: editor.getEditedPostContent() as string,
		};
	}, [] );

	const [ isGenerating, setIsGenerating ] = useState< boolean >( false );

	const { minContentLength } = getSettings();
	const isContentTooShort = ! hasMinimumContent( content, minContentLength );

	const buttonLabel = isGenerating
		? __( 'Generating…', 'ai' )
		: __( 'Generate title', 'ai' );

	const tooShortLabel = sprintf(
		/* translators: %d: minimum number of characters required. */
		__(
			'Title generation will be available when the post content has at least %d characters.',
			'ai'
		),
		minContentLength
	);

	const buttonTooltip = isContentTooShort ? tooShortLabel : buttonLabel;
	const isDisabled = isGenerating || isContentTooShort;

	/**
	 * Handles generating a new title and populating the text input in the modal.
	 */
	const handleGenerate = async () => {
		if ( isGenerating ) {
			return;
		}

		if ( ! ensureProvider( NOTICE_ID ) ) {
			return;
		}

		setIsGenerating( true );
		dispatch( noticesStore ).removeNotice( NOTICE_ID );

		try {
			const result = await generateTitle( postId as number, content );

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
		} catch ( error: any ) {
			const message =
				typeof error === 'string'
					? error
					: error?.message ?? __( 'Failed to generate title.', 'ai' );
			dispatch( noticesStore ).createErrorNotice( message, {
				id: NOTICE_ID,
				isDismissible: true,
			} );
		} finally {
			setIsGenerating( false );
		}
	};

	// Don't render if title generation is disabled.
	if ( ! getSettings().enabled ) {
		return null;
	}

	return (
		<PostTypeSupportCheck supportKeys="title">
			<Button
				type="button"
				variant="secondary"
				onClick={ handleGenerate }
				disabled={ isDisabled }
				isBusy={ isGenerating }
				accessibleWhenDisabled
				label={ buttonTooltip }
				showTooltip={ isContentTooShort }
				__next40pxDefaultSize
				className="ai-title-generation-rename-button"
			>
				{ buttonLabel }
			</Button>
		</PostTypeSupportCheck>
	);
}
