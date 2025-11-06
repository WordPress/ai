/**
 * Title toolbar component for generating and transforming post titles.
 *
 * @package WordPress\AI
 */

import * as React from 'react';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	ToolbarGroup,
	ToolbarButton,
	DropdownMenu,
	MenuGroup,
	MenuItem,
} from '@wordpress/components';
import { update, chevronDown } from '@wordpress/icons';
import { useState } from '@wordpress/element';
import { toSentenceCase, toTitleCase } from '../utils/casing';

/**
 * Placeholder function for title generation API call.
 *
 * @param {string} content - The post content to generate title from.
 * @return {Promise<string>} A promise that resolves to the generated title.
 */
async function generateTitle( content: string ): Promise< string > {
	// TODO: Connect to actual API endpoint
	// For now, return a placeholder
	return Promise.resolve( 'Generated Title' );
}

/**
 * TitleToolbar component.
 *
 * Provides Generate/Re-generate button and casing options dropdown.
 *
 * @return {JSX.Element} The toolbar component.
 */
export default function TitleToolbar(): JSX.Element {
	const title = useSelect( ( select ) => {
		return select( 'core/editor' ).getEditedPostAttribute( 'title' ) || '';
	}, [] );

	const content = useSelect( ( select ) => {
		return select( 'core/editor' ).getEditedPostContent() || '';
	}, [] );

	const { editPost } = useDispatch( 'core/editor' );

	const [ isGenerating, setIsGenerating ] = useState( false );

	const hasTitle = title.trim().length > 0;
	const buttonLabel = hasTitle ? 'Re-generate' : 'Generate';

	/**
	 * Handles the generate/re-generate button click.
	 */
	const handleGenerate = async () => {
		setIsGenerating( true );
		try {
			const generatedTitle = await generateTitle( content );
			editPost( { title: generatedTitle } );
		} catch ( error ) {
			// TODO: Handle error appropriately
			console.error( 'Error generating title:', error );
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

	return (
		<ToolbarGroup>
			<ToolbarButton
				icon={ update }
				label={ buttonLabel }
				onClick={ handleGenerate }
				disabled={ isGenerating }
			>
				{ buttonLabel }
			</ToolbarButton>
			{ hasTitle && (
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
							<MenuGroup label="OPTIONS">
								<MenuItem
									onClick={ () => {
										handleCasingChange( 'sentence' );
										onClose();
									} }
								>
									Sentence Case
								</MenuItem>
								<MenuItem
									onClick={ () => {
										handleCasingChange( 'title' );
										onClose();
									} }
								>
									Title Case
								</MenuItem>
							</MenuGroup>
						</>
					) }
				</DropdownMenu>
			) }
		</ToolbarGroup>
	);
}

