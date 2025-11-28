/**
 * WordPress dependencies
 */
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Popover,
} from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews/wp';
import type { DataViewField, View, Filter } from '@wordpress/dataviews';
import { __, sprintf } from '@wordpress/i18n';

/**
 * External dependencies
 */
import React, { useCallback, useMemo, useState } from 'react';

/**
 * Internal dependencies
 */
import { getProviderIconComponent } from '../../components/provider-icons';
import type { FilterOptions, LogEntry } from '../types';
import type { ProviderMetadata } from '../../types/providers';

interface LogsTableProps {
	logs: LogEntry[];
	filterOptions: FilterOptions;
	onViewLog: ( log: LogEntry ) => void;
	loading: boolean;
	totalPages: number;
	total: number;
	view: View;
	setView: ( next: View | ( ( prev: View ) => View ) ) => void;
	providerMetadata: Record< string, ProviderMetadata >;
}

const formatTimestamp = ( timestamp: string ): string => {
	const date = new Date( timestamp + 'Z' );
	return date.toLocaleString();
};

const formatDuration = ( ms: number | null ): string => {
	if ( ms === null ) {
		return '-';
	}
	if ( ms < 1000 ) {
		return ms + 'ms';
	}
	return ( ms / 1000 ).toFixed( 1 ) + 's';
};

const formatTokens = ( tokens: number | null ): string => {
	if ( tokens === null ) {
		return '-';
	}
	if ( tokens >= 1000 ) {
		return ( tokens / 1000 ).toFixed( 1 ) + 'K';
	}
	return tokens.toLocaleString();
};

const formatTokensPerSecond = ( value: number | null ): string => {
	if ( value === null ) {
		return '-';
	}
	if ( value >= 1000 ) {
		return ( value / 1000 ).toFixed( 1 ) + 'K';
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

const LogsTable: React.FC< LogsTableProps > = ( {
	logs,
	filterOptions,
	onViewLog,
	loading,
	totalPages,
	total,
	view,
	setView,
	providerMetadata,
} ) => {
	const typeElements = useMemo(
		() =>
			filterOptions.types.map( ( value ) => ( {
				label: formatSelectLabel( value ),
				value,
			} ) ),
		[ filterOptions.types ]
	);

	const statusElements = useMemo(
		() =>
			filterOptions.statuses.map( ( value ) => ( {
				label: formatSelectLabel( value ),
				value,
			} ) ),
		[ filterOptions.statuses ]
	);

	const providerElements = useMemo(
		() =>
			filterOptions.providers.map( ( value ) => ( {
				label: value,
				value,
			} ) ),
		[ filterOptions.providers ]
	);

	const operationElements = useMemo(
		() =>
			( filterOptions.operations ?? [] ).map( ( value ) => ( {
				label: formatSelectLabel( value ),
				value,
			} ) ),
		[ filterOptions.operations ]
	);

	// Token filter elements with useful ranges
	const tokenFilterElements = useMemo(
		() => [
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
		[]
	);

	const requestKindElements = useMemo( () => {
		const kinds = new Set<string>();
		logs.forEach( ( entry ) => kinds.add( getRequestKind( entry ) ) );
		return Array.from( kinds ).map( ( value ) => ( {
			label: formatSelectLabel( value ),
			value,
		} ) );
	}, [ logs ] );

	const fields = useMemo< DataViewField< LogEntry >[] >(
		() => [
			{
				id: 'timestamp',
				label: __( 'Time', 'ai' ),
				type: 'datetime',
				getValue: ( { item } ) => item.timestamp,
				enableSorting: false,
				render: ( { item } ) => (
					<span className="ai-request-logs__cell--time">
						{ formatTimestamp( item.timestamp ) }
					</span>
				),
			},
			{
				id: 'operation',
				label: __( 'Operation', 'ai' ),
				type: 'text',
				enableGlobalSearch: true,
				getValue: ( { item } ) => item.operation,
				elements: operationElements,
				filterBy:
					operationElements.length > 0
						? { operators: [ 'isAny' ] }
						: false,
				render: ( { item } ) => (
					<div className="ai-request-logs__operation">
						<code>{ item.operation }</code>
						{ item.error_message && (
							<div className="ai-request-logs__error-preview">
								{ item.error_message.substring( 0, 50 ) }
								{ item.error_message.length > 50 ? '…' : '' }
							</div>
						) }
					</div>
				),
			},
			{
				id: 'operation_pattern',
				label: __( 'Request Type', 'ai' ),
				type: 'text',
				getValue: ( { item } ) => getRequestKind( item ),
				elements: requestKindElements,
				filterBy:
					requestKindElements.length > 0
						? { operators: [ 'is' ] }
						: false,
				enableHiding: false,
				render: ( { item } ) => (
					<span
						className={ `ai-request-logs__kind ai-request-logs__kind--${ getRequestKind( item ) }` }
					>
						{ formatSelectLabel( getRequestKind( item ) ) }
					</span>
				),
			},
			{
				id: 'type',
				label: __( 'Type', 'ai' ),
				type: 'text',
				getValue: ( { item } ) => item.type,
				elements: typeElements,
				filterBy:
					typeElements.length > 0 ? { operators: [ 'is' ] } : false,
				enableHiding: false,
				isVisible: () => false,
			},
			{
				id: 'provider',
				label: __( 'Provider / Model', 'ai' ),
				type: 'text',
				getValue: ( { item } ) => item.provider ?? '',
				elements: providerElements,
				filterBy:
					providerElements.length > 0
						? { operators: [ 'is' ] }
						: false,
				render: ( { item } ) => (
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
				type: 'text',
				getValue: ( { item } ) => {
					// Return filter value for matching
					const tokens = item.tokens_total ?? 0;
					if ( tokens === 0 || item.tokens_total === null ) {
						return 'none';
					}
					if ( tokens > 10000 ) return 'gt:10000';
					if ( tokens > 5000 ) return 'gt:5000';
					if ( tokens > 1000 ) return 'gt:1000';
					return 'gt:0';
				},
				elements: tokenFilterElements,
				filterBy: { operators: [ 'is' ] },
				render: ( { item } ) => formatTokens( item.tokens_total ),
			},
			{
				id: 'duration_ms',
				label: __( 'Duration', 'ai' ),
				type: 'number',
				getValue: ( { item } ) => item.duration_ms ?? 0,
				render: ( { item } ) => formatDuration( item.duration_ms ),
			},
			{
				id: 'tokens_per_second',
				label: __( 'Tokens/s', 'ai' ),
				type: 'number',
				getValue: ( { item } ) => item.tokens_per_second ?? 0,
				render: ( { item } ) =>
					formatTokensPerSecond( item.tokens_per_second ),
			},
			{
				id: 'status',
				label: __( 'Status', 'ai' ),
				type: 'text',
				getValue: ( { item } ) => item.status,
				elements: statusElements,
				filterBy:
					statusElements.length > 0 ? { operators: [ 'is' ] } : false,
				render: ( { item } ) => (
					<span
						className={ `ai-request-logs__status ${ getStatusClass(
							item.status
						) }` }
					>
						{ formatSelectLabel( item.status ) }
					</span>
				),
			},
			{
				id: 'actions',
				label: __( 'Details', 'ai' ),
				type: 'text',
				enableSorting: false,
				enableHiding: false,
				filterBy: false,
				render: ( { item } ) => (
					<Button
						variant="tertiary"
						size="small"
						onClick={ () => onViewLog( item ) }
					>
						{ __( 'View', 'ai' ) }
					</Button>
				),
			},
		],
		[
			onViewLog,
			operationElements,
			providerElements,
			providerMetadata,
			statusElements,
			tokenFilterElements,
			typeElements,
			requestKindElements,
		]
	);

	const handleViewChange = useCallback(
		( nextView: View ) => {
			setView( ( previous ) => {
				// Deduplicate filters by field - keep only the last filter for each field
				const filters = nextView.filters ?? [];
				const deduplicatedFilters = filters.reduce(
					( acc: Filter[], filter ) => {
						const existingIndex = acc.findIndex(
							( f ) => f.field === filter.field
						);
						if ( existingIndex >= 0 ) {
							acc[ existingIndex ] = filter;
						} else {
							acc.push( filter );
						}
						return acc;
					},
					[]
				);

				return {
					...previous,
					...nextView,
					filters: deduplicatedFilters,
					layout: nextView.layout ?? previous.layout,
				};
			} );
		},
		[ setView ]
	);

	const hasActiveFilters = Boolean(
		view.search || ( view.filters && view.filters.length > 0 )
	);

	return (
		<Card className="ai-request-logs__card ai-request-logs__table-card">
			<CardHeader>
				<h2>{ __( 'Request Logs', 'ai' ) }</h2>
				{ total > 0 && (
					<span className="ai-request-logs__count">
						{ sprintf(
							/* translators: %d: total number of logged requests. */
							__( '%d total', 'ai' ),
							total
						) }
					</span>
				) }
			</CardHeader>
			<CardBody>
				<DataViews
					data={ logs }
					fields={ fields }
					view={ view }
					onChangeView={ handleViewChange }
					getItemId={ ( item ) => item.id }
					defaultLayouts={ {
						table: {
							layout: {
								density: 'comfortable',
								enableMoving: false,
							},
						},
					} }
					isLoading={ loading }
					paginationInfo={ {
						totalItems: total,
						totalPages,
					} }
					config={ { perPageSizes: [ 25 ] } }
					empty={
						<p className="ai-request-logs__empty">
							{ hasActiveFilters
								? __( 'No logs match your filters.', 'ai' )
								: __(
										'No AI requests have been logged yet.',
										'ai'
								  ) }
						</p>
					}
					searchLabel={ __( 'Search logs', 'ai' ) }
				/>
			</CardBody>
		</Card>
	);
};

interface ProviderCellProps {
	provider: string | null;
	model: string | null;
	metadata?: ProviderMetadata;
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
			onMouseEnter={ () => setIsPopoverVisible( true ) }
			onMouseLeave={ () => setIsPopoverVisible( false ) }
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

export default LogsTable;
