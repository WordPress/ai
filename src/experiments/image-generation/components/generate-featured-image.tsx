/**
 * External dependencies
 */
import React from 'react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, __experimentalHStack as HStack } from '@wordpress/components';
import { dispatch, select } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

const { aiImageGenerationData } = window as any;

/**
 * Generates an image for the given post ID and content.
 *
 * @param {string} content The content of the post to generate an image for.
 * @return {Promise<string>} A promise that resolves to the generated image.
 */
async function generateImage( content: string ): Promise< string > {
	return apiFetch( {
		path: aiImageGenerationData?.path ?? '',
		method: 'POST',
		data: {
			input: {
				prompt: content,
			},
		},
	} )
		.then( ( response ) => {
			if ( response && typeof response === 'string' ) {
				return response;
			}

			return '';
		} )
		.catch( ( error ) => {
			throw new Error( error.message );
		} );
}

/**
 * GenerateFeaturedImage component.
 *
 * Provides a button to generate a featured image.
 *
 * @return {JSX.Element} The GenerateFeaturedImage component.
 */
export default function GenerateFeaturedImage(): JSX.Element {
	const content = select( editorStore ).getEditedPostContent();

	const [ isGenerating, setIsGenerating ] = useState< boolean >( false );
	const [ image, setImage ] = useState< string >( '' );

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
			setImage( generatedImage );
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
							src={ `data:image/png;base64,${ image }` }
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
								console.log( 'remove image' );
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
