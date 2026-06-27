/**
 * Shared hook for content classification logic.
 */

/**
 * WordPress dependencies
 */
import { dispatch, select, useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { store as editorStore } from '@wordpress/editor';
import { useState, useCallback, useEffect } from '@wordpress/element';
import { store as noticesStore } from '@wordpress/notices';
import { addQueryArgs } from '@wordpress/url';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';
import { ensureProvider } from '../../../utils/provider-status';
import { hasMinimumContent } from '../../../utils/word-count';
import type {
	ContentClassificationAbilityInput,
	ContentClassificationResponse,
	TagSuggestion,
	ContentClassificationData,
} from '../types';

const MINIMUM_CONTENT_COUNT_DEFAULT = 100;
const NOTICE_ID = 'ai_content_classification_error';
const DEFAULT_MAX_SUGGESTIONS = 5;
const MIN_SUGGESTIONS = 1;
const MAX_SUGGESTIONS = 10;

const suggestionsCache: Record< string, TagSuggestion[] > = {};

const normalizeMaxSuggestions = ( value: unknown ): number => {
	const parsedValue = Number.parseInt(
		String( value ?? DEFAULT_MAX_SUGGESTIONS ),
		10
	);

	if ( Number.isNaN( parsedValue ) ) {
		return DEFAULT_MAX_SUGGESTIONS;
	}

	return Math.min(
		MAX_SUGGESTIONS,
		Math.max( MIN_SUGGESTIONS, parsedValue )
	);
};

const getSettings = (): ContentClassificationData => {
	const settings = ( window as any ).aiContentClassificationData ?? {};

	return {
		enabled: settings.enabled ?? false,
		strategy: settings.strategy ?? 'existing_only',
		maxSuggestions: normalizeMaxSuggestions( settings.maxSuggestions ),
		minContentLength:
			settings.minContentLength ?? MINIMUM_CONTENT_COUNT_DEFAULT,
	};
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
	const params: ContentClassificationAbilityInput = {
		content,
		post_id: postId,
		taxonomy,
		strategy,
		max_suggestions: maxSuggestions,
	};

	const response = await runAbility< ContentClassificationResponse >(
		'ai/content-classification',
		params
	);

	if ( response?.suggestions && Array.isArray( response.suggestions ) ) {
		return response.suggestions;
	}

	return [];
}

/**
 * Gets the lowercase names of terms currently assigned to the post for a taxonomy.
 *
 * @param taxonomy The taxonomy slug.
 * @return A promise that resolves to an array of lowercase term names.
 */
async function getAssignedTermNames( taxonomy: string ): Promise< string[] > {
	const taxonomyObject: any = select( coreStore ).getTaxonomy( taxonomy );
	const restBase = taxonomyObject?.rest_base ?? taxonomy;
	const { getEditedPostAttribute } = select( editorStore );
	const termIds: number[] = getEditedPostAttribute( restBase ) ?? [];

	if ( ! termIds.length ) {
		return [];
	}

	try {
		const terms: any[] = await apiFetch( {
			path: addQueryArgs( `/wp/v2/${ restBase }`, {
				include: termIds.join( ',' ),
				per_page: termIds.length,
			} ),
		} );

		return terms.map( ( t: any ) => t.name.toLowerCase() );
	} catch {
		return [];
	}
}

/**
 * Hook for content classification functionality.
 *
 * @param taxonomy The taxonomy to generate suggestions for.
 * @return Object with generation state, suggestions, and handlers.
 */
export function useContentClassification( taxonomy: string ): {
	isGenerating: boolean;
	suggestions: TagSuggestion[];
	hasEnoughContent: boolean;
	handleGenerate: () => Promise< void >;
	handleAccept: ( suggestion: TagSuggestion ) => void;
	handleDismiss: ( suggestion: TagSuggestion ) => void;
	handleDismissAll: () => void;
	minContentLength: number;
} {
	const { postId, content } = useSelect( ( selectFn ) => {
		const editor = selectFn( editorStore );

		return {
			postId: editor.getCurrentPostId() as number,
			content: editor.getEditedPostContent(),
		};
	}, [] );
	const [ isGenerating, setIsGenerating ] = useState< boolean >( false );
	const [ suggestions, setSuggestions ] = useState< TagSuggestion[] >(
		() => suggestionsCache[ taxonomy ] ?? []
	);

	// Sync suggestions to the cache whenever they change.
	useEffect( () => {
		suggestionsCache[ taxonomy ] = suggestions;
	}, [ taxonomy, suggestions ] );

	const { removeNotice, createErrorNotice } = dispatch( noticesStore );

	const { minContentLength } = getSettings();

	// Check if content has enough words.
	const hasEnoughContent = hasMinimumContent(
		content || '',
		minContentLength
	);

	const handleGenerate = useCallback( async () => {
		if ( ! ensureProvider( NOTICE_ID ) ) {
			return;
		}

		const settings = getSettings();
		setIsGenerating( true );
		setSuggestions( [] );

		// Remove any existing error notices.
		removeNotice( NOTICE_ID );

		try {
			const latestContent = select( editorStore ).getEditedPostContent();

			// Generate suggestions.
			const result = await generateSuggestions(
				postId,
				latestContent,
				taxonomy,
				settings.strategy,
				settings.maxSuggestions
			);

			// Filter out terms already assigned to the post.
			const assignedNames = await getAssignedTermNames( taxonomy );
			const filtered = result.filter(
				( s ) => ! assignedNames.includes( s.term.toLowerCase() )
			);

			// Update the suggestions state.
			setSuggestions( filtered );
		} catch ( error: any ) {
			// Create an error notice.
			createErrorNotice( error?.message || error, {
				id: NOTICE_ID,
				isDismissible: true,
			} );
		} finally {
			setIsGenerating( false );
		}
	}, [ postId, taxonomy, removeNotice, createErrorNotice ] );

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
		minContentLength,
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

	// Resolve parent term ID for hierarchical taxonomies only.
	let parentId: number | undefined;
	if ( suggestion.parent && taxonomyObject?.hierarchical ) {
		const resolvedParent = await findOrCreateTerm(
			taxonomy,
			restBase,
			suggestion.parent
		);
		if ( resolvedParent ) {
			parentId = resolvedParent;
		}
	}

	const currentTerms: number[] = getEditedPostAttribute( restBase ) ?? [];
	const termId = await findOrCreateTerm(
		taxonomy,
		restBase,
		suggestion.term,
		parentId
	);

	if ( termId && ! currentTerms.includes( termId ) ) {
		editPost( {
			[ restBase ]: [ ...currentTerms, termId ],
		} );
	}
}

/**
 * Finds an existing term by name or creates a new one.
 *
 * @param taxonomy The taxonomy slug (e.g., 'category').
 * @param restBase The REST base for the taxonomy (e.g., 'categories').
 * @param termName The term name.
 * @param parentId Optional parent term ID for hierarchical taxonomies.
 * @return The term ID, or null if not found and could not be created.
 */
async function findOrCreateTerm(
	taxonomy: string,
	restBase: string,
	termName: string,
	parentId?: number
): Promise< number | null > {
	try {
		// Search for existing term via REST.
		const searchResults: any[] = await apiFetch( {
			path: addQueryArgs( `/wp/v2/${ restBase }`, {
				search: termName,
				per_page: 100,
			} ),
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

		// Create new term
		const data: Record< string, unknown > = { name: termName };
		if ( parentId ) {
			data[ 'parent' ] = parentId; // eslint-disable-line dot-notation
		}

		const newTerm: any = await (
			dispatch( coreStore ) as any
		 ).saveEntityRecord( 'taxonomy', taxonomy, data );

		return newTerm?.id ?? null;
	} catch ( error: any ) {
		const { createErrorNotice } = dispatch( noticesStore );
		createErrorNotice(
			error?.message ||
				`Could not add term "${ termName }". Please try again.`,
			{
				id: `${ NOTICE_ID }_term_${ termName }`,
				isDismissible: true,
			}
		);
		return null;
	}
}
