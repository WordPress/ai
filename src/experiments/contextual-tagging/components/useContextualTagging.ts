/**
 * Shared hook for contextual tagging logic.
 */

/**
 * WordPress dependencies
 */
import { dispatch, resolveSelect, select } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { store as editorStore } from '@wordpress/editor';
import { useState, useCallback } from '@wordpress/element';
import { store as noticesStore } from '@wordpress/notices';
import { count as wordCount } from '@wordpress/wordcount';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';
import type {
	ContextualTaggingAbilityInput,
	ContextualTaggingResponse,
	TagSuggestion,
	ContextualTaggingData,
} from '../types';

const MINIMUM_WORD_COUNT = 150;
const NOTICE_ID = 'ai_contextual_tagging_error';

const getSettings = (): ContextualTaggingData =>
	( window as any ).aiContextualTaggingData ?? {
		enabled: false,
		strategy: 'existing_only',
		maxSuggestions: 5,
	};

/**
 * Generates taxonomy suggestions for the given post.
 *
 * @param postId         The post ID.
 * @param content        The post content.
 * @param taxonomy       The taxonomy to suggest terms for.
 * @param strategy       The suggestion strategy.
 * @param maxSuggestions The maximum number of suggestions.
 * @return A promise that resolves to the generated suggestions.
 */
async function generateSuggestions(
	postId: number,
	content: string,
	taxonomy: string,
	strategy: string,
	maxSuggestions: number
): Promise< TagSuggestion[] > {
	const params: ContextualTaggingAbilityInput = {
		content,
		post_id: postId,
		taxonomy,
		strategy,
		max_suggestions: maxSuggestions,
	};

	const response = await runAbility< ContextualTaggingResponse >(
		'ai/contextual-tagging',
		params
	);

	if ( response?.suggestions && Array.isArray( response.suggestions ) ) {
		return response.suggestions;
	}

	return [];
}

/**
 * Hook for contextual tagging functionality.
 *
 * @param taxonomy The taxonomy to generate suggestions for.
 * @return Object with generation state, suggestions, and handlers.
 */
export function useContextualTagging( taxonomy: string ): {
	isGenerating: boolean;
	suggestions: TagSuggestion[];
	hasEnoughContent: boolean;
	handleGenerate: () => Promise< void >;
	handleAccept: ( suggestion: TagSuggestion ) => void;
	handleDismiss: ( suggestion: TagSuggestion ) => void;
	handleDismissAll: () => void;
} {
	const postId = select( editorStore ).getCurrentPostId() as number;
	const content = select( editorStore ).getEditedPostContent();
	const [ isGenerating, setIsGenerating ] = useState< boolean >( false );
	const [ suggestions, setSuggestions ] = useState< TagSuggestion[] >( [] );
	const { removeNotice, createErrorNotice } = dispatch( noticesStore ) as any;

	// Check if content has enough words.
	const hasEnoughContent =
		wordCount( content || '', 'words' ) >= MINIMUM_WORD_COUNT;

	const handleGenerate = useCallback( async () => {
		const settings = getSettings();
		setIsGenerating( true );
		setSuggestions( [] );

		// Remove any existing error notices.
		removeNotice( NOTICE_ID );

		try {
			// Generate suggestions.
			const result = await generateSuggestions(
				postId,
				content,
				taxonomy,
				settings.strategy,
				settings.maxSuggestions
			);

			// Update the suggestions state.
			setSuggestions( result );
		} catch ( error: any ) {
			// Create an error notice.
			createErrorNotice( error?.message || error, {
				id: NOTICE_ID,
				isDismissible: true,
			} );
		} finally {
			setIsGenerating( false );
		}
	}, [ postId, content, taxonomy ] );

	// Remove a suggestion from the list.
	const removeSuggestionFromList = ( suggestion: TagSuggestion ) => {
		setSuggestions( ( prev ) =>
			prev.filter( ( s ) => s.term !== suggestion.term )
		);
	};

	// Handle accepting a suggestion.
	const handleAccept = useCallback(
		( suggestion: TagSuggestion ) => {
			removeSuggestionFromList( suggestion );
			addTermToPost( taxonomy, suggestion );
		},
		[ taxonomy ]
	);

	// Handle dismissing a suggestion.
	const handleDismiss = useCallback( ( suggestion: TagSuggestion ) => {
		removeSuggestionFromList( suggestion );
	}, [] );

	// Handle dismissing all suggestions.
	const handleDismissAll = useCallback( () => {
		setSuggestions( [] );
	}, [] );

	return {
		isGenerating,
		suggestions,
		hasEnoughContent,
		handleGenerate,
		handleAccept,
		handleDismiss,
		handleDismissAll,
	};
}

/**
 * Adds a term to the current post.
 *
 * @param taxonomy   The taxonomy slug.
 * @param suggestion The suggestion to add.
 */
async function addTermToPost(
	taxonomy: string,
	suggestion: TagSuggestion
): Promise< void > {
	const { editPost }: any = dispatch( editorStore );
	const { getEditedPostAttribute } = select( editorStore );

	const taxonomyObject: any = select( coreStore ).getTaxonomy( taxonomy );
	const restBase = taxonomyObject?.rest_base ?? taxonomy;

	const currentTerms: string[] = getEditedPostAttribute( restBase ) ?? [];
	const termId = await findOrCreateTerm( restBase, suggestion.term );

	if ( termId && ! currentTerms.includes( termId as any ) ) {
		editPost( {
			[ restBase ]: [ ...currentTerms, termId ],
		} );
	}
}

/**
 * Finds an existing term by name or creates a new one.
 *
 * @param restBase The REST base for the taxonomy.
 * @param termName The term name.
 * @return The term ID, or null if not found and could not be created.
 */
async function findOrCreateTerm(
	restBase: string,
	termName: string
): Promise< number | null > {
	try {
		// Search for existing term.
		const searchResults: any[] = await (
			resolveSelect( coreStore ) as any
		 ).getEntityRecords( 'taxonomy', restBase, {
			search: termName,
			per_page: 100,
		} );

		// If we have a direct match, return its ID.
		if ( Array.isArray( searchResults ) ) {
			const match = searchResults.find(
				( t: any ) => t.name.toLowerCase() === termName.toLowerCase()
			);
			if ( match ) {
				return match.id;
			}
		}

		// Create new term via REST.
		const newTerm: any = await apiFetch( {
			path: `/wp/v2/${ restBase }`,
			method: 'POST',
			data: { name: termName },
		} );

		return newTerm?.id ?? null;
	} catch {
		return null;
	}
}
