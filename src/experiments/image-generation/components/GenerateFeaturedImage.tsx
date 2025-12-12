/**
 * External dependencies
 */
import React from 'react';

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
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

/**
 * TODO:
 * - Show a regenerate icon overlay and wire it up
 * - Add middleware ability to take post context and generate prompt we can pass to image gen
 */

/**
 * GenerateFeaturedImage component.
 *
 * Provides a button to generate a featured image.
 *
 * @return {JSX.Element} The GenerateFeaturedImage component.
 */
export default function GenerateFeaturedImage(): JSX.Element {
	const { editPost } = useDispatch( editorStore );
	const { saveEditedEntityRecord } = useDispatch( coreStore );

	const content = select( editorStore ).getEditedPostContent();
	const meta = select( editorStore ).getEditedPostAttribute( 'meta' );
	const postId = select( editorStore ).getCurrentPostId();
	const postType = select( editorStore ).getCurrentPostType();
	const featuredImage =
		select( editorStore ).getEditedPostAttribute( 'featured_media' );

	const [ isGenerating, setIsGenerating ] = useState< boolean >( false );

	/**
	 * Handles the generate button click.
	 */
	const handleGenerate = async () => {
		setIsGenerating( true );
		( dispatch( noticesStore ) as any ).removeNotice(
			'ai_image_generation_error'
		);

		try {
			const generatedImage = await generateImage( postId, content );
			const importedImage = await uploadImage( generatedImage );
			editPost( {
				featured_media: importedImage.id,
				meta: {
					...meta,
					ai_featured_image: importedImage.id,
				},
			} );
			saveEditedEntityRecord( 'postType', postType, postId );
		} catch ( error: any ) {
			( dispatch( noticesStore ) as any ).createErrorNotice( error, {
				id: 'ai_image_generation_error',
				isDismissible: true,
			} );
		} finally {
			setIsGenerating( false );
		}
	};

	return (
		<>
			{ ! featuredImage && (
				<div className="ai-featured-image editor-post-featured-image">
					<div className="ai-featured-image__container editor-post-featured-image__container">
						<Button
							__next40pxDefaultSize
							className="ai-generate-featured-image editor-post-featured-image__toggle"
							onClick={ handleGenerate }
							disabled={ isGenerating }
							isBusy={ isGenerating }
						>
							{ __( 'Generate featured image', 'ai' ) }
						</Button>
					</div>
				</div>
			) }
		</>
	);
}
