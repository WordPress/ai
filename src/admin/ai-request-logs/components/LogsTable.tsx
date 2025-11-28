import { Button, Card, CardBody, CardHeader, SelectControl, Spinner, TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import React, { useMemo } from 'react';

import type { FilterOptions, LogEntry, LogFilters } from '../types';

interface LogsTableProps {
	logs: LogEntry[];
	filters: LogFilters;
	filterOptions: FilterOptions;
	onFilterChange: ( key: keyof LogFilters, value: string ) => void;
	onViewLog: ( log: LogEntry ) => void;
	loading: boolean;
	page: number;
	totalPages: number;
	total: number;
	onPageChange: ( page: number ) => void;
}

const formatTimestamp = ( timestamp: string ): string => {
	const date = new Date( timestamp + 'Z' );
	return date.toLocaleString();
};

const formatDuration = ( ms: number | null ): string => {
	if ( ms === null ) return '-';
	if ( ms < 1000 ) return ms + 'ms';
	return ( ms / 1000 ).toFixed( 1 ) + 's';
};

const formatTokens = ( tokens: number | null ): string => {
	if ( tokens === null ) return '-';
	if ( tokens >= 1000 ) return ( tokens / 1000 ).toFixed( 1 ) + 'K';
	return tokens.toLocaleString();
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

const LogsTable: React.FC< LogsTableProps > = ( {
	logs,
	filters,
	filterOptions,
	onFilterChange,
	onViewLog,
	loading,
	page,
	totalPages,
	total,
	onPageChange,
} ) => {
	const typeOptions = useMemo(
		() => [
			{ label: __( 'All Types', 'ai' ), value: '' },
			...filterOptions.types.map( ( t ) => ( { label: t, value: t } ) ),
		],
		[ filterOptions.types ]
	);

	const statusOptions = useMemo(
		() => [
			{ label: __( 'All Statuses', 'ai' ), value: '' },
			...filterOptions.statuses.map( ( s ) => ( { label: s, value: s } ) ),
		],
		[ filterOptions.statuses ]
	);

	const providerOptions = useMemo(
		() => [
			{ label: __( 'All Providers', 'ai' ), value: '' },
			...filterOptions.providers.map( ( p ) => ( { label: p, value: p } ) ),
		],
		[ filterOptions.providers ]
	);

	return (
		<Card className="ai-request-logs__card ai-request-logs__table-card">
			<CardHeader>
				<h2>{ __( 'Request Logs', 'ai' ) }</h2>
				{ total > 0 && (
					<span className="ai-request-logs__count">
						{ sprintf( __( '%d total', 'ai' ), total ) }
					</span>
				) }
			</CardHeader>
			<CardBody>
				<div className="ai-request-logs__filters">
					<TextControl
						placeholder={ __( 'Search...', 'ai' ) }
						value={ filters.search }
						onChange={ ( value ) => onFilterChange( 'search', value ) }
						__nextHasNoMarginBottom
					/>
					<SelectControl
						value={ filters.type }
						options={ typeOptions }
						onChange={ ( value ) => onFilterChange( 'type', value ) }
						__nextHasNoMarginBottom
					/>
					<SelectControl
						value={ filters.status }
						options={ statusOptions }
						onChange={ ( value ) => onFilterChange( 'status', value ) }
						__nextHasNoMarginBottom
					/>
					<SelectControl
						value={ filters.provider }
						options={ providerOptions }
						onChange={ ( value ) => onFilterChange( 'provider', value ) }
						__nextHasNoMarginBottom
					/>
				</div>

				{ loading ? (
					<div className="ai-request-logs__loading">
						<Spinner />
					</div>
				) : logs.length > 0 ? (
					<>
						<table className="ai-request-logs__table">
							<thead>
								<tr>
									<th>{ __( 'Time', 'ai' ) }</th>
									<th>{ __( 'Operation', 'ai' ) }</th>
									<th>{ __( 'Provider / Model', 'ai' ) }</th>
									<th>{ __( 'Tokens', 'ai' ) }</th>
									<th>{ __( 'Duration', 'ai' ) }</th>
									<th>{ __( 'Status', 'ai' ) }</th>
									<th></th>
								</tr>
							</thead>
							<tbody>
								{ logs.map( ( log ) => (
									<tr key={ log.id } className={ log.status === 'error' ? 'ai-request-logs__row--error' : '' }>
										<td className="ai-request-logs__cell--time">
											{ formatTimestamp( log.timestamp ) }
										</td>
										<td className="ai-request-logs__cell--operation">
											<code>{ log.operation }</code>
											{ log.error_message && (
												<div className="ai-request-logs__error-preview">
													{ log.error_message.substring( 0, 50 ) }
													{ log.error_message.length > 50 ? '...' : '' }
												</div>
											) }
										</td>
										<td>
											{ log.provider && (
												<span className="ai-request-logs__provider">{ log.provider }</span>
											) }
											{ log.model && (
												<div className="ai-request-logs__model">{ log.model }</div>
											) }
											{ ! log.provider && ! log.model && '-' }
										</td>
										<td>{ formatTokens( log.tokens_total ) }</td>
										<td>{ formatDuration( log.duration_ms ) }</td>
										<td>
											<span className={ `ai-request-logs__status ${ getStatusClass( log.status ) }` }>
												{ log.status }
											</span>
										</td>
										<td>
											<Button
												variant="tertiary"
												size="small"
												onClick={ () => onViewLog( log ) }
											>
												{ __( 'View', 'ai' ) }
											</Button>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>

						{ totalPages > 1 && (
							<div className="ai-request-logs__pagination">
								<Button
									variant="secondary"
									disabled={ page <= 1 }
									onClick={ () => onPageChange( page - 1 ) }
								>
									{ __( 'Previous', 'ai' ) }
								</Button>
								<span>
									{ sprintf( __( 'Page %1$d of %2$d', 'ai' ), page, totalPages ) }
								</span>
								<Button
									variant="secondary"
									disabled={ page >= totalPages }
									onClick={ () => onPageChange( page + 1 ) }
								>
									{ __( 'Next', 'ai' ) }
								</Button>
							</div>
						) }
					</>
				) : (
					<p className="ai-request-logs__empty">
						{ filters.search || filters.type || filters.status || filters.provider
							? __( 'No logs match your filters.', 'ai' )
							: __( 'No AI requests have been logged yet.', 'ai' ) }
					</p>
				) }
			</CardBody>
		</Card>
	);
};

export default LogsTable;
