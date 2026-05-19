/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { dispatch, select, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { generateImage } from '../functions/generate-image';
import { uploadImage } from '../functions/upload-image';
import { ensureProvider } from '../../../utils/provider-status';

const NOTICE_ID = 'ai_image_generation_error';

/**
 * GenerateFeaturedImage component.
 *
 * Provides a button to generate a featured image.
 *
 * @return {React.JSX.Element} The GenerateFeaturedImage component.
 */
export default function GenerateFeaturedImage(): React.JSX.Element | null {
	const { editPost } = useDispatch( editorStore );

	const content = select( editorStore ).getEditedPostContent();
	const featuredImage =
		select( editorStore ).getEditedPostAttribute( 'featured_media' );

	const [ isGenerating, setIsGenerating ] = useState< boolean >( false );
	const [ progress, setProgress ] = useState( '' );

	/**
	 * Handles the generate button click.
	 */
	const handleGenerate = async () => {
		if ( ! ensureProvider( NOTICE_ID ) ) {
			return;
		}

		setIsGenerating( true );
		setProgress( '' );
		( dispatch( noticesStore ) as any ).removeNotice( NOTICE_ID );

		try {
			const generatedImageData = await generateImage( content, {
				onProgress: setProgress,
			} );
			const importedImage = await uploadImage( generatedImageData, {
				onProgress: setProgress,
			} );
			editPost( {
				featured_media: importedImage.id,
			} );
		} catch ( error: unknown ) {
			const message =
				error instanceof Error
					? error.message
					: __( 'An error occurred during image generation.', 'ai' );
			( dispatch( noticesStore ) as any ).createErrorNotice( message, {
				id: NOTICE_ID,
				isDismissible: true,
			} );
		} finally {
			setIsGenerating( false );
			setProgress( '' );
		}
	};

	if ( featuredImage && ! isGenerating ) {
		return null;
	}

	return (
		<div className="ai-featured-image editor-post-featured-image">
			<div className="ai-featured-image__container editor-post-featured-image__container">
				<Button
					__next40pxDefaultSize
					className="ai-generate-featured-image editor-post-featured-image__toggle"
					onClick={ handleGenerate }
					disabled={ isGenerating }
					isBusy={ isGenerating }
				>
					{ isGenerating
						? __( 'Generating…', 'ai' )
						: __( 'Generate featured image', 'ai' ) }
				</Button>
				{ isGenerating && progress && (
					<p className="ai-featured-image__progress description">
						{ progress }
					</p>
				) }
			</div>
		</div>
	);
}
