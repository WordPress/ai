/**
 * Content Opportunities dashboard widget app.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { escapeHTML } from '@wordpress/escape-html';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';
import type {
	ContentGapSuggestion,
	ContentGapSuggestionsAbilityOutput,
} from '../types';

type CreatedPost = {
	id: number;
};

/**
 * Extracts a readable message from an error thrown by the ability or REST call.
 *
 * REST and ability errors are plain objects with a `message` property rather
 * than `Error` instances.
 *
 * @param error    The caught error.
 * @param fallback Message used when the error has none.
 * @return The message to show the user.
 */
const getErrorMessage = ( error: unknown, fallback: string ): string => {
	if ( error && typeof error === 'object' && 'message' in error ) {
		const { message } = error as { message: unknown };
		if ( typeof message === 'string' && message !== '' ) {
			return message;
		}
	}
	return fallback;
};

const DEFAULT_POST_EDIT_BASE_URL = 'post.php';

const getPostEditBaseUrl = (): string => {
	return (
		window.aiContentGapSuggestionsData?.postEditBaseUrl ??
		DEFAULT_POST_EDIT_BASE_URL
	);
};

/**
 * Renders the Content Opportunities widget: a "Generate" trigger, a list of
 * suggested topics, and a "Create draft" action per suggestion. Nothing is
 * published automatically - "Create draft" only ever creates a draft post
 * that the user opens in the editor to review, edit, and publish themselves.
 *
 * @return The widget UI.
 */
export default function ContentOpportunitiesWidget(): JSX.Element {
	const [ status, setStatus ] = useState<
		'idle' | 'loading' | 'loaded' | 'error'
	>( 'idle' );
	const [ suggestions, setSuggestions ] = useState< ContentGapSuggestion[] >(
		[]
	);
	const [ dismissed, setDismissed ] = useState< Set< number > >( new Set() );
	const [ creatingIndex, setCreatingIndex ] = useState< number | null >(
		null
	);
	const [ errorMessage, setErrorMessage ] = useState< string >( '' );

	const handleGenerate = async (): Promise< void > => {
		setStatus( 'loading' );
		setErrorMessage( '' );

		try {
			const result =
				await runAbility< ContentGapSuggestionsAbilityOutput >(
					'ai/content-gap-suggestions',
					{ limit: 5 }
				);

			setSuggestions( result?.suggestions ?? [] );
			setDismissed( new Set() );
			setStatus( 'loaded' );
		} catch ( error ) {
			setStatus( 'error' );
			setErrorMessage(
				getErrorMessage(
					error,
					__( 'Could not generate content suggestions.', 'ai' )
				)
			);
		}
	};

	const handleDismiss = ( index: number ): void => {
		setDismissed( ( previous ) => new Set( previous ).add( index ) );
	};

	const handleCreateDraft = async (
		suggestion: ContentGapSuggestion,
		index: number
	): Promise< void > => {
		setCreatingIndex( index );
		setErrorMessage( '' );

		try {
			const post = await apiFetch< CreatedPost >( {
				path: '/wp/v2/posts',
				method: 'POST',
				data: {
					title: suggestion.title,
					content: suggestion.outline
						.split( '\n' )
						.filter( ( line ) => line.trim() !== '' )
						.map(
							( line ) =>
								`<!-- wp:paragraph --><p>${ escapeHTML(
									line.replace( /^-\s*/, '' )
								) }</p><!-- /wp:paragraph -->`
						)
						.join( '\n\n' ),
					status: 'draft',
				},
			} );

			handleDismiss( index );
			window.location.href = `${ getPostEditBaseUrl() }?post=${
				post.id
			}&action=edit`;
		} catch ( error ) {
			setErrorMessage(
				getErrorMessage(
					error,
					__( 'Could not create a draft for this idea.', 'ai' )
				)
			);
		} finally {
			setCreatingIndex( null );
		}
	};

	const visibleSuggestions = suggestions
		.map( ( suggestion, index ) => ( { suggestion, index } ) )
		.filter( ( { index } ) => ! dismissed.has( index ) );

	return (
		<div className="ai-content-gap-suggestions__body">
			{ status === 'idle' && (
				<>
					<p>
						{ __(
							'Find new post ideas based on what visitors are searching for.',
							'ai'
						) }
					</p>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						onClick={ handleGenerate }
					>
						{ __( 'Generate suggestions', 'ai' ) }
					</Button>
				</>
			) }

			{ status === 'loading' && (
				<p>
					<Spinner />
					{ __( 'Looking for content opportunities…', 'ai' ) }
				</p>
			) }

			{ errorMessage !== '' && (
				<Notice status="error" isDismissible={ false }>
					{ errorMessage }
				</Notice>
			) }

			{ status === 'error' && (
				<Button
					__next40pxDefaultSize
					variant="secondary"
					onClick={ handleGenerate }
				>
					{ __( 'Try again', 'ai' ) }
				</Button>
			) }

			{ status === 'loaded' && visibleSuggestions.length === 0 && (
				<p>
					{ __(
						'No content opportunities found right now. Check back after more traffic data is available.',
						'ai'
					) }
				</p>
			) }

			{ status === 'loaded' && visibleSuggestions.length > 0 && (
				<ul className="ai-content-gap-suggestions__list">
					{ visibleSuggestions.map( ( { suggestion, index } ) => (
						<li
							className="ai-content-gap-suggestions__item"
							key={ index }
						>
							<p className="ai-content-gap-suggestions__title">
								{ suggestion.title }
							</p>
							<p className="ai-content-gap-suggestions__outline">
								{ suggestion.outline }
							</p>
							<div className="ai-content-gap-suggestions__actions">
								<Button
									__next40pxDefaultSize
									variant="primary"
									isBusy={ creatingIndex === index }
									disabled={ creatingIndex !== null }
									onClick={ () =>
										handleCreateDraft( suggestion, index )
									}
								>
									{ __( 'Create draft', 'ai' ) }
								</Button>
								<Button
									__next40pxDefaultSize
									variant="tertiary"
									disabled={ creatingIndex !== null }
									onClick={ () => handleDismiss( index ) }
								>
									{ __( 'Dismiss', 'ai' ) }
								</Button>
							</div>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}
