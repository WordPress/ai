/**
 * Title toolbar component for generating post titles.
 */

/**
 * WordPress dependencies
 */
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

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';
import type {
	TitleGenerationAbilityInput,
	GeneratedTitlesData,
} from '../types';

const { aiTitleGenerationData } = window as any;

/**
 * Renders a single title option as a choice card with a radio button.
 *
 * @param {Object}   props            Component props.
 * @param {string}   props.title      The title value to display.
 * @param {boolean}  props.isSelected Whether this option is currently selected.
 * @param {boolean}  props.isDisabled Whether controls are disabled (e.g. during regeneration).
 * @param {Function} props.onChange   Callback to update this title's value.
 * @param {Function} props.onSelect   Callback to select this option.
 * @return {JSX.Element} The rendered title option.
 */
function TitleOption( {
	title,
	isSelected,
	isDisabled,
	onChange,
	onSelect,
}: {
	title: string;
	isSelected: boolean;
	isDisabled: boolean;
	onChange: ( value: string ) => void;
	onSelect: () => void;
} ): JSX.Element {
	return (
		// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
		<div
			className={ [
				'ai-title-generation-option',
				isSelected && 'is-selected',
				isDisabled && 'is-disabled',
			]
				.filter( Boolean )
				.join( ' ' ) }
			onClick={ ! isDisabled ? onSelect : undefined }
		>
			<input
				type="radio"
				name="ai-title-selection"
				checked={ isSelected }
				onChange={ onSelect }
				disabled={ isDisabled }
				className="ai-title-generation-option__radio"
				aria-label={ title }
			/>
			<TextareaControl
				rows={ 2 }
				label={ __( 'Generated title', 'ai' ) }
				hideLabelFromVision
				value={ title }
				onChange={ ( value: string ) => {
					if ( ! isSelected ) {
						onSelect();
					}
					onChange( value );
				} }
				disabled={ isDisabled }
				__nextHasNoMarginBottom
			/>
		</div>
	);
}

/**
 * Renders the list of generated title options as a radio group.
 *
 * @param {Object}   props               Component props.
 * @param {string[]} props.titles        The array of titles to render.
 * @param {number}   props.selectedIndex Index of the currently selected title.
 * @param {boolean}  props.isDisabled    Whether controls are disabled.
 * @param {Function} props.onTitleChange Callback to update the titles array.
 * @param {Function} props.onSelect      Callback when an option is selected.
 * @return {JSX.Element | null} The rendered title options.
 */
function TitleOptionsList( {
	titles,
	selectedIndex,
	isDisabled,
	onTitleChange,
	onSelect,
}: {
	titles: string[];
	selectedIndex: number;
	isDisabled: boolean;
	onTitleChange: ( newTitles: string[] ) => void;
	onSelect: ( index: number ) => void;
} ): JSX.Element | null {
	if ( ! titles || titles.length === 0 ) {
		return null;
	}

	return (
		<div
			className="ai-title-generation-options"
			role="radiogroup"
			aria-label={ __( 'Generated title options', 'ai' ) }
		>
			{ titles.map( ( title: string, i: number ) => (
				<TitleOption
					key={ `title-${ i }` }
					title={ title }
					isSelected={ selectedIndex === i }
					isDisabled={ isDisabled }
					onChange={ ( value: string ) => {
						onTitleChange(
							titles.map( ( item, idx ) =>
								idx === i ? value : item
							)
						);
					} }
					onSelect={ () => onSelect( i ) }
				/>
			) ) }
		</div>
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
	const params: TitleGenerationAbilityInput = {
		post_id: postId,
		content,
	};

	return runAbility< GeneratedTitlesData >( 'ai/title-generation', params )
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
 * Provides Generate/Re-generate button and a modal for selecting from
 * AI-generated title suggestions.
 *
 * @return {JSX.Element} The toolbar component.
 */
export default function TitleToolbar(): JSX.Element | null {
	const postId = select( editorStore ).getCurrentPostId();
	const content = select( editorStore ).getEditedPostContent();
	const title = select( editorStore ).getEditedPostAttribute( 'title' );

	const { editPost } = useDispatch( editorStore );

	const [ isGenerating, setIsGenerating ] = useState< boolean >( false );
	const [ isRegenerating, setIsRegenerating ] = useState< boolean >( false );
	const [ isOpen, setOpen ] = useState< boolean >( false );
	const [ titles, setTitles ] = useState< string[] >( [] );
	const [ selectedIndex, setSelectedIndex ] = useState< number >( 0 );

	const openModal = () => setOpen( true );
	const closeModal = () => {
		setOpen( false );
		setTitles( [] );
		setSelectedIndex( 0 );
	};

	const hasTitle = title.trim().length > 0;
	const buttonLabel = hasTitle
		? __( 'Re-generate', 'ai' )
		: __( 'Generate', 'ai' );

	/**
	 * Handles the toolbar Generate/Re-generate button click.
	 */
	const handleGenerate = async () => {
		setIsGenerating( true );
		( dispatch( noticesStore ) as any ).removeNotice(
			'ai_title_generation_error'
		);

		try {
			const generatedTitles = await generateTitles(
				postId as number,
				content
			);
			setTitles( generatedTitles );
			setSelectedIndex( 0 );
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
	 * Handles the Regenerate button inside the modal.
	 * Fetches a new batch of suggestions without closing the modal.
	 */
	const handleRegenerate = async () => {
		setIsRegenerating( true );
		( dispatch( noticesStore ) as any ).removeNotice(
			'ai_title_generation_error'
		);

		try {
			const generatedTitles = await generateTitles(
				postId as number,
				content
			);
			setTitles( generatedTitles );
			setSelectedIndex( 0 );
		} catch ( error: any ) {
			( dispatch( noticesStore ) as any ).createErrorNotice( error, {
				id: 'ai_title_generation_error',
				isDismissible: true,
			} );
		} finally {
			setIsRegenerating( false );
		}
	};

	/**
	 * Applies the selected title to the post and closes the modal.
	 */
	const handleInsert = () => {
		if ( titles[ selectedIndex ] ) {
			editPost( { title: titles[ selectedIndex ] } );
			closeModal();
		}
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
					title={ __(
						'Select a title or regenerate for more options',
						'ai'
					) }
					onRequestClose={ closeModal }
					isFullScreen={ false }
					size="medium"
					className="ai-title-generation-modal"
				>
					{ titles && (
						<TitleOptionsList
							titles={ titles }
							selectedIndex={ selectedIndex }
							isDisabled={ isRegenerating }
							onTitleChange={ setTitles }
							onSelect={ setSelectedIndex }
						/>
					) }
					<Flex
						justify="flex-end"
						gap="3"
						className="ai-title-generation-actions"
					>
						<FlexItem>
							<Button
								variant="secondary"
								onClick={ handleRegenerate }
								disabled={ isRegenerating }
								isBusy={ isRegenerating }
							>
								{ isRegenerating
									? __( 'Regenerating…', 'ai' )
									: __( 'Regenerate', 'ai' ) }
							</Button>
						</FlexItem>
						<FlexItem>
							<Button
								variant="primary"
								onClick={ handleInsert }
								disabled={
									isRegenerating || ! titles[ selectedIndex ]
								}
							>
								{ __( 'Insert', 'ai' ) }
							</Button>
						</FlexItem>
					</Flex>
				</Modal>
			) }
		</>
	);
}
