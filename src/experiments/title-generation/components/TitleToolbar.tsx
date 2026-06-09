/**
 * Title toolbar component for generating post titles.
 */

/**
 * WordPress dependencies
 */
import {
	Button,
	Flex,
	FlexItem,
	Modal,
	TextareaControl,
	ToolbarGroup,
	ToolbarButton,
} from '@wordpress/components';
import { dispatch, useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore, PostTypeSupportCheck } from '@wordpress/editor';
import { useEffect, useRef, useState } from '@wordpress/element';
import { update } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';
import { ensureProvider } from '../../../utils/provider-status';
import { getWordCountType, hasMinimumContent } from '../../../utils/word-count';
import type {
	TitleGenerationAbilityInput,
	GeneratedTitleData,
	TitleGenerationData,
} from '../types';

const NOTICE_ID = 'ai_title_generation_error';
const MINIMUM_CONTENT_COUNT_DEFAULT = 100;

const getSettings = (): TitleGenerationData => {
	const settings = ( window as any ).aiTitleGenerationData ?? {};

	return {
		enabled: settings.enabled ?? false,
		minContentLength:
			settings.minContentLength ?? MINIMUM_CONTENT_COUNT_DEFAULT,
	};
};

/**
 * Generates a title for the given post ID and content.
 *
 * @param {number} postId  The ID of the post to generate a title for.
 * @param {string} content The content of the post to generate a title for.
 * @return {Promise<string>} A promise that resolves to the generated title.
 */
async function generateTitle(
	postId: number,
	content: string
): Promise< string > {
	const params: TitleGenerationAbilityInput = {
		context: postId.toString(),
		content,
	};

	const response = await runAbility< GeneratedTitleData >(
		'ai/title-generation',
		params
	);

	if (
		response &&
		typeof response === 'object' &&
		'title' in response &&
		typeof response.title === 'string' &&
		response.title.length > 0
	) {
		return response.title;
	}

	throw new Error( __( 'No title suggestion was generated.', 'ai' ) );
}

/**
 * TitleToolbar component.
 *
 * Provides Generate/Regenerate button and a modal for reviewing and
 * inserting the AI-generated title suggestion.
 *
 * @return {React.JSX.Element} The toolbar component.
 */
interface TitleToolbarProps {
	isStandalone?: boolean;
}

export default function TitleToolbar( {
	isStandalone = false,
}: TitleToolbarProps ): React.JSX.Element | null {
	const { postId, title, content } = useSelect( ( select ) => {
		const editor = select( editorStore );

		return {
			postId: editor.getCurrentPostId(),
			title: editor.getEditedPostAttribute( 'title' ) as string,
			content: editor.getEditedPostContent() as string,
		};
	}, [] );

	const { editPost } = useDispatch( editorStore );

	const [ isGenerating, setIsGenerating ] = useState< boolean >( false );
	const [ isRegenerating, setIsRegenerating ] = useState< boolean >( false );
	const [ isOpen, setOpen ] = useState< boolean >( false );
	const [ generatedTitle, setGeneratedTitle ] = useState< string >( '' );

	const generateButtonRef = useRef< HTMLButtonElement | null >( null );

	// Tracks the pending focus-restore timeout so it can be cancelled on unmount.
	const focusTimeoutRef = useRef< ReturnType< typeof setTimeout > | null >(
		null
	);

	useEffect( () => {
		return () => {
			if ( focusTimeoutRef.current ) {
				clearTimeout( focusTimeoutRef.current );
			}
		};
	}, [] );

	const openModal = () => setOpen( true );

	/**
	 * Returns focus to the title input after the modal closes.
	 */
	const restoreFocus = () => {
		focusTimeoutRef.current = setTimeout( () => {
			focusTimeoutRef.current = null;

			const titleInput =
				generateButtonRef.current?.ownerDocument.querySelector(
					'.editor-post-title__input'
				) as HTMLElement | null;

			titleInput?.focus();
		}, 0 );
	};

	const closeModal = () => {
		setOpen( false );
		setGeneratedTitle( '' );
		restoreFocus();
	};

	const hasTitle = title.trim().length > 0;

	const { minContentLength } = getSettings();
	const isContentTooShort = ! hasMinimumContent( content, minContentLength );

	let buttonLabel: string = __( 'Generate', 'ai' );

	if ( isGenerating || isRegenerating ) {
		buttonLabel = __( 'Generating…', 'ai' );
	} else if ( hasTitle ) {
		buttonLabel = __( 'Regenerate', 'ai' );
	}

	// When the post content is too short, disable the button and surface the
	// minimum-length requirement as its accessible tooltip.
	const isCharacterType = getWordCountType() !== 'words';
	const tooShortLabel = isCharacterType
		? sprintf(
				/* translators: %d: minimum number of characters required */
				__(
					'Title generation will be available when the post content has at least %d characters.',
					'ai'
				),
				minContentLength
		  )
		: sprintf(
				/* translators: %d: minimum number of words required */
				__(
					'Title generation will be available when the post content has at least %d words.',
					'ai'
				),
				minContentLength
		  );

	const buttonTooltip = isContentTooShort ? tooShortLabel : buttonLabel;
	const isDisabled = isGenerating || isContentTooShort;

	/**
	 * Handles the toolbar Generate/Regenerate button click.
	 */
	const handleGenerate = async () => {
		if ( isGenerating ) {
			return;
		}

		if ( ! ensureProvider( NOTICE_ID ) ) {
			return;
		}

		setIsGenerating( true );
		( dispatch( noticesStore ) as any ).removeNotice( NOTICE_ID );

		try {
			const result = await generateTitle( postId as number, content );
			setGeneratedTitle( result );
			openModal();
		} catch ( error: any ) {
			const message =
				typeof error === 'string'
					? error
					: error?.message ?? __( 'Failed to generate title.', 'ai' );
			( dispatch( noticesStore ) as any ).createErrorNotice( message, {
				id: NOTICE_ID,
				isDismissible: true,
			} );
		} finally {
			setIsGenerating( false );
		}
	};

	/**
	 * Handles the Regenerate button inside the modal.
	 * Fetches a new suggestion without closing the modal.
	 */
	const handleRegenerate = async () => {
		setIsRegenerating( true );
		( dispatch( noticesStore ) as any ).removeNotice( NOTICE_ID );

		try {
			const result = await generateTitle( postId as number, content );
			setGeneratedTitle( result );
		} catch ( error: any ) {
			const message =
				typeof error === 'string'
					? error
					: error?.message ?? __( 'Failed to generate title.', 'ai' );
			( dispatch( noticesStore ) as any ).createErrorNotice( message, {
				id: NOTICE_ID,
				isDismissible: true,
			} );
		} finally {
			setIsRegenerating( false );
		}
	};

	/**
	 * Applies the generated title to the post and closes the modal.
	 */
	const handleInsert = () => {
		editPost( { title: generatedTitle } );
		closeModal();
	};

	// Don't render if disabled.
	if ( ! getSettings().enabled ) {
		return null;
	}

	return (
		<PostTypeSupportCheck supportKeys="title">
			{ isStandalone ? (
				<Button
					ref={ generateButtonRef }
					icon={ update }
					variant="secondary"
					label={ buttonTooltip }
					showTooltip
					onClick={ handleGenerate }
					disabled={ isDisabled }
					isBusy={ isGenerating }
					accessibleWhenDisabled
					__next40pxDefaultSize
				>
					{ buttonLabel }
				</Button>
			) : (
				<ToolbarGroup>
					<ToolbarButton
						ref={ generateButtonRef }
						icon={ update }
						label={ buttonTooltip }
						showTooltip
						onClick={ handleGenerate }
						disabled={ isDisabled }
						isBusy={ isGenerating }
					>
						{ buttonLabel }
					</ToolbarButton>
				</ToolbarGroup>
			) }
			{ isOpen && (
				<Modal
					title={ __( 'Title suggestion', 'ai' ) }
					onRequestClose={ closeModal }
					isFullScreen={ false }
					size="medium"
					className="ai-title-generation-modal"
				>
					<p className="ai-title-generation-subtitle">
						{ __(
							'Review, edit and insert the suggested title or regenerate a new one.',
							'ai'
						) }
					</p>
					<TextareaControl
						rows={ 2 }
						label={ __( 'Generated title', 'ai' ) }
						hideLabelFromVision
						value={ generatedTitle }
						onChange={ setGeneratedTitle }
						disabled={ isRegenerating }
					/>
					<Flex
						justify="flex-end"
						gap="3"
						className="ai-title-generation-actions"
					>
						<FlexItem>
							<Button
								accessibleWhenDisabled
								variant="secondary"
								onClick={ handleRegenerate }
								disabled={ isRegenerating }
								isBusy={ isRegenerating }
							>
								{ buttonLabel }
							</Button>
						</FlexItem>
						<FlexItem>
							<Button
								variant="primary"
								onClick={ handleInsert }
								disabled={ isRegenerating || ! generatedTitle }
							>
								{ __( 'Insert', 'ai' ) }
							</Button>
						</FlexItem>
					</Flex>
				</Modal>
			) }
		</PostTypeSupportCheck>
	);
}
