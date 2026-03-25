/**
 * WordPress dependencies
 */
import { Button, Spinner } from '@wordpress/components';
import { dispatch, select } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { generateImage } from '../functions/generate-image';
import { uploadImage } from '../functions/upload-image';
import { useEditorDispatch } from '../../../utils/editor-dispatch';

/**
 * GenerateFeaturedImage component.
 *
 * Provides a button to generate a featured image.
 *
 * @return {JSX.Element} The GenerateFeaturedImage component.
 */
export default function GenerateFeaturedImage(): JSX.Element {
	const { editPost } = useEditorDispatch();

	const content = select( editorStore ).getEditedPostContent();
	const postId = select( editorStore ).getCurrentPostId();
	const featuredImage =
		select( editorStore ).getEditedPostAttribute( 'featured_media' );

	const [ isGenerating, setIsGenerating ] = useState< boolean >( false );
	const [ progressMessage, setProgressMessage ] = useState< string | null >(
		null
	);

	const buttonLabel = featuredImage
		? __( 'Generate new featured image', 'ai' )
		: __( 'Generate featured image', 'ai' );

	/**
	 * Handles the generate button click.
	 */
	const handleGenerate = async () => {
		setIsGenerating( true );
		setProgressMessage( null );
		( dispatch( noticesStore ) as any ).removeNotice(
			'ai_image_generation_error'
		);

		try {
			const generatedImageData = await generateImage(
				postId as number,
				content,
				{ onProgress: setProgressMessage }
			);
			const importedImage = await uploadImage( generatedImageData, {
				onProgress: setProgressMessage,
			} );
			editPost( {
				featured_media: importedImage.id,
			} );
		} catch ( error: any ) {
			( dispatch( noticesStore ) as any ).createErrorNotice( error, {
				id: 'ai_image_generation_error',
				isDismissible: true,
			} );
		} finally {
			setIsGenerating( false );
			setProgressMessage( null );
		}
	};

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
					{ buttonLabel }
				</Button>
				{ progressMessage && (
					<div
						className="ai-featured-image__progress"
						role="status"
						aria-live="polite"
						style={ { color: '#757575', marginTop: '10px' } }
					>
						{ progressMessage }
						<Spinner
							style={ {
								marginLeft: '5px',
								marginTop: '0',
								position: 'inherit',
								verticalAlign: 'text-top',
							} }
						/>
					</div>
				) }
			</div>
		</div>
	);
}
