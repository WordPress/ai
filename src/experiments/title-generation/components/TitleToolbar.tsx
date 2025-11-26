/**
 * Title toolbar component for generating post titles.
 */

/**
 * External dependencies
 */
import React from 'react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Flex,
	FlexItem,
	Modal,
	TextareaControl,
	ToolbarGroup,
	ToolbarButton,
} from '@wordpress/components';
import { dispatch, select, useDispatch } from '@wordpress/data';
import { store as editorStore, PostTypeSupportCheck } from '@wordpress/editor';
import { useState } from '@wordpress/element';
import { update } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

const { aiTitleGenerationData } = window as any;

/**
 * Renders a single title option.
 *
 * @param {Object}   props          Component props.
 * @param {string}   props.title    The title value to display.
 * @param {number}   props.index    The index of this title in the array.
 * @param {Function} props.onChange Callback to update this title's value.
 * @param {Function} props.onSelect Callback when this title is selected.
 * @return {JSX.Element} The rendered title option.
 */
function TitleOption( {
	title,
	index,
	onChange,
	onSelect,
}: {
	title: string;
	index: number;
	onChange: ( value: string ) => void;
	onSelect: ( title: string, index: number ) => void;
} ): JSX.Element {
	return (
		<FlexItem className="ai-title">
			<TextareaControl
				rows={ 2 }
				label={ __( 'Generated title', 'ai' ) }
				hideLabelFromVision
				value={ title }
				onChange={ onChange }
				__nextHasNoMarginBottom
			/>
			<Button
				variant="secondary"
				style={ { marginTop: '15px' } }
				onClick={ () => onSelect( title, index ) }
			>
				{ __( 'Select', 'ai' ) }
			</Button>
		</FlexItem>
	);
}

/**
 * Renders the generated title data with editable textareas.
 *
 * @param {Object}   props               Component props.
 * @param {string[]} props.titles        The array of titles to render.
 * @param {Function} props.onTitleChange Callback to update the title array.
 * @param {Function} props.onSelect      Callback when a title is selected.
 * @return {JSX.Element | null} The rendered titles.
 */
function TitleOptionsList( {
	titles: titlesToRender,
	onTitleChange,
	onSelect,
}: {
	titles: string[];
	onTitleChange: ( newTitle: string[] ) => void;
	onSelect: ( title: string, index: number ) => void;
} ): JSX.Element | null {
	if ( ! titlesToRender || titlesToRender.length === 0 ) {
		return null;
	}

	return (
		<Flex gap="5" wrap direction="column">
			{ titlesToRender.map( ( title: string, i: number ) => (
				<TitleOption
					key={ `title-${ i }` }
					title={ title }
					index={ i }
					onChange={ ( value: string ) => {
						onTitleChange(
							titlesToRender.map( ( item, index ) =>
								index === i ? value : item
							)
						);
					} }
					onSelect={ onSelect }
				/>
			) ) }
		</Flex>
	);
}

/**
 * Generates titles for the given post ID and content.
 *
 * @param {number} postId  The ID of the post to generate a title for.
 * @param {string} content The content of the post to generate a title for.
 * @return {Promise<string[]>} A promise that resolves to the generated titles.
 */
async function generateTitles(
	postId: number,
	content: string
): Promise< string[] > {
	return apiFetch( {
		path: 'wp-abilities/v1/abilities/ai/title-generation/run',
		method: 'POST',
		data: {
			input: {
				post_id: postId,
				content,
			},
		},
	} )
		.then( ( response ) => {
			if (
				response &&
				typeof response === 'object' &&
				'titles' in response
			) {
				return response.titles as string[];
			}

			return [];
		} )
		.catch( ( error ) => {
			throw new Error( `Error generating titles: ${ error.message }` );
		} );
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
	const [ isOpen, setOpen ] = useState< boolean >( false );
	const [ titles, setTitles ] = useState< string[] >( [] );

	const openModal = () => setOpen( true );
	const closeModal = () => {
		setOpen( false );
		setTitles( [] );
	};

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
			const generatedTitles = await generateTitles( postId, content );
			setTitles( generatedTitles );
			openModal();
		} catch ( error: any ) {
			( dispatch( noticesStore ) as any ).createErrorNotice( error, {
				id: 'ai_title_generation_error',
				isDismissible: true,
			} );
			setTitles( [] );
		} finally {
			setIsGenerating( false );
		}
	};

	/**
	 * Handles selecting a title.
	 *
	 * @param {string} selectedTitle The selected title.
	 */
	const handleSelectTitle = async ( selectedTitle: string ) => {
		editPost( {
			title: selectedTitle,
		} );
		closeModal();
	};

	// Ensure the experiment is enabled.
	if ( ! aiTitleGenerationData?.enabled ) {
		return null;
	}

	return (
		<>
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
			{ isOpen && (
				<Modal
					title={ __( 'Select a title', 'ai' ) }
					onRequestClose={ closeModal }
					isFullScreen={ false }
					size="medium"
					className="ai-title-generation-modal"
				>
					{ titles && (
						<TitleOptionsList
							titles={ titles }
							onTitleChange={ setTitles }
							onSelect={ handleSelectTitle }
						/>
					) }
				</Modal>
			) }
		</>
	);
}
