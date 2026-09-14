/**
 * WordPress dependencies
 */
import {
	Button,
	Modal,
	Notice,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { Stack } from '@wordpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as noticesStore } from '@wordpress/notices';
import { safeHTML } from '@wordpress/dom';

/**
 * Internal dependencies
 */
import { getSettings } from '../utils';
import { getBlockHTML } from '../../../utils/blocks';
import { useContentTranslation } from '../hooks/useContentTranslation';
import { hasMinimumContent } from '../../../utils/character-count';
import { getErrorMessage } from '../../../utils/errors';

/**
 * Modal component for translating a block.
 *
 * @param props            Component props.
 * @param props.clientId   The block client ID.
 * @param props.closeModal The function invoked when the modal is closed.
 */
export default function BlockTranslationModal( {
	clientId,
	closeModal,
}: {
	clientId: string;
	closeModal: () => void;
} ) {
	const options = useMemo(
		() =>
			getSettings().languages.map( ( language ) => ( {
				label: language.name,
				value: language.code,
			} ) ),
		[]
	);

	const [ translationPreview, setTranslationPreview ] = useState( {
		content: '',
		editableTextAttribute: '',
	} );
	const [ selectedLanguage, setSelectedLanguage ] = useState(
		options?.[ 0 ]?.value || ''
	);

	const { createErrorNotice } = useDispatch( noticesStore );
	const { updateBlockAttributes } = useDispatch( blockEditorStore );

	const { isLoading, generateBlockTranslationPreview, minContentLength } =
		useContentTranslation();

	const { blockContent } = useSelect( ( select ) => {
		const block = select( blockEditorStore ).getBlock( clientId );

		return {
			blockContent: block ? getBlockHTML( block ) : '',
		};
	} );

	const isContentTooShort = ! hasMinimumContent(
		blockContent || '',
		minContentLength
	);

	/**
	 * Generates a translation preview for the current block in the selected
	 * language and stores the result for review and acceptance.
	 *
	 * @return A promise that resolves when preview generation finishes.
	 */
	const onGenerateTranslation = async () => {
		try {
			const preview = await generateBlockTranslationPreview(
				selectedLanguage,
				clientId
			);

			if ( ! preview ) {
				return;
			}

			setTranslationPreview( preview );
		} catch ( error ) {
			createErrorNotice(
				getErrorMessage(
					error,
					__( 'Failed to generate translation.', 'ai' )
				),
				{ type: 'snackbar' }
			);
		}
	};

	const onAcceptTranslation = () => {
		if (
			! translationPreview.content ||
			typeof translationPreview.content !== 'string' ||
			! translationPreview.content.trim().length
		) {
			return;
		}

		if ( ! translationPreview.editableTextAttribute ) {
			return;
		}

		updateBlockAttributes( clientId, {
			[ translationPreview.editableTextAttribute ]:
				translationPreview.content,
		} );
		closeModal();
	};

	return (
		<Modal
			title={ __( 'Translate Content', 'ai' ) }
			onRequestClose={ closeModal }
			isFullScreen={ false }
			size="medium"
			className="ai-content-resizing-modal"
		>
			<Stack
				direction="column"
				className="ai-content-resizing-modal__panel"
				aria-label={ __( 'Original content', 'ai' ) }
			>
				<div className="ai-content-resizing-modal__label">
					<span>{ __( 'Original', 'ai' ) }</span>
				</div>
				<div
					className="ai-content-resizing-modal__text ai-content-resizing-modal__text--original"
					dangerouslySetInnerHTML={ {
						__html: safeHTML( blockContent ),
					} }
				/>
			</Stack>

			<Stack
				direction="column"
				className="ai-content-resizing-modal__panel"
				aria-label={ __( 'Translated content', 'ai' ) }
			>
				<div className="ai-content-resizing-modal__label">
					<span>{ __( 'Translated', 'ai' ) }</span>
				</div>
				{ isLoading ? (
					<div
						className="ai-content-resizing-modal__text ai-content-resizing-modal__loading"
						role="status"
						aria-live="polite"
					>
						<div className="ai-content-resizing-modal__loading-status">
							<Spinner />
							<span>{ __( 'Generating…', 'ai' ) }</span>
						</div>
						<div
							className="ai-content-resizing-modal__loading-skeleton"
							aria-hidden="true"
						>
							{ Array.from( { length: 3 } ).map( ( _, index ) => (
								<span
									key={ index }
									className="ai-content-resizing-modal__loading-skeleton-line"
								/>
							) ) }
						</div>
					</div>
				) : (
					<div
						className="ai-content-resizing-modal__text"
						dangerouslySetInnerHTML={ {
							__html: safeHTML(
								translationPreview.content || blockContent || ''
							),
						} }
					/>
				) }
			</Stack>

			{ isContentTooShort && (
				<Notice
					isDismissible={ false }
					status="info"
					className="ai-content-translation-modal__notice"
				>
					{ sprintf(
						/* translators: %d: minimum number of characters required for translation. */
						__(
							'Translation will be available when the content has at least %d characters.',
							'ai'
						),
						minContentLength
					) }
				</Notice>
			) }

			<Stack
				direction="row"
				className="ai-content-resizing-modal__actions"
				justify="space-between"
				align="flex-end"
			>
				<Stack direction="row" gap="sm" justify="flex-start">
					<Button
						variant="primary"
						accessibleWhenDisabled
						__next40pxDefaultSize
						disabled={
							isLoading ||
							isContentTooShort ||
							! translationPreview.content
						}
						onClick={ onAcceptTranslation }
					>
						{ __( 'Accept', 'ai' ) }
					</Button>

					<Button
						variant="secondary"
						accessibleWhenDisabled
						__next40pxDefaultSize
						disabled={ isLoading || isContentTooShort }
						onClick={ onGenerateTranslation }
					>
						{ isLoading
							? __( 'Translating…', 'ai' )
							: __( 'Generate Translation', 'ai' ) }
					</Button>
				</Stack>

				<SelectControl
					label={ __( 'Translate to', 'ai' ) }
					options={ options }
					value={ selectedLanguage }
					onChange={ ( value ) => setSelectedLanguage( value ) }
					__next40pxDefaultSize
					disabled={ isLoading || isContentTooShort }
				/>
			</Stack>
		</Modal>
	);
}
