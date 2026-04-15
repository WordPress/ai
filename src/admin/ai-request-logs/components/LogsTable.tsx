/**
 * WordPress dependencies
 */
import { Popover } from '@wordpress/components';
import {
	DataViews,
	type View,
	type Operator,
	type Filter,
	type ViewTable,
} from '@wordpress/dataviews';
import { __, sprintf } from '@wordpress/i18n';
import { useCallback, useMemo, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { getProviderIconComponent } from '../../components/provider-icons';
import type { ProviderMetadata } from '../../types/providers';
import { getDefaultOperationSelection } from '../query';
import type { FilterOptions, LogEntry, LogsQuery } from '../types';

interface LogsTableProps {
	logs: LogEntry[];
	filterOptions: FilterOptions;
	onViewLog: ( log: LogEntry ) => void;
	loading: boolean;
	totalPages: number;
	total: number;
	query: LogsQuery;
	setQuery: React.Dispatch< React.SetStateAction< LogsQuery > >;
	providerMetadata: Record< string, ProviderMetadata >;
}

/**
 * View-only properties that DataViews manages but are not part of the API
 * query. These are tracked separately to survive the query round-trip.
 */
interface ViewConfig {
	filters: Filter[];
	fields: string[];
	layout: NonNullable< ViewTable[ 'layout' ] >;
}

const DEFAULT_VIEW_FIELDS = [
	'timestamp',
	'operation',
	'provider',
	'tokens_total',
	'duration_ms',
	'status',
];

const FIELD_ORDER = new Map(
	DEFAULT_VIEW_FIELDS.map( ( id, index ) => [ id, index ] )
);

const sortFieldsByCanonicalOrder = ( ids: string[] ): string[] =>
	[ ...ids ].sort( ( a, b ) => {
		const ai = FIELD_ORDER.get( a ) ?? Infinity;
		const bi = FIELD_ORDER.get( b ) ?? Infinity;
		return ai - bi;
	} );

const formatTimestamp = ( timestamp: string ): string => {
	const date = new Date( timestamp + 'Z' );
	return date.toLocaleString();
};

const formatDuration = ( ms: number | null ): string => {
	if ( null === ms ) {
		return '-';
	}

	if ( ms < 1000 ) {
		return `${ ms }ms`;
	}

	return `${ ( ms / 1000 ).toFixed( 1 ) }s`;
};

const formatTokens = ( tokens: number | null ): string => {
	if ( null === tokens ) {
		return '-';
	}

	if ( tokens >= 1000 ) {
		return `${ ( tokens / 1000 ).toFixed( 1 ) }K`;
	}

	return tokens.toLocaleString();
};

const formatTokensPerSecond = ( value: number | null ): string => {
	if ( null === value ) {
		return '-';
	}

	if ( value >= 1000 ) {
		return `${ ( value / 1000 ).toFixed( 1 ) }K`;
	}

	return value.toFixed( 1 );
};

const getStatusClass = ( status: string ): string => {
	switch ( status ) {
		case 'success':
			return 'ai-request-logs__status--success';
		case 'error':
			return 'ai-request-logs__status--error';
		case 'timeout':
			return 'ai-request-logs__status--timeout';
		default:
			return '';
	}
};

const formatSelectLabel = ( value: string ): string =>
	value
		.split( '_' )
		.map( ( part ) => part.charAt( 0 ).toUpperCase() + part.slice( 1 ) )
		.join( ' ' );

const getRequestKind = ( entry: LogEntry ): string => {
	const raw = entry.context?.request_kind;
	return typeof raw === 'string' ? raw : 'text';
};

const getSourceLabel = ( entry: LogEntry ): string | null => {
	const source = entry.context?.source;

	if ( ! source || typeof source !== 'object' ) {
		return null;
	}

	const sourceName =
		'name' in source && typeof source.name === 'string'
			? source.name
			: null;
	const sourceType =
		'type' in source && typeof source.type === 'string'
			? source.type
			: null;

	if ( sourceName && sourceType ) {
		return `${ sourceName } (${ sourceType })`;
	}

	return sourceName ?? sourceType ?? null;
};

/**
 * Builds the initial filters array from a persisted LogsQuery so the
 * DataViews chip UI reflects saved filter state on first render.
 *
 * @param query Persisted logs query.
 */
const buildFiltersFromQuery = ( query: LogsQuery ): Filter[] => {
	const filters: Filter[] = [];

	if ( query.type ) {
		filters.push( {
			field: 'type',
			operator: 'is' as Operator,
			value: query.type,
		} );
	}
	if ( query.status ) {
		filters.push( {
			field: 'status',
			operator: 'is' as Operator,
			value: query.status,
		} );
	}
	if ( query.provider ) {
		filters.push( {
			field: 'provider',
			operator: 'is' as Operator,
			value: query.provider,
		} );
	}
	if ( query.operation.length > 0 ) {
		filters.push( {
			field: 'operation',
			operator: 'isAny' as Operator,
			value: query.operation,
		} );
	}
	if ( query.tokensFilter ) {
		filters.push( {
			field: 'tokensFilter',
			operator: 'is' as Operator,
			value: query.tokensFilter,
		} );
	}

	return filters;
};

/**
 * Extracts a string filter value, returning an empty string when the
 * filter is absent or has no concrete value (e.g. just opened, value
 * is still `undefined`).
 *
 * @param filters Active DataViews filters.
 * @param field   Field id to look up.
 */
const extractStringFilter = ( filters: Filter[], field: string ): string => {
	const f = filters.find( ( item ) => item.field === field );
	return typeof f?.value === 'string' ? f.value : '';
};

/**
 * Translates the DataViews view state into the LogsQuery shape
 * consumed by the parent's REST fetcher.
 *
 * @param view                Current DataViews view object.
 * @param availableOperations Known operation slugs from the server.
 */
const viewToQuery = (
	view: View,
	availableOperations: string[]
): LogsQuery => {
	const filters = view.filters ?? [];
	const operationFilter = filters.find( ( f ) => f.field === 'operation' );

	let operations: string[];
	if ( operationFilter && Array.isArray( operationFilter.value ) ) {
		operations = operationFilter.value as string[];
	} else if ( operationFilter && typeof operationFilter.value === 'string' ) {
		operations = [ operationFilter.value ];
	} else {
		operations = getDefaultOperationSelection( availableOperations );
	}

	return {
		page: ( view.page ?? 1 ) || 1,
		perPage: view.perPage ?? 25,
		search: ( view.search ?? '' ) || '',
		type: extractStringFilter( filters, 'type' ),
		status: extractStringFilter( filters, 'status' ),
		provider: extractStringFilter( filters, 'provider' ),
		operation: operations,
		tokensFilter: extractStringFilter( filters, 'tokensFilter' ),
	};
};

/**
 * Combines the LogsQuery (API-relevant state) with the ViewConfig
 * (UI-only state) into a complete DataViews View object.
 *
 * @param query      Current API query state.
 * @param viewConfig UI-only view configuration.
 */
const queryToView = ( query: LogsQuery, viewConfig: ViewConfig ): View => {
	return {
		type: 'table' as const,
		search: query.search,
		filters: viewConfig.filters,
		page: query.page,
		perPage: query.perPage,
		sort: {
			field: 'timestamp',
			direction: 'desc' as const,
		},
		fields: viewConfig.fields,
		layout: viewConfig.layout,
	};
};

interface ProviderCellProps {
	provider: string | null;
	model: string | null;
	metadata: ProviderMetadata | undefined;
}

const ProviderCell: React.FC< ProviderCellProps > = ( {
	provider,
	model,
	metadata,
} ) => {
	const [ isPopoverVisible, setIsPopoverVisible ] = useState( false );

	if ( ! provider && ! model ) {
		return <span className="ai-request-logs__cell-muted">-</span>;
	}

	if ( ! metadata ) {
		return (
			<div className="ai-request-logs__provider-cell">
				<div className="ai-request-logs__provider-row">
					{ provider && (
						<span className="ai-request-logs__provider-name">
							{ provider }
						</span>
					) }
				</div>
				{ model && (
					<div className="ai-request-logs__model-row">{ model }</div>
				) }
			</div>
		);
	}

	const IconComponent = getProviderIconComponent(
		metadata.icon || metadata.id,
		provider || undefined
	);

	return (
		<div
			className="ai-request-logs__provider-cell"
			role="button"
			tabIndex={ 0 }
			onClick={ () => setIsPopoverVisible( ( prev ) => ! prev ) }
			onKeyDown={ ( e ) => {
				if ( 'Enter' === e.key || ' ' === e.key ) {
					e.preventDefault();
					setIsPopoverVisible( ( prev ) => ! prev );
				}
			} }
		>
			<div className="ai-request-logs__provider-row">
				<span className="ai-request-logs__provider-icon">
					<IconComponent />
				</span>
				<span className="ai-request-logs__provider-name">
					{ metadata.name }
				</span>
			</div>
			{ model && (
				<div className="ai-request-logs__model-row">{ model }</div>
			) }
			{ isPopoverVisible && (
				<Popover
					placement="bottom-start"
					noArrow={ false }
					offset={ 8 }
					className="ai-request-logs__provider-popover"
					onClose={ () => setIsPopoverVisible( false ) }
				>
					<div className="ai-request-logs__popover-content">
						<div className="ai-request-logs__popover-header">
							<span className="ai-request-logs__popover-icon">
								<IconComponent />
							</span>
							<span className="ai-request-logs__popover-title">
								{ metadata.name }
							</span>
							<span className="ai-request-logs__popover-badge">
								{ metadata.type === 'client'
									? __( 'Local', 'ai' )
									: __( 'Cloud', 'ai' ) }
							</span>
						</div>
						{ model && (
							<div className="ai-request-logs__popover-model">
								{ model }
							</div>
						) }
						<div className="ai-request-logs__popover-links">
							{ metadata.url && (
								<a
									href={ metadata.url }
									target="_blank"
									rel="noopener noreferrer"
									className="ai-request-logs__popover-link"
								>
									{ __( 'API Key Settings', 'ai' ) }
								</a>
							) }
							<a
								href="admin.php?page=ai-provider-credentials"
								className="ai-request-logs__popover-link"
							>
								{ __( 'Provider Credentials', 'ai' ) }
							</a>
						</div>
					</div>
				</Popover>
			) }
		</div>
	);
};

const LogsTable: React.FC< LogsTableProps > = ( {
	logs,
	filterOptions,
	onViewLog,
	loading,
	totalPages,
	total,
	query,
	setQuery,
	providerMetadata,
} ) => {
	const [ viewConfig, setViewConfig ] = useState< ViewConfig >( () => ( {
		filters: buildFiltersFromQuery( query ),
		fields: [ ...DEFAULT_VIEW_FIELDS ],
		layout: {},
	} ) );

	const view = useMemo(
		() => queryToView( query, viewConfig ),
		[ query, viewConfig ]
	);

	const onChangeView = useCallback(
		( nextView: View ) => {
			const nextLayout =
				( 'layout' in nextView && nextView.layout ) || {};

			setViewConfig( {
				filters: nextView.filters ?? [],
				fields: sortFieldsByCanonicalOrder(
					nextView.fields ?? [ ...DEFAULT_VIEW_FIELDS ]
				),
				layout: nextLayout,
			} );

			setQuery( viewToQuery( nextView, filterOptions.operations ?? [] ) );
		},
		[ filterOptions.operations, setQuery ]
	);

	const fields = useMemo(
		() => [
			{
				id: 'timestamp',
				label: __( 'Time', 'ai' ),
				enableSorting: false,
				enableHiding: false,
				render: ( { item }: { item: LogEntry } ) => (
					<span className="ai-request-logs__cell--time">
						{ formatTimestamp( item.timestamp ) }
					</span>
				),
			},
			{
				id: 'operation',
				label: __( 'Operation', 'ai' ),
				enableSorting: false,
				elements: ( filterOptions.operations ?? [] ).map(
					( value ) => ( {
						label: value,
						value,
					} )
				),
				filterBy: {
					operators: [ 'isAny' as const ],
				},
				render: ( { item }: { item: LogEntry } ) => {
					const sourceLabel = getSourceLabel( item );
					return (
						<div className="ai-request-logs__operation">
							<div className="ai-request-logs__operation-primary">
								<code>{ item.operation }</code>
								<span
									className={ `ai-request-logs__kind ai-request-logs__kind--${ getRequestKind(
										item
									) }` }
								>
									{ formatSelectLabel(
										getRequestKind( item )
									) }
								</span>
							</div>
							{ ( sourceLabel || item.error_message ) && (
								<div className="ai-request-logs__operation-secondary">
									{ sourceLabel && (
										<span className="ai-request-logs__source-preview">
											{ sourceLabel }
										</span>
									) }
									{ item.error_message && (
										<span className="ai-request-logs__error-preview">
											{ item.error_message.substring(
												0,
												80
											) }
											{ item.error_message.length > 80
												? '…'
												: '' }
										</span>
									) }
								</div>
							) }
						</div>
					);
				},
			},
			{
				id: 'type',
				label: __( 'Type', 'ai' ),
				enableSorting: false,
				enableHiding: false,
				elements: filterOptions.types.map( ( value ) => ( {
					label: formatSelectLabel( value ),
					value,
				} ) ),
				filterBy: {
					operators: [ 'is' as const ],
				},
				render: ( { item }: { item: LogEntry } ) => (
					<span>{ formatSelectLabel( item.type ) }</span>
				),
			},
			{
				id: 'provider',
				label: __( 'Provider / Model', 'ai' ),
				enableSorting: false,
				elements: filterOptions.providers.map( ( value ) => ( {
					label: value,
					value,
				} ) ),
				filterBy: {
					operators: [ 'is' as const ],
				},
				getValue: ( { item }: { item: LogEntry } ) =>
					item.provider ?? '',
				render: ( { item }: { item: LogEntry } ) => (
					<ProviderCell
						provider={ item.provider }
						model={ item.model }
						metadata={
							item.provider
								? providerMetadata[ item.provider ]
								: undefined
						}
					/>
				),
			},
			{
				id: 'tokens_total',
				label: __( 'Tokens', 'ai' ),
				enableSorting: false,
				getValue: ( { item }: { item: LogEntry } ) =>
					item.tokens_total ?? 0,
				render: ( { item }: { item: LogEntry } ) => (
					<div className="ai-request-logs__metric">
						<span>{ formatTokens( item.tokens_total ) }</span>
						<span className="ai-request-logs__metric-secondary">
							{ sprintf(
								/* translators: %s: tokens per second. */
								__( '%s/s', 'ai' ),
								formatTokensPerSecond( item.tokens_per_second )
							) }
						</span>
					</div>
				),
			},
			{
				id: 'tokensFilter',
				label: __( 'Token Range', 'ai' ),
				enableSorting: false,
				enableHiding: false,
				elements: [
					{
						label: __( 'Has Tokens (> 0)', 'ai' ),
						value: 'gt:0',
					},
					{
						label: __( '< 100 tokens', 'ai' ),
						value: 'lt:100',
					},
					{
						label: __( '< 500 tokens', 'ai' ),
						value: 'lt:500',
					},
					{
						label: __( '< 1K tokens', 'ai' ),
						value: 'lt:1000',
					},
					{
						label: __( '< 5K tokens', 'ai' ),
						value: 'lt:5000',
					},
					{
						label: __( '> 1K tokens', 'ai' ),
						value: 'gt:1000',
					},
					{
						label: __( '> 5K tokens', 'ai' ),
						value: 'gt:5000',
					},
					{
						label: __( '> 10K tokens', 'ai' ),
						value: 'gt:10000',
					},
					{
						label: __( 'No Tokens', 'ai' ),
						value: 'none',
					},
				],
				filterBy: {
					operators: [ 'is' as const ],
				},
				render: () => null,
			},
			{
				id: 'duration_ms',
				label: __( 'Duration', 'ai' ),
				enableSorting: false,
				getValue: ( { item }: { item: LogEntry } ) =>
					item.duration_ms ?? 0,
				render: ( { item }: { item: LogEntry } ) => (
					<span>{ formatDuration( item.duration_ms ) }</span>
				),
			},
			{
				id: 'status',
				label: __( 'Status', 'ai' ),
				enableSorting: false,
				elements: filterOptions.statuses.map( ( value ) => ( {
					label: formatSelectLabel( value ),
					value,
				} ) ),
				filterBy: {
					operators: [ 'is' as const ],
				},
				render: ( { item }: { item: LogEntry } ) => (
					<span
						className={ `ai-request-logs__status ${ getStatusClass(
							item.status
						) }` }
					>
						{ formatSelectLabel( item.status ) }
					</span>
				),
			},
		],
		[ filterOptions, providerMetadata ]
	);

	const actions = useMemo(
		() => [
			{
				id: 'view-detail',
				label: __( 'View details', 'ai' ),
				isPrimary: true,
				callback: ( items: LogEntry[] ) => {
					if ( items[ 0 ] ) {
						onViewLog( items[ 0 ] );
					}
				},
			},
		],
		[ onViewLog ]
	);

	const paginationInfo = useMemo(
		() => ( {
			totalItems: total,
			totalPages,
		} ),
		[ total, totalPages ]
	);

	const defaultLayouts = useMemo(
		() => ( {
			table: {},
		} ),
		[]
	);

	return (
		<div className="ai-request-logs__dataviews-wrap">
			<DataViews
				data={ logs }
				fields={ fields }
				view={ view }
				onChangeView={ onChangeView }
				actions={ actions }
				paginationInfo={ paginationInfo }
				getItemId={ ( item: LogEntry ) => item.id }
				isLoading={ loading }
				defaultLayouts={ defaultLayouts }
			/>
		</div>
	);
};

export default LogsTable;
