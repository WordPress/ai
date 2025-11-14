/**
 * Title toolbar component for generating and transforming post titles.
 */

/**
 * External Dependencies.
 */
import React from 'react';
import { executeAbility } from '@wordpress/abilities';
import {
	ToolbarGroup,
	ToolbarButton,
	DropdownMenu,
	MenuGroup,
	MenuItem,
} from '@wordpress/components';
import { dispatch, useSelect, useDispatch } from '@wordpress/data';
import { PostTypeSupportCheck } from '@wordpress/editor';
import { useState } from '@wordpress/element';
import { update, chevronDown } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';

/**
 * Internal Dependencies.
 */
import { toSentenceCase, toTitleCase } from '../utils/casing';

const { aiTitleGenerationData } = window as any;

/**
 * Generates a title for the given post ID and content.
 *
 * TODO: Handle multiple titles.
 *
 * @param {number} postId  - The ID of the post to generate a title for.
 * @param {string} content - The content of the post to generate a title for.
 * @return {Promise<string>} A promise that resolves to the generated title.
 */
async function generateTitle(
	postId: number,
	content: string
): Promise< string > {
	return executeAbility( 'ai/title-generation', {
		content,
		post_id: postId,
		candidates: 1,
	} )
		.then( ( response ) => {
			if (
				response &&
				typeof response === 'object' &&
				'titles' in response
			) {
				return ( response.titles as string[] )[ 0 ];
			}

			return '';
		} )
		.catch( ( error ) => {
			throw new Error( `Error generating title: ${ error.message }` );
		} );
}

/**
 * TitleToolbar component.
 *
 * Provides Generate/Re-generate button and casing options dropdown.
 *
 * @return {JSX.Element} The toolbar component.
 */
export default function TitleToolbar(): JSX.Element | null {
	const postId = useSelect( ( select ) => {
		return ( select( 'core/editor' ) as any ).getCurrentPostId() ?? 0;
	}, [] );

	const title = useSelect( ( select ) => {
		return (
			( select( 'core/editor' ) as any ).getEditedPostAttribute(
				'title'
			) || ''
		);
	}, [] );

	const content = useSelect( ( select ) => {
		return ( select( 'core/editor' ) as any ).getEditedPostContent() || '';
	}, [] );

	const { editPost } = useDispatch( 'core/editor' );

	const [ isGenerating, setIsGenerating ] = useState( false );

	const hasTitle = title.trim().length > 0;
	const buttonLabel = hasTitle
		? __( 'Re-generate', 'ai' )
		: __( 'Generate', 'ai' );

	/**
	 * Handles the generate/re-generate button click.
	 */
	const handleGenerate = async () => {
		setIsGenerating( true );
		( dispatch( 'core/notices' ) as any ).removeNotice(
			'ai_title_generation_error'
		);

		try {
			const generatedTitle = await generateTitle( postId, content );
			editPost( { title: generatedTitle } );
		} catch ( error ) {
			( dispatch( 'core/notices' ) as any ).createErrorNotice( error, {
				id: 'ai_title_generation_error',
				isDismissible: true,
			} );
		} finally {
			setIsGenerating( false );
		}
	};

	/**
	 * Handles casing option selection from dropdown.
	 *
	 * @param {string} casingType - The casing type to apply ('sentence' or 'title').
	 */
	const handleCasingChange = ( casingType: string ) => {
		if ( ! hasTitle ) {
			return;
		}

		let transformedTitle: string;
		if ( casingType === 'sentence' ) {
			transformedTitle = toSentenceCase( title );
		} else if ( casingType === 'title' ) {
			transformedTitle = toTitleCase( title );
		} else {
			return;
		}

		editPost( { title: transformedTitle } );
	};

	// Ensure the feature is enabled.
	if ( ! aiTitleGenerationData?.enabled ) {
		return null;
	}

	return (
		<PostTypeSupportCheck supportKeys="title">
			<ToolbarGroup>
				<ToolbarButton
					icon={ update }
					label={ buttonLabel }
					onClick={ handleGenerate }
					disabled={ isGenerating }
					isBusy={ isGenerating }
				>
					{ buttonLabel }
				</ToolbarButton>
				{ hasTitle && ! isGenerating && (
					<DropdownMenu
						icon={ chevronDown }
						label="Options"
						toggleProps={ {
							as: ToolbarButton,
						} }
						popoverProps={ {
							placement: 'bottom-start',
						} }
					>
						{ ( { onClose } ) => (
							<>
								<MenuGroup label={ __( 'Options', 'ai' ) }>
									<MenuItem
										onClick={ () => {
											handleCasingChange( 'sentence' );
											onClose();
										} }
									>
										{ __( 'Sentence Case', 'ai' ) }
									</MenuItem>
									<MenuItem
										onClick={ () => {
											handleCasingChange( 'title' );
											onClose();
										} }
									>
										{ __( 'Title Case', 'ai' ) }
									</MenuItem>
								</MenuGroup>
							</>
						) }
					</DropdownMenu>
				) }
			</ToolbarGroup>
		</PostTypeSupportCheck>
	);
}
