/**
 * External dependencies
 */
import React from 'react';

/**
 * WordPress dependencies
 */
import { executeAbility } from '@wordpress/abilities';
import { Button } from '@wordpress/components';
import { dispatch, select } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Generates an image for the given post ID and content.
 *
 * @param {string} content The content of the post to generate an image for.
 * @return {Promise<string>} A promise that resolves to the generated image.
 */
async function generateImage( content: string ): Promise< string > {
	return executeAbility( 'ai/image-generation', {
		prompt: content,
	} )
		.then( ( response ) => {
			if ( response && typeof response === 'string' ) {
				return response;
			}

			return '';
		} )
		.catch( ( error ) => {
			throw new Error( `Error generating titles: ${ error.message }` );
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
	 * Handles the generate/re-generate button click.
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
		<div className="ai-featured-image">
			<div className="ai-featured-image__container">
				{ image && (
					<img
						src={ `data:image/png;base64,${ image }` }
						alt={ __( 'Generated featured image', 'ai' ) }
						className="ai-featured-image__image"
					/>
				) }
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
	);
}
