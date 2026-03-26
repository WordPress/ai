/**
 * WordPress dependencies
 */
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Popover,
	SearchControl,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * External dependencies
 */
import React, { useMemo, useState } from 'react';

/**
 * Internal dependencies
 */
import { getProviderIconComponent } from '../../components/provider-icons';
import type { ProviderMetadata } from '../../types/providers';
import {
	areOperationsEqual,
	getDefaultLogsQuery,
	isModelDiscoveryOperation,
} from '../query';
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
	const updateQuery = (
		field: keyof LogsQuery,
		value: LogsQuery[ keyof LogsQuery ]
	) => {
		setQuery( ( previous ) => ( {
			...previous,
			[ field ]: value,
			page: 'page' === field ? Number( value ) : 1,
		} ) );
	};

	const defaultQuery = useMemo(
		() => getDefaultLogsQuery( filterOptions.operations ?? [] ),
		[ filterOptions.operations ]
	);
	const modelDiscoveryOperations = useMemo(
		() =>
			( filterOptions.operations ?? [] ).filter(
				isModelDiscoveryOperation
			),
		[ filterOptions.operations ]
	);
	const hasModelDiscoverySelection = modelDiscoveryOperations.some(
		( operation ) => query.operation.includes( operation )
	);
	const hasOperationFilter = ! areOperationsEqual(
		query.operation,
		defaultQuery.operation
	);

	const clearFilters = () => {
		setQuery( defaultQuery );
	};

	const includeModelDiscoveryRequests = () => {
		updateQuery( 'operation', [
			...new Set( [ ...query.operation, ...modelDiscoveryOperations ] ),
		] );
	};

	const typeOptions = useMemo(
		() => [
			{ label: __( 'All types', 'ai' ), value: '' },
			...filterOptions.types.map( ( value ) => ( {
				label: formatSelectLabel( value ),
				value,
			} ) ),
		],
		[ filterOptions.types ]
	);

	const statusOptions = useMemo(
		() => [
			{ label: __( 'All statuses', 'ai' ), value: '' },
			...filterOptions.statuses.map( ( value ) => ( {
				label: formatSelectLabel( value ),
				value,
			} ) ),
		],
		[ filterOptions.statuses ]
	);

	const providerOptions = useMemo(
		() => [
			{ label: __( 'All providers', 'ai' ), value: '' },
			...filterOptions.providers.map( ( value ) => ( {
				label: value,
				value,
			} ) ),
		],
		[ filterOptions.providers ]
	);

	const operationOptions = useMemo(
		() =>
			( filterOptions.operations ?? [] ).map( ( value ) => ( {
				label: value,
				value,
			} ) ),
		[ filterOptions.operations ]
	);

	const tokenFilterOptions = useMemo(
		() => [
			{
				label: __( 'All token counts', 'ai' ),
				value: '',
			},
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

	const hasActiveFilters = Boolean(
		query.search ||
			query.type ||
			query.status ||
			query.provider ||
			hasOperationFilter ||
			query.tokensFilter
	);
	const emptyMessage = hasActiveFilters
		? __( 'No logs match your filters.', 'ai' )
		: __( 'No AI requests have been logged yet.', 'ai' );

	const rangeStart = 0 === total ? 0 : ( query.page - 1 ) * 25 + 1;
	const rangeEnd = Math.min( query.page * 25, total );
	const shouldShowTable = ! loading && logs.length > 0;

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
				<div className="ai-request-logs__controls">
					<div className="ai-request-logs__search">
						<SearchControl
							label={ __( 'Search logs', 'ai' ) }
							value={ query.search }
							onChange={ ( value ) =>
								updateQuery( 'search', value )
							}
							placeholder={ __(
								'Search operations, previews, and errors',
								'ai'
							) }
							__nextHasNoMarginBottom
						/>
					</div>

					<div className="ai-request-logs__filters">
						<SelectControl
							label={ __( 'Type', 'ai' ) }
							value={ query.type }
							options={ typeOptions }
							onChange={ ( value ) =>
								updateQuery( 'type', value )
							}
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						<SelectControl
							label={ __( 'Status', 'ai' ) }
							value={ query.status }
							options={ statusOptions }
							onChange={ ( value ) =>
								updateQuery( 'status', value )
							}
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						<SelectControl
							label={ __( 'Provider', 'ai' ) }
							value={ query.provider }
							options={ providerOptions }
							onChange={ ( value ) =>
								updateQuery( 'provider', value )
							}
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						<div className="ai-request-logs__operations-filter">
							<SelectControl
								label={ __( 'Operations', 'ai' ) }
								value={ query.operation }
								options={ operationOptions }
								onChange={ ( value ) =>
									updateQuery( 'operation', value )
								}
								multiple
								help={
									modelDiscoveryOperations.length > 0
										? __(
												'Model discovery requests are hidden by default. Use Command on macOS or Ctrl on Windows/Linux to select multiple operations.',
												'ai'
										  )
										: __(
												'Use Command on macOS or Ctrl on Windows/Linux to select multiple operations.',
												'ai'
										  )
								}
								__nextHasNoMarginBottom
							/>
							{ modelDiscoveryOperations.length > 0 &&
								! hasModelDiscoverySelection && (
									<Button
										variant="tertiary"
										onClick={
											includeModelDiscoveryRequests
										}
									>
										{ __(
											'Include model discovery requests',
											'ai'
										) }
									</Button>
								) }
						</div>
						<SelectControl
							label={ __( 'Tokens', 'ai' ) }
							value={ query.tokensFilter }
							options={ tokenFilterOptions }
							onChange={ ( value ) =>
								updateQuery( 'tokensFilter', value )
							}
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					</div>

					{ hasActiveFilters && (
						<div className="ai-request-logs__controls-footer">
							<Button variant="tertiary" onClick={ clearFilters }>
								{ __( 'Clear filters', 'ai' ) }
							</Button>
						</div>
					) }
				</div>

				{ loading && (
					<div className="ai-request-logs__loading">
						<Spinner />
						<span>{ __( 'Loading request logs…', 'ai' ) }</span>
					</div>
				) }

				{ ! loading && 0 === logs.length && (
					<p className="ai-request-logs__empty">{ emptyMessage }</p>
				) }

				{ shouldShowTable && (
					<>
						<div className="ai-request-logs__table-wrap">
							<table className="ai-request-logs__table">
								<thead>
									<tr>
										<th scope="col">
											{ __( 'Time', 'ai' ) }
										</th>
										<th scope="col">
											{ __( 'Operation', 'ai' ) }
										</th>
										<th scope="col">
											{ __( 'Provider / Model', 'ai' ) }
										</th>
										<th scope="col">
											{ __( 'Tokens', 'ai' ) }
										</th>
										<th scope="col">
											{ __( 'Duration', 'ai' ) }
										</th>
										<th scope="col">
											{ __( 'Status', 'ai' ) }
										</th>
										<th scope="col">
											{ __( 'Details', 'ai' ) }
										</th>
									</tr>
								</thead>
								<tbody>
									{ logs.map( ( log ) => (
										<tr key={ log.id }>
											<td>
												<span className="ai-request-logs__cell--time">
													{ formatTimestamp(
														log.timestamp
													) }
												</span>
											</td>
											<td>
												<div className="ai-request-logs__operation">
													<code>
														{ log.operation }
													</code>
													<div className="ai-request-logs__operation-meta">
														<span
															className={ `ai-request-logs__kind ai-request-logs__kind--${ getRequestKind(
																log
															) }` }
														>
															{ formatSelectLabel(
																getRequestKind(
																	log
																)
															) }
														</span>
													</div>
													{ getSourceLabel( log ) && (
														<div className="ai-request-logs__source-preview">
															{ getSourceLabel(
																log
															) }
														</div>
													) }
													{ log.error_message && (
														<div className="ai-request-logs__error-preview">
															{ log.error_message.substring(
																0,
																80
															) }
															{ log.error_message
																.length > 80
																? '…'
																: '' }
														</div>
													) }
												</div>
											</td>
											<td>
												<ProviderCell
													provider={ log.provider }
													model={ log.model }
													metadata={
														log.provider
															? providerMetadata[
																	log.provider
															  ]
															: undefined
													}
												/>
											</td>
											<td>
												<div className="ai-request-logs__metric">
													<span>
														{ formatTokens(
															log.tokens_total
														) }
													</span>
													<span className="ai-request-logs__metric-secondary">
														{ sprintf(
															/* translators: %s: tokens per second. */
															__( '%s/s', 'ai' ),
															formatTokensPerSecond(
																log.tokens_per_second
															)
														) }
													</span>
												</div>
											</td>
											<td>
												{ formatDuration(
													log.duration_ms
												) }
											</td>
											<td>
												<span
													className={ `ai-request-logs__status ${ getStatusClass(
														log.status
													) }` }
												>
													{ formatSelectLabel(
														log.status
													) }
												</span>
											</td>
											<td className="ai-request-logs__table-actions">
												<Button
													variant="tertiary"
													size="small"
													onClick={ () =>
														onViewLog( log )
													}
												>
													{ __( 'View', 'ai' ) }
												</Button>
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>

						<div className="ai-request-logs__pagination">
							<span className="ai-request-logs__pagination-summary">
								{ sprintf(
									/* translators: 1: first visible result, 2: last visible result, 3: total results. */
									__( '%1$d-%2$d of %3$d', 'ai' ),
									rangeStart,
									rangeEnd,
									total
								) }
							</span>
							<div className="ai-request-logs__pagination-actions">
								<Button
									variant="secondary"
									disabled={ query.page <= 1 }
									onClick={ () =>
										updateQuery( 'page', query.page - 1 )
									}
								>
									{ __( 'Previous', 'ai' ) }
								</Button>
								<span className="ai-request-logs__pagination-page">
									{ sprintf(
										/* translators: 1: current page number, 2: total page count. */
										__( 'Page %1$d of %2$d', 'ai' ),
										query.page,
										totalPages
									) }
								</span>
								<Button
									variant="secondary"
									disabled={ query.page >= totalPages }
									onClick={ () =>
										updateQuery( 'page', query.page + 1 )
									}
								>
									{ __( 'Next', 'ai' ) }
								</Button>
							</div>
						</div>
					</>
				) }
			</CardBody>
		</Card>
	);
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
