/**
 * Excerpt generator component for the excerpt panel.
 */

/**
 * External dependencies
 */
import React from 'react';

/**
 * WordPress dependencies
 */
import { executeAbility } from '@wordpress/abilities';
import { Button } from '@wordpress/components';
import { dispatch, select, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useState } from '@wordpress/element';
import { update } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

const { aiExcerptGenerationData } = window as any;

/**
 * Generates an excerpt for the given post ID and content.
 *
 * @param {number} postId  The ID of the post to generate an excerpt for.
 * @param {string} content The content of the post to generate an excerpt for.
 * @return {Promise<string>} A promise that resolves to the generated excerpt.
 */
async function generateExcerpt(
	postId: number,
	content: string
): Promise< string > {
	return executeAbility( 'ai/excerpt-generation', {
		content,
		post_id: postId,
	} )
		.then( ( response ) => {
			if ( response && typeof response === 'string' ) {
				return response;
			}

			return '';
		} )
		.catch( ( error ) => {
			throw new Error( `Error generating excerpt: ${ error.message }` );
		} );
}

/**
 * ExcerptGeneration component.
 *
 * Provides a button to generate an excerpt.
 *
 * @return {JSX.Element | null} The excerpt generation component.
 */
export default function ExcerptGeneration(): JSX.Element | null {
	const postId = select( editorStore ).getCurrentPostId();
	const content = select( editorStore ).getEditedPostContent();
	const excerpt = select( editorStore ).getEditedPostAttribute( 'excerpt' );

	const { editPost } = useDispatch( editorStore );

	const [ isGenerating, setIsGenerating ] = useState< boolean >( false );

	const hasExcerpt = excerpt && excerpt.trim().length > 0;
	const buttonLabel = hasExcerpt
		? __( 'Re-generate excerpt', 'ai' )
		: __( 'Generate excerpt', 'ai' );

	/**
	 * Handles the generate/re-generate button click.
	 */
	const handleGenerate = async () => {
		setIsGenerating( true );
		( dispatch( noticesStore ) as any ).removeNotice(
			'ai_excerpt_generation_error'
		);

		try {
			const generatedExcerpt = await generateExcerpt( postId, content );
			editPost( {
				excerpt: generatedExcerpt,
			} );
		} catch ( error: any ) {
			( dispatch( noticesStore ) as any ).createErrorNotice( error, {
				id: 'ai_excerpt_generation_error',
				isDismissible: true,
			} );
		} finally {
			setIsGenerating( false );
		}
	};

	// Ensure the experiment is enabled.
	if ( ! aiExcerptGenerationData?.enabled ) {
		return null;
	}

	return (
		<Button
			icon={ update }
			variant="secondary"
			onClick={ handleGenerate }
			disabled={ isGenerating }
			isBusy={ isGenerating }
		>
			{ buttonLabel }
		</Button>
	);
}
