/**
 * Renders a tool's post list as a read-only DataViews table inside a message.
 */

/**
 * WordPress dependencies
 */
import { DataViews, type Field, type View } from '@wordpress/dataviews/wp';
import { dateI18n, getSettings } from '@wordpress/date';
import { useCallback, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { PostResultRow, PostResultSet } from '../types';

/**
 * Formats a post date for display, falling back to the raw value.
 *
 * @param value The ISO 8601 date reported by the ability.
 * @return The formatted date.
 */
function formatDate( value: string ): string {
	if ( '' === value ) {
		return '—';
	}

	const { formats } = getSettings();

	return dateI18n( formats.date, value );
}

/**
 * Renders one tool result as a fixed, read-only table.
 *
 * The table is deliberately plainer than the one on the request-log screen:
 * inside a chat message there is no room for a search box, filter chips or a
 * layout switcher, and the rows are a snapshot of one tool call rather than a
 * dataset to explore. The view state is therefore fixed and controlled — the
 * component composes only the layout, so none of that chrome renders — and no
 * field is sortable, hideable or filterable.
 *
 * Every value shown came back from the ability, which filtered its rows by the
 * requesting user's own capabilities at execute time. Nothing is fetched here.
 *
 * @param props        Component props.
 * @param props.result The narrowed post list.
 * @return The rendered table, or an empty state.
 */
export default function ResultsTable( { result }: { result: PostResultSet } ) {
	const [ view, setView ] = useState< View >( () => ( {
		type: 'table',
		page: 1,
		perPage: Math.max( 1, result.results.length ),
		fields: [ 'post_type', 'status', 'date' ],
		titleField: 'title',
		layout: {},
	} ) );

	// The view is fixed, so changes are accepted only to keep DataViews
	// controlled; nothing here re-queries or re-sorts the rows.
	const onChangeView = useCallback( ( next: View ) => {
		setView( next );
	}, [] );

	const fields = useMemo< Field< PostResultRow >[] >(
		() => [
			{
				id: 'title',
				label: __( 'Title', 'ai' ),
				enableSorting: false,
				enableHiding: false,
				enableGlobalSearch: false,
				filterBy: false,
				render: ( { item }: { item: PostResultRow } ) => (
					<a
						href={ item.link }
						target="_blank"
						rel="noreferrer noopener"
					>
						{ '' === item.title
							? __( '(no title)', 'ai' )
							: item.title }
					</a>
				),
			},
			{
				id: 'post_type',
				label: __( 'Type', 'ai' ),
				enableSorting: false,
				enableHiding: false,
				enableGlobalSearch: false,
				filterBy: false,
			},
			{
				id: 'status',
				label: __( 'Status', 'ai' ),
				enableSorting: false,
				enableHiding: false,
				enableGlobalSearch: false,
				filterBy: false,
			},
			{
				id: 'date',
				label: __( 'Date', 'ai' ),
				enableSorting: false,
				enableHiding: false,
				enableGlobalSearch: false,
				filterBy: false,
				render: ( { item }: { item: PostResultRow } ) => (
					<span>{ formatDate( item.date ) }</span>
				),
			},
		],
		[]
	);

	const actions = useMemo(
		() => [
			{
				id: 'edit-post',
				label: __( 'Edit', 'ai' ),
				isPrimary: true,
				/*
				 * Only rows the ability reported as editable offer this. A row
				 * that carries no editor URL is one the tool did not vouch for,
				 * and guessing one would produce an action that denies.
				 */
				isEligible: ( item: PostResultRow ) =>
					typeof item.edit_link === 'string' && '' !== item.edit_link,
				callback: ( items: PostResultRow[] ) => {
					const target = items[ 0 ]?.edit_link;

					if ( target ) {
						window.location.assign( target );
					}
				},
			},
		],
		[]
	);

	const paginationInfo = useMemo(
		() => ( {
			totalItems: result.results.length,
			totalPages: 1,
		} ),
		[ result.results.length ]
	);

	const defaultLayouts = useMemo( () => ( { table: {} } ), [] );

	if ( 0 === result.results.length ) {
		return (
			<p className="ai-workspace__results-empty">
				{ __( 'That search matched nothing you can read.', 'ai' ) }
			</p>
		);
	}

	return (
		<div className="ai-workspace__results">
			{ /*
			 * The wrapper caps its own height and scrolls, so a long result set
			 * stays inside the message instead of stretching the transcript.
			 * `minHeight: 0` keeps it from refusing to shrink in the flex column
			 * the transcript lays out.
			 */ }
			<div
				style={ {
					minHeight: 0,
					maxHeight: '24rem',
					overflow: 'auto',
				} }
			>
				<DataViews
					data={ result.results }
					fields={ fields }
					view={ view }
					onChangeView={ onChangeView }
					actions={ actions }
					paginationInfo={ paginationInfo }
					defaultLayouts={ defaultLayouts }
					getItemId={ ( item: PostResultRow ) => String( item.id ) }
					search={ false }
				>
					<DataViews.Layout />
				</DataViews>
			</div>
			{ result.total > result.results.length && (
				<p className="ai-workspace__results-note">
					{ sprintf(
						/* translators: 1: number of posts shown, 2: number of posts matched. */
						__(
							'Showing %1$d of %2$d matching posts. The rest are outside this result page or not readable by your account.',
							'ai'
						),
						result.results.length,
						result.total
					) }
				</p>
			) }
		</div>
	);
}
