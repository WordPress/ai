/**
 * WordPress dependencies
 */
import {
	DataViews,
	filterSortAndPaginate,
	type SupportedLayouts,
	type View,
} from '@wordpress/dataviews/wp';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	BUILT_IN_FIELD_IDS,
	DEFAULT_VISIBLE_FIELDS,
	TITLE_FIELD_ID,
	useFields,
} from '../fields';
import type { AbilityListItem } from '../types';
import {
	appendNewFields,
	loadSavedView,
	mergeFieldIds,
	restoreView,
	saveView,
} from '../view-storage';
import { applyProviderFilter } from './list/provider-filter';
import Statistics from './Statistics';

export interface ListViewProps {
	/** Every registered ability. */
	items: AbilityListItem[];
	/** True while the list is loading. */
	isLoading: boolean;
}

const PER_PAGE = 20;
const SUPPORTED_LAYOUTS = [ 'table' ];

const DEFAULT_VIEW: View = {
	type: 'table',
	search: '',
	filters: [],
	page: 1,
	perPage: PER_PAGE,
	sort: { field: TITLE_FIELD_ID, direction: 'asc' },
	titleField: TITLE_FIELD_ID,
	fields: [ ...DEFAULT_VISIBLE_FIELDS ],
	layout: {},
};

const DEFAULT_LAYOUTS: SupportedLayouts = { table: {} };

const getItemId = ( item: AbilityListItem ): string => item.slug;

/**
 * The field IDs a view shows the first time they are seen: the built-in
 * default columns, and any field that is not built in (an extension's column).
 *
 * @param fieldIds Every registered field ID.
 * @return The candidate IDs.
 */
const getCandidateIds = ( fieldIds: string[] ): string[] =>
	fieldIds.filter(
		( id ) =>
			DEFAULT_VISIBLE_FIELDS.includes( id ) ||
			! BUILT_IN_FIELD_IDS.includes( id )
	);

/**
 * The ability list: statistics, then a DataViews table with search, filters
 * and sorting.
 *
 * The whole list is fetched once and filtered, sorted and paginated here. The
 * provider filter runs first with its own rule and is consumed before
 * `filterSortAndPaginate()`.
 *
 * @param props           Component props.
 * @param props.items     The abilities.
 * @param props.isLoading Whether a load is in flight.
 * @return The view.
 */
export default function ListView( { items, isLoading }: ListViewProps ) {
	const fields = useFields( items );
	const fieldIds = useMemo(
		() => fields.map( ( field ) => field.id ),
		[ fields ]
	);
	const fieldKey = fieldIds.join( '\n' );

	const seenFields = useRef< string[] >( [] );

	const [ view, setView ] = useState< View >( () => {
		const restored = restoreView(
			DEFAULT_VIEW,
			loadSavedView(),
			fieldIds,
			getCandidateIds( fieldIds ),
			SUPPORTED_LAYOUTS
		);

		seenFields.current = restored.seenFields;

		return restored.view;
	} );

	/*
	 * Fields can change after the first render (an extension's field).
	 * A field seen for the first time joins the visible columns once; nothing
	 * is ever removed from the view.
	 */
	useEffect( () => {
		const ids = fieldKey.split( '\n' );
		const unseen = ids.filter(
			( id ) => ! seenFields.current.includes( id )
		);

		if ( 0 === unseen.length ) {
			return;
		}

		const previouslySeen = seenFields.current;
		seenFields.current = mergeFieldIds( previouslySeen, ids );

		setView( ( current ) => {
			const visible = current.fields ?? [];
			const next = appendNewFields(
				visible,
				previouslySeen,
				getCandidateIds( ids )
			);

			return next === visible ? current : { ...current, fields: next };
		} );
	}, [ fieldKey ] );

	/*
	 * What the last save was computed from. Search, filters and page are not
	 * persisted, so a change to only those skips the storage write.
	 */
	const lastSaved = useRef< string | null >( null );

	const onChangeView = useCallback(
		( next: View ) => {
			setView( next );

			const saveKey = JSON.stringify( [
				next.type,
				'layout' in next ? next.layout : undefined,
				next.fields,
				next.sort,
				next.perPage,
				seenFields.current,
				fieldIds,
			] );

			if ( saveKey !== lastSaved.current ) {
				lastSaved.current = saveKey;
				saveView( next, seenFields.current, fieldIds );
			}
		},
		[ fieldIds ]
	);

	const { data, paginationInfo } = useMemo( () => {
		const prefiltered = applyProviderFilter( items, view );

		return filterSortAndPaginate(
			prefiltered.items,
			prefiltered.view,
			fields
		);
	}, [ items, view, fields ] );

	const isFirstLoad = isLoading && 0 === items.length;

	return (
		<div className="ai-abilities-explorer__list">
			{ ! isFirstLoad && <Statistics items={ items } /> }
			<div
				className="ai-abilities-explorer__table"
				role="region"
				aria-label={ __( 'Abilities list', 'ai' ) }
			>
				{ /*
				 * No DataViews `actions`: the row actions sit under the name,
				 * as in `WP_List_Table`, rendered by the Name field.
				 */ }
				<DataViews< AbilityListItem >
					data={ data }
					fields={ fields }
					view={ view }
					onChangeView={ onChangeView }
					paginationInfo={ paginationInfo }
					getItemId={ getItemId }
					isLoading={ isFirstLoad }
					defaultLayouts={ DEFAULT_LAYOUTS }
					searchLabel={ __( 'Search Abilities', 'ai' ) }
				/>
			</div>
		</div>
	);
}
