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
				// Only rows the ability reported as editable carry an edit link.
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
			{ /* `minHeight: 0` lets the scroll wrapper shrink in the transcript's flex column. */ }
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
