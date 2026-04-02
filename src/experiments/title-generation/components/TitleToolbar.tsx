/**
 * Title toolbar component for generating post titles.
 */

/**
 * WordPress dependencies
 */
import { ToolbarGroup, ToolbarButton } from '@wordpress/components';
import { dispatch, select, useDispatch } from '@wordpress/data';
import { store as editorStore, PostTypeSupportCheck } from '@wordpress/editor';
import { useState } from '@wordpress/element';
import { update } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';
import type { TitleGenerationAbilityInput, GeneratedTitleData } from '../types';

const { aiTitleGenerationData } = window as any;

/**
 * Generates a title for the given post ID and content.
 *
 * @param {number} postId  The ID of the post to generate a title for.
 * @param {string} content The content of the post to generate a title for.
 * @return {Promise<string>} A promise that resolves to the generated title.
 */
async function generateTitle(
	postId: number,
	content: string
): Promise< string > {
	const params: TitleGenerationAbilityInput = {
		context: postId.toString(),
		content,
	};

	const response = await runAbility< GeneratedTitleData >(
		'ai/title-generation',
		params
	);

	if (
		response &&
		typeof response === 'object' &&
		'title' in response &&
		typeof response.title === 'string' &&
		response.title.length > 0
	) {
		return response.title;
	}

	throw new Error( __( 'No title suggestion was generated.', 'ai' ) );
}

/**
 * TitleToolbar component.
 *
 * Provides Generate/Re-generate button.
 *
 * @return {JSX.Element} The toolbar component.
 */
export default function TitleToolbar(): JSX.Element | null {
	const postId = select( editorStore ).getCurrentPostId();
	const content = select( editorStore ).getEditedPostContent();
	const title = select( editorStore ).getEditedPostAttribute( 'title' );

	const { editPost } = useDispatch( editorStore );

	const [ isGenerating, setIsGenerating ] = useState< boolean >( false );

	const hasTitle = title.trim().length > 0;
	const buttonLabel = hasTitle
		? __( 'Re-generate', 'ai' )
		: __( 'Generate', 'ai' );

	/**
	 * Handles the generate/re-generate button click.
	 */
	const handleGenerate = async () => {
		setIsGenerating( true );
		( dispatch( noticesStore ) as any ).removeNotice(
			'ai_title_generation_error'
		);

		try {
			const generatedTitle = await generateTitle(
				postId as number,
				content
			);
			editPost( { title: generatedTitle } );
		} catch ( error: any ) {
			( dispatch( noticesStore ) as any ).createErrorNotice( error, {
				id: 'ai_title_generation_error',
				isDismissible: true,
			} );
		} finally {
			setIsGenerating( false );
		}
	};

	// Don't render if disabled.
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
			</ToolbarGroup>
		</PostTypeSupportCheck>
	);
}
