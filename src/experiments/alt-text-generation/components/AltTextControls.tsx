/**
 * Alt text generation controls for the image block inspector.
 */

/**
 * WordPress dependencies
 */
import { Button, TextareaControl, Notice } from '@wordpress/components';
import { update } from '@wordpress/icons';
import { InspectorControls } from '@wordpress/block-editor';
import { type RefCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { dispatch, select } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { store as editorStore } from '@wordpress/editor';
import { Stack } from '@wordpress/ui';
import { getBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import type { ImageBlockAttributes } from '../types';
import { generateAltText } from '../../../utils/generate-alt-text';
import { ensureProvider } from '../../../utils/provider-status';

const NOTICE_ID = 'ai_alt_text_generation_error';

interface AltTextControlsProps {
	clientId: string;
	attributes: ImageBlockAttributes;
	setAttributes: ( attributes: Partial< ImageBlockAttributes > ) => void;
	generateButtonRef: RefCallback< HTMLButtonElement | null >;
	primaryButtonRef: RefCallback< HTMLButtonElement | null >;
	requestFocus: ( target: 'generate' | 'notice' | 'primary' ) => void;
}

/**
 * Returns the appropriate button label based on state.
 *
 * @param {boolean} hasExistingAlt Whether the image has existing alt text.
 * @param {boolean} isGenerating   Whether alt text is currently being generated.
 * @return {string} The button label.
 */
export function getButtonLabel(
	hasExistingAlt: boolean,
	isGenerating: boolean
): string {
	if ( isGenerating ) {
		return __( 'Generating…', 'ai' );
	}
	if ( hasExistingAlt ) {
		return __( 'Regenerate Alt Text', 'ai' );
	}
	return __( 'Generate Alt Text', 'ai' );
}

/**
 * AltTextControls component.
 *
 * Adds a "Generate Alt Text" button to the image block inspector panel.
 *
 * @param {AltTextControlsProps}                    props                   The component props.
 * @param {string}                                  props.clientId          The block client ID.
 * @param {ImageBlockAttributes}                    props.attributes        The block attributes.
 * @param {Function}                                props.setAttributes     The function to set the block attributes.
 * @param {RefCallback< HTMLButtonElement | null >} props.generateButtonRef The ref to the generate button.
 * @param {RefCallback< HTMLButtonElement | null >} props.primaryButtonRef  The ref to the primary button.
 * @param {Function}                                props.requestFocus      The function to request focus.
 * @return {React.JSX.Element|null} The component.
 */
export function AltTextControls( {
	clientId,
	attributes,
	generateButtonRef,
	primaryButtonRef,
	requestFocus,
	setAttributes,
}: AltTextControlsProps ): React.JSX.Element | null {
	const { id: attachmentId, url: imageUrl, alt } = attributes;

	const [ isGenerating, setIsGenerating ] = useState< boolean >( false );
	const [ generatedAlt, setGeneratedAlt ] = useState< string | null >( null );
	const [ isFoundDecorative, setIsFoundDecorative ] =
		useState< boolean >( false );

	const hasGeneratedAlt = generatedAlt !== null;

	// Don't show controls if there's no image.
	if ( ! attachmentId && ! imageUrl ) {
		return null;
	}

	const hasExistingAlt = alt && alt.trim().length > 0;

	/**
	 * Handles the generate button click.
	 */
	const handleGenerate = async () => {
		if ( ! ensureProvider( NOTICE_ID ) ) {
			return;
		}

		setIsGenerating( true );
		setGeneratedAlt( null );
		setIsFoundDecorative( false );

		// Clear any previous notices.
		dispatch( noticesStore ).removeNotice( NOTICE_ID );

		try {
			const content = select( editorStore ).getEditedPostContent();
			const result = await generateAltText(
				attachmentId,
				imageUrl,
				content,
				clientId,
				{
					linkDestination: attributes?.linkDestination,
					href: attributes?.href,
					linkTarget: attributes?.linkTarget,
					caption:
						typeof attributes?.caption === 'string'
							? attributes.caption
							: ( attributes?.caption as any )?.text,
				}
			);

			requestFocus( 'primary' );

			if ( result.is_decorative ) {
				setIsFoundDecorative( true );
				setGeneratedAlt( '' );
			} else {
				setGeneratedAlt( result.alt_text );
			}
		} catch ( err: any ) {
			const errorMessage =
				err?.message ||
				__( 'An error occurred while generating alt text.', 'ai' );
			dispatch( noticesStore ).createErrorNotice( errorMessage, {
				id: NOTICE_ID,
				isDismissible: true,
			} );
		} finally {
			setIsGenerating( false );
		}
	};

	/**
	 * Applies the generated alt text to the image block.
	 */
	const handleApply = () => {
		if ( generatedAlt ) {
			setAttributes( { alt: generatedAlt } );
		}

		requestFocus( 'generate' );
		setGeneratedAlt( null );
		setIsFoundDecorative( false );
	};

	/**
	 * Enables the core Image block "Mark as decorative" setting.
	 *
	 * Mirrors core's implementation: set `isDecorative` and clear
	 * alt, caption, href, linkDestination, linkTarget, and rel fields so
	 * those values are not left behind.
	 */
	const markImageAsDecorative = () => {
		// Check whether `isDecorative` is registered before setting it.
		const supportsMarkAsDecorative = Object.hasOwn(
			getBlockType( 'core/image' )?.attributes ?? {},
			'isDecorative'
		);

		setAttributes( {
			...( supportsMarkAsDecorative ? { isDecorative: true } : {} ),
			alt: '',
			caption: undefined,
			href: undefined,
			linkDestination: undefined,
			linkTarget: undefined,
			rel: undefined,
		} );

		requestFocus( 'notice' );
		setGeneratedAlt( null );
		setIsFoundDecorative( false );
	};

	/**
	 * Dismisses the generated alt text suggestion.
	 */
	const handleDismiss = () => {
		requestFocus( 'generate' );
		setGeneratedAlt( null );
		setIsFoundDecorative( false );
	};

	return (
		<InspectorControls group="content">
			<Stack
				direction="column"
				className="ai-alt-text-controls"
				style={ { padding: '0 16px' } }
			>
				{ /* Generated alt text preview */ }
				{ hasGeneratedAlt && ! isFoundDecorative && (
					<Stack direction="column">
						<TextareaControl
							label={ __( 'Generated Alt Text', 'ai' ) }
							hideLabelFromVision
							value={ generatedAlt || '' }
							onChange={ ( value ) => setGeneratedAlt( value ) }
							rows={ 3 }
						/>
						<Stack direction="row" gap="md">
							<Button
								ref={ primaryButtonRef }
								variant="primary"
								onClick={ handleApply }
								__next40pxDefaultSize
							>
								{ __( 'Apply', 'ai' ) }
							</Button>
							<Button
								variant="secondary"
								onClick={ handleDismiss }
								__next40pxDefaultSize
							>
								{ __( 'Dismiss', 'ai' ) }
							</Button>
						</Stack>
					</Stack>
				) }

				{ /* Decorative image notice */ }
				{ isFoundDecorative && (
					<Notice status="info" onDismiss={ handleDismiss }>
						<p>
							{ __(
								'This image appears to be decorative. Consider marking it as decorative so screen readers can skip it.',
								'ai'
							) }
						</p>

						<Button
							ref={ primaryButtonRef }
							variant="secondary"
							onClick={ markImageAsDecorative }
							style={ {
								width: '100%',
								justifyContent: 'center',
							} }
							__next40pxDefaultSize
						>
							{ __( 'Mark as decorative', 'ai' ) }
						</Button>
					</Notice>
				) }

				{ /* Generate button */ }
				{ ! hasGeneratedAlt && ! isFoundDecorative && (
					<Button
						ref={ generateButtonRef }
						variant="secondary"
						onClick={ handleGenerate }
						disabled={ isGenerating }
						accessibleWhenDisabled
						style={ {
							width: '100%',
							justifyContent: 'center',
						} }
						isBusy={ isGenerating }
						icon={ update }
						__next40pxDefaultSize
					>
						{ getButtonLabel( !! hasExistingAlt, isGenerating ) }
					</Button>
				) }
			</Stack>
		</InspectorControls>
	);
}
