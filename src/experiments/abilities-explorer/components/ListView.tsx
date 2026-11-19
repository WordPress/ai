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
import type { AbilityListItem, PolicyState, SurfaceResponse } from '../types';
import {
	appendNewFields,
	loadSavedView,
	mergeFieldIds,
	restoreView,
	saveView,
} from '../view-storage';
import { applyProviderFilter } from './list/provider-filter';
import PolicyToggle from './PolicyToggle';
import Statistics from './Statistics';
import { RowSurfaceActionContext, useSurfaceActions } from './SurfaceActions';

export interface ListViewProps {
	/** Every registered ability. */
	items: AbilityListItem[];
	/** The assistant admission policy, or null before the first load. */
	policy: PolicyState | null;
	/** True while the list is loading. */
	isLoading: boolean;
	/** Applies a surface response; false when it was stale. */
	onSurfaceResponse: ( response: SurfaceResponse ) => boolean;
}

const PER_PAGE = 20;
const SUPPORTED_LAYOUTS = [ 'table' ];

const TABLE_LAYOUT = {
	styles: {
		description: { width: '50%' },
		surface: { minWidth: '16em' },
	},
};

const DEFAULT_VIEW: View = {
	type: 'table',
	search: '',
	filters: [],
	page: 1,
	perPage: PER_PAGE,
	sort: { field: TITLE_FIELD_ID, direction: 'asc' },
	titleField: TITLE_FIELD_ID,
	fields: [ ...DEFAULT_VISIBLE_FIELDS ],
	layout: TABLE_LAYOUT,
};

const DEFAULT_LAYOUTS: SupportedLayouts = { table: { layout: TABLE_LAYOUT } };

const getItemId = ( item: AbilityListItem ): string => item.slug;

/**
 * The field IDs shown the first time they are seen: the defaults and any extension field.
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
 * The ability list: statistics, then a DataViews table.
 *
 * @param props                   Component props.
 * @param props.items             The abilities.
 * @param props.policy            The assistant admission policy.
 * @param props.isLoading         Whether a load is in flight.
 * @param props.onSurfaceResponse Applies a surface route response.
 * @return The view.
 */
export default function ListView( {
	items,
	policy,
	isLoading,
	onSurfaceResponse,
}: ListViewProps ) {
	const tableRef = useRef< HTMLDivElement >( null );

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

	// A field first seen after load (an extension's) joins the visible columns once.
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

	// Search, filters and page are not saved, so changing only those skips the write.
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

	/*
	 * When a surface change removes the focused row button (filtered out or no action left),
	 * move focus to the list region; the table itself is gone when the list ends up empty.
	 */
	const [ settledCount, setSettledCount ] = useState( 0 );

	const onSurfaceSettled = useCallback( () => {
		setSettledCount( ( count ) => count + 1 );
	}, [] );

	useEffect( () => {
		if ( 0 === settledCount ) {
			return;
		}

		const { activeElement } = document;

		if (
			activeElement &&
			activeElement !== document.body &&
			activeElement.isConnected
		) {
			return;
		}

		tableRef.current?.focus();
	}, [ settledCount ] );

	const getRowSurfaceAction = useSurfaceActions( {
		onSurfaceResponse,
		onSettled: onSurfaceSettled,
	} );

	const isFirstLoad = isLoading && 0 === items.length;

	return (
		<div className="ai-abilities-explorer__list">
			{ ! isFirstLoad && <Statistics items={ items } /> }
			{ null !== policy && (
				<PolicyToggle
					policy={ policy }
					onSurfaceResponse={ onSurfaceResponse }
				/>
			) }
			<div
				ref={ tableRef }
				className="ai-abilities-explorer__table"
				role="region"
				aria-label={ __( 'Abilities list', 'ai' ) }
				tabIndex={ -1 }
			>
				<RowSurfaceActionContext.Provider value={ getRowSurfaceAction }>
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
				</RowSurfaceActionContext.Provider>
			</div>
		</div>
	);
}
