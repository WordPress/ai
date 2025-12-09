/**
 * External dependencies
 */
import React from 'react';

/**
 * WordPress dependencies
 */
import { Button, __experimentalHStack as HStack } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { dispatch, select, useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { generateImage } from '../functions/generate-image';
import { uploadImage } from '../functions/upload-image';

/**
 * TODO:
 * - Add ability to see full image in a modal or lightbox (or link to media library view MediaUpload component)
 * - Wire up the set button (or think about auto-setting as featured image when generated)
 * - Add regenerate button and wire it up
 * - Add middleware ability to take post context and generate prompt we can pass to image gen
 * - Styling to make generated image appear separate from featured image
 * - Look at creating functions for setting and removing the image.
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
	const currentAiImageId = meta?.ai_featured_image;

	// See if we have an existing image to display.
	const aiImage = useSelect(
		( selectStore ) => {
			if ( ! currentAiImageId ) {
				return null;
			}
			return selectStore( coreStore ).getEntityRecord(
				'postType',
				'attachment',
				currentAiImageId
			);
		},
		[ currentAiImageId ]
	);

	const [ isGenerating, setIsGenerating ] = useState< boolean >( false );
	const [ image, setImage ] = useState< string >( '' );

	// Sync image state when entity record becomes available.
	useEffect( () => {
		if ( aiImage?.source_url ) {
			setImage( aiImage.source_url );
		} else if ( ! currentAiImageId ) {
			setImage( '' );
		}
	}, [ aiImage, currentAiImageId ] );

	/**
	 * Handles the generate button click.
	 */
	const handleGenerate = async () => {
		setIsGenerating( true );
		( dispatch( noticesStore ) as any ).removeNotice(
			'ai_image_generation_error'
		);

		try {
			const generatedImage = await generateImage( content );
			const importedImage = await uploadImage( generatedImage );
			editPost( {
				meta: {
					...meta,
					ai_featured_image: importedImage.id,
				},
			} );
			saveEditedEntityRecord( 'postType', postType, postId );
			setImage( importedImage.url );
		} catch ( error: any ) {
			( dispatch( noticesStore ) as any ).createErrorNotice( error, {
				id: 'ai_image_generation_error',
				isDismissible: true,
			} );
			setImage( '' );
		} finally {
			setIsGenerating( false );
		}
	};

	return (
		<div className="ai-featured-image editor-post-featured-image">
			<div className="ai-featured-image__container editor-post-featured-image__container">
				{ image && (
					<div className="editor-post-featured-image__preview">
						<img
							src={ image }
							alt={ __( 'Generated featured image', 'ai' ) }
							className="ai-featured-image__image editor-post-featured-image__preview-image"
						/>
					</div>
				) }
				{ ! image && (
					<Button
						__next40pxDefaultSize
						className="ai-generate-featured-image editor-post-featured-image__toggle"
						onClick={ handleGenerate }
						disabled={ isGenerating }
						isBusy={ isGenerating }
					>
						{ __( 'Generate featured image', 'ai' ) }
					</Button>
				) }
				{ !! image && (
					<HStack className="editor-post-featured-image__actions">
						<Button
							__next40pxDefaultSize
							className="editor-post-featured-image__action"
							onClick={ () => {
								console.log( 'set image' );
							} }
						>
							{ __( 'Set', 'ai' ) }
						</Button>
						<Button
							__next40pxDefaultSize
							className="editor-post-featured-image__action"
							onClick={ () => {
								editPost( {
									meta: {
										...meta,
										ai_featured_image: null,
									},
								} );
								saveEditedEntityRecord(
									'postType',
									postType,
									postId
								);
								setImage( '' );
							} }
						>
							{ __( 'Remove', 'ai' ) }
						</Button>
					</HStack>
				) }
			</div>
		</div>
	);
}
