/**
 * WordPress dependencies
 */
import {
	DataViews,
	filterSortAndPaginate,
	type Action,
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
import { useExplorer } from '../context';
import {
	BUILT_IN_FIELD_IDS,
	DEFAULT_VISIBLE_FIELDS,
	TITLE_FIELD_ID,
	getBuiltInFields,
} from '../fields';
import type { AbilityListItem, PolicyState, SurfaceResponse } from '../types';
import {
	appendNewFields,
	loadSavedView,
	mergeFieldIds,
	restoreView,
	saveView,
} from '../view-storage';
import { applyProviderFilter } from './list/provider-filter';
import RouterLink from './RouterLink';
import Statistics from './Statistics';

export interface ListViewProps {
	/** Every registered ability, as last loaded. Kept on a failed refresh. */
	items: AbilityListItem[];
	/** The assistant admission policy, or null before the first load. */
	policy: PolicyState | null;
	/** True while the first load or a refresh is in flight. */
	isLoading: boolean;
	/**
	 * Applies a surface route response to the list (KTD8). Returns false when
	 * the response is stale because a newer one has already been applied.
	 */
	onSurfaceResponse: ( response: SurfaceResponse ) => boolean;
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

const DEFAULT_LAYOUTS = { table: {} };

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
 * and sorting (R1 to R5).
 *
 * The whole list is fetched once and filtered, sorted and paginated here
 * (KTD6). The provider filter runs first with its own rule and is consumed
 * before `filterSortAndPaginate()`.
 *
 * @param props           Component props.
 * @param props.items     The abilities.
 * @param props.isLoading Whether a load is in flight.
 * @return The view.
 */
export default function ListView( { items, isLoading }: ListViewProps ) {
	const { navigate } = useExplorer();

	const fields = useMemo( () => getBuiltInFields( items ), [ items ] );
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
	 * Fields can change after the first render (an extension's field, KTD13).
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

	const onChangeView = useCallback( ( next: View ) => {
		setView( next );
		saveView( next, seenFields.current );
	}, [] );

	const { data, paginationInfo } = useMemo( () => {
		const prefiltered = applyProviderFilter( items, view );

		return filterSortAndPaginate(
			prefiltered.items,
			prefiltered.view,
			fields
		);
	}, [ items, view, fields ] );

	/*
	 * Navigation actions. The surface actions (remove from and return to the
	 * assistant) join this list; extension fields never carry actions.
	 */
	const actions = useMemo< Action< AbilityListItem >[] >(
		() => [
			{
				id: 'view',
				label: __( 'View', 'ai' ),
				isPrimary: true,
				callback: ( selected ) => {
					const item = selected[ 0 ];

					if ( item ) {
						navigate( { view: 'detail', ability: item.slug } );
					}
				},
			},
			{
				id: 'test',
				label: __( 'Test', 'ai' ),
				isPrimary: true,
				callback: ( selected ) => {
					const item = selected[ 0 ];

					if ( item ) {
						navigate( { view: 'runner', ability: item.slug } );
					}
				},
			},
		],
		[ navigate ]
	);

	const renderItemLink = useCallback(
		( {
			item,
			className,
			children,
		}: {
			item: AbilityListItem;
			className?: string | undefined;
			children?: React.ReactNode;
		} ) => (
			<RouterLink
				route={ { view: 'detail', ability: item.slug } }
				{ ...( className ? { className } : {} ) }
			>
				{ children }
			</RouterLink>
		),
		[]
	);

	const isFirstLoad = isLoading && 0 === items.length;

	return (
		<div className="ai-abilities-explorer__list">
			{ ! isFirstLoad && <Statistics items={ items } /> }
			<DataViews< AbilityListItem >
				data={ data }
				fields={ fields }
				view={ view }
				onChangeView={ onChangeView }
				actions={ actions }
				paginationInfo={ paginationInfo }
				getItemId={ getItemId }
				isLoading={ isFirstLoad }
				defaultLayouts={ DEFAULT_LAYOUTS }
				renderItemLink={ renderItemLink }
				searchLabel={ __( 'Search Abilities', 'ai' ) }
			/>
		</div>
	);
}
