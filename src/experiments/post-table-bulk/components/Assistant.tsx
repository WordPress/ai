/**
 * WordPress dependencies
 */
import { Button, Notice, Spinner } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';

type Suggestion = {
	term_id: number | null;
	name: string;
	confidence: number;
	is_new: boolean;
};

type TaxonomySuggestionMap = Record< string, Suggestion[] >;

type AbilityResponse = {
	suggestions: Record< string, TaxonomySuggestionMap >;
	metadata?: {
		taxonomies?: Record< string, string >;
	};
};

type TaxonomyDefinition = {
	name: string;
	label: string;
	hierarchical: boolean;
};

type Props = {
	mode: 'bulk' | 'quick';
	ability: string;
	taxonomies: TaxonomyDefinition[];
	maxBatchSize: number;
	suggestionLimit: number;
};

type Status = 'idle' | 'loading' | 'success' | 'error';

const getFormElement = ( mode: 'bulk' | 'quick' ): HTMLElement | null =>
	document.getElementById( mode === 'bulk' ? 'bulk-edit' : 'edit' );

const sanitizePostIds = ( ids: number[] ): number[] =>
	Array.from( new Set( ids.filter( ( id ) => Number.isFinite( id ) && id > 0 ) ) );

const getQuickPostIds = (): number[] => {
	const input =
		document.querySelector<HTMLInputElement>( '#edit input[name="post_ID"]' );

	if ( ! input ) {
		return [];
	}

	return sanitizePostIds( [ parseInt( input.value, 10 ) ] );
};

const fallbackBulkSelection = (): number[] =>
	Array.from(
		document.querySelectorAll<HTMLInputElement>(
			'#the-list input[name="post[]"]:checked'
		)
	).map( ( node ) => parseInt( node.value, 10 ) );

const getBulkPostIds = (): number[] => {
	const inlineEdit = ( window as Record< string, any > ).inlineEditPost;

	if ( inlineEdit && typeof inlineEdit.getIdList === 'function' ) {
		const list = inlineEdit.getIdList();

		if ( Array.isArray( list ) ) {
			return sanitizePostIds( list.map( ( id ) => parseInt( id, 10 ) ) );
		}

		if ( typeof list === 'string' ) {
			return sanitizePostIds(
				list
					.split( ',' )
					.map( ( id: string ) => parseInt( id, 10 ) )
					.filter( Boolean )
			);
		}
	}

	return sanitizePostIds( fallbackBulkSelection() );
};

const aggregateBulkSuggestions = (
	results: Record< string, TaxonomySuggestionMap >,
	taxonomies: TaxonomyDefinition[],
	limit: number
): TaxonomySuggestionMap => {
	const aggregates: Record<
		string,
		Record<
			string,
			{
				item: Suggestion;
				count: number;
				totalScore: number;
			}
		>
	> = {};

	Object.values( results ).forEach( ( taxMap ) => {
		Object.entries( taxMap ).forEach( ( [ taxonomy, suggestions ] ) => {
			if ( ! aggregates[ taxonomy ] ) {
				aggregates[ taxonomy ] = {};
			}

			suggestions.forEach( ( suggestion ) => {
				const key =
					suggestion.term_id !== null
						? String( suggestion.term_id )
						: suggestion.name.toLowerCase();

				if ( ! aggregates[ taxonomy ][ key ] ) {
					aggregates[ taxonomy ][ key ] = {
						item: suggestion,
						count: 0,
						totalScore: 0,
					};
				}

				aggregates[ taxonomy ][ key ].count += 1;
				aggregates[ taxonomy ][ key ].totalScore += suggestion.confidence;
			} );
		} );
	} );

	const ordered: TaxonomySuggestionMap = {};

	taxonomies.forEach( ( taxonomy ) => {
		const entries = Object.values( aggregates[ taxonomy.name ] || {} )
			.sort( ( a, b ) => {
				if ( b.count === a.count ) {
					return b.totalScore - a.totalScore;
				}

				return b.count - a.count;
			} )
			.slice( 0, limit )
			.map( ( entry ) => entry.item );

		if ( entries.length ) {
			ordered[ taxonomy.name ] = entries;
		}
	} );

	return ordered;
};

const Assistant = ( {
	mode,
	ability,
	taxonomies,
	maxBatchSize,
	suggestionLimit,
}: Props ) => {
	const [ status, setStatus ] = useState< Status >( 'idle' );
	const [ error, setError ] = useState< string | null >( null );
	const [ notice, setNotice ] = useState< string | null >( null );
	const [ response, setResponse ] = useState< AbilityResponse | null >( null );
	const [ lastPostIds, setLastPostIds ] = useState< number[] >( [] );

	const displayedSuggestions = useMemo( () => {
		if ( ! response ) {
			return null;
		}

		if ( mode === 'quick' ) {
			const postId = lastPostIds[ 0 ];

			if ( ! postId ) {
				return null;
			}

			return response.suggestions?.[ String( postId ) ] ?? null;
		}

		return aggregateBulkSuggestions(
			response.suggestions ?? {},
			taxonomies,
			suggestionLimit
		);
	}, [ response, mode, lastPostIds, taxonomies, suggestionLimit ] );

	const selectedCount = lastPostIds.length;

	const fetchSuggestions = async () => {
		const ids =
			mode === 'quick' ? getQuickPostIds() : getBulkPostIds().slice( 0, maxBatchSize );

		if ( ids.length === 0 ) {
			setError(
				mode === 'quick'
					? __( 'Open quick edit for a post before requesting suggestions.', 'ai' )
					: __( 'Select at least one post before requesting suggestions.', 'ai' )
			);
			setStatus( 'error' );
			return;
		}

		setStatus( 'loading' );
		setError( null );
		setNotice( null );

		try {
			const payload = {
				post_ids: ids,
				taxonomies: taxonomies.map( ( taxonomy ) => taxonomy.name ),
				limit: suggestionLimit,
				locale: document.documentElement.lang,
			};
			const result = ( await runAbility( ability, payload ) ) as AbilityResponse;

			if ( ! result || ! result.suggestions ) {
				throw new Error(
					__( 'No suggestions were returned for the selected posts.', 'ai' )
				);
			}

			setResponse( result );
			setLastPostIds( ids );
			setStatus( 'success' );
		} catch ( err ) {
			const message =
				err instanceof Error ? err.message : __( 'Unknown error.', 'ai' );
			setError( message );
			setStatus( 'error' );
		}
	};

	const applySuggestions = ( taxonomy: string, suggestions: Suggestion[] ) => {
		const form = getFormElement( mode );

		if ( ! form ) {
			setError(
				__( 'Unable to locate the inline edit form to apply suggestions.', 'ai' )
			);
			setStatus( 'error' );
			return;
		}

		if ( taxonomy === 'post_tag' ) {
			const field =
				form.querySelector<HTMLInputElement | HTMLTextAreaElement>(
					'[name="tax_input[post_tag]"]'
				);

			if ( ! field ) {
				return;
			}

			const existing = field.value
				.split( ',' )
				.map( ( value ) => value.trim() )
				.filter( Boolean );
			const additions = suggestions.map( ( suggestion ) => suggestion.name.trim() );

			const merged = Array.from( new Set( [ ...existing, ...additions ] ) ).filter(
				Boolean
			);
			field.value = merged.join( ', ' );
		} else {
			suggestions.forEach( ( suggestion ) => {
				if ( suggestion.term_id === null ) {
					return;
				}

				form
					.querySelectorAll<HTMLInputElement>(
						`input[name="tax_input[${ taxonomy }][]"][value="${ suggestion.term_id }"]`
					)
					.forEach( ( input ) => {
						input.checked = true;
					} );
			} );
		}

		setNotice(
			sprintf(
				/* translators: %s: Taxonomy label. */
				__( 'Applied %s suggestions to the form. Review and update to save.', 'ai' ),
				response?.metadata?.taxonomies?.[ taxonomy ] ?? taxonomy
			)
		);
	};

	const renderTaxonomySections = () => {
		if ( ! displayedSuggestions || Object.keys( displayedSuggestions ).length === 0 ) {
			return null;
		}

		return Object.entries( displayedSuggestions ).map(
			( [ taxonomy, suggestions ] ) => {
				const label =
					response?.metadata?.taxonomies?.[ taxonomy ] ??
					taxonomies.find( ( tax ) => tax.name === taxonomy )?.label ??
					taxonomy;

				return (
					<div className="wp-ai-post-table-bulk__taxonomy" key={ taxonomy }>
						<p className="wp-ai-post-table-bulk__taxonomy-heading">
							{ label }
						</p>
						<ul className="wp-ai-post-table-bulk__list">
							{ suggestions.map( ( suggestion ) => (
								<li key={ `${ taxonomy }-${ suggestion.name }` }>
									<strong>{ suggestion.name }</strong>{' '}
									<span className="wp-ai-post-table-bulk__confidence">
										{ sprintf(
											/* translators: %s: confidence percentage. */
											__( '%s confidence', 'ai' ),
											Math.round( suggestion.confidence * 100 ) + '%'
										) }
									</span>
									{ suggestion.is_new && (
										<span className="wp-ai-post-table-bulk__badge">
											{ __( 'New term', 'ai' ) }
										</span>
									) }
								</li>
							) ) }
						</ul>
						<Button
							variant="secondary"
							size="small"
							onClick={ () => applySuggestions( taxonomy, suggestions ) }
						>
							{ __( 'Apply suggestions', 'ai' ) }
						</Button>
					</div>
				);
			}
		);
	};

	return (
		<div className="wp-ai-post-table-bulk">
			<Button
				variant="secondary"
				onClick={ fetchSuggestions }
				disabled={ status === 'loading' }
			>
				{ status === 'loading'
					? __( 'Generating suggestions...', 'ai' )
					: __( 'Suggest categories & tags', 'ai' ) }
			</Button>

			{ status === 'loading' && (
				<div className="wp-ai-post-table-bulk__status">
					<Spinner />
					<span>{ __( 'Analyzing selected posts...', 'ai' ) }</span>
				</div>
			) }

			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			{ notice && (
				<Notice status="success" onRemove={ () => setNotice( null ) }>
					{ notice }
				</Notice>
			) }

			{ status === 'success' && selectedCount > 0 && (
				<p className="wp-ai-post-table-bulk__summary">
					{ sprintf(
						/* translators: %d: number of posts. */
						__( 'Suggestions generated for %d post(s).', 'ai' ),
						selectedCount
					) }
				</p>
			) }

			{ renderTaxonomySections() }
		</div>
	);
};

export default Assistant;
