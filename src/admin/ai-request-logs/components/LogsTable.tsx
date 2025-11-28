/**
 * WordPress dependencies
 */
import { Button, Card, CardBody, CardHeader } from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews/wp';
import type { DataViewField, View } from '@wordpress/dataviews';
import { __, sprintf } from '@wordpress/i18n';

/**
 * External dependencies
 */
import React, { useCallback, useMemo } from 'react';

/**
 * Internal dependencies
 */
import { AnthropicIcon, GoogleIcon, OpenAiIcon } from '../../components/icons';
import type { FilterOptions, LogEntry } from '../types';

const getProviderIcon = ( provider: string | null ): React.ReactNode => {
	if ( ! provider ) {
		return null;
	}
	const normalized = provider.toLowerCase();
	if ( normalized === 'openai' ) {
		return <OpenAiIcon />;
	}
	if ( normalized === 'anthropic' ) {
		return <AnthropicIcon />;
	}
	if ( normalized === 'google' ) {
		return <GoogleIcon />;
	}
	return null;
};

interface LogsTableProps {
	logs: LogEntry[];
	filterOptions: FilterOptions;
	onViewLog: ( log: LogEntry ) => void;
	loading: boolean;
	totalPages: number;
	total: number;
	view: View;
	setView: ( next: View | ( ( prev: View ) => View ) ) => void;
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
		return `${ ms }ms`;
	}
	return `${ ( ms / 1000 ).toFixed( 1 ) }s`;
};

const formatTokens = ( tokens: number | null ): string => {
	if ( tokens === null ) {
		return '-';
	}
	if ( tokens >= 1000 ) {
		return `${ ( tokens / 1000 ).toFixed( 1 ) }K`;
	}
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

const formatSelectLabel = ( value: string ): string =>
	value
		.split( '_' )
		.map( ( part ) => part.charAt( 0 ).toUpperCase() + part.slice( 1 ) )
		.join( ' ' );

const LogsTable: React.FC< LogsTableProps > = ( {
	logs,
	filterOptions,
	onViewLog,
	loading,
	totalPages,
	total,
	view,
	setView,
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
					<div>
						{ item.provider && (
							<span className="ai-request-logs__provider">
								{ getProviderIcon( item.provider ) }
								{ item.provider }
							</span>
						) }
						{ item.model && (
							<div className="ai-request-logs__model">
								{ item.model }
							</div>
						) }
						{ ! item.provider && ! item.model && '-' }
					</div>
				),
			},
			{
				id: 'tokens_total',
				label: __( 'Tokens', 'ai' ),
				type: 'number',
				getValue: ( { item } ) => item.tokens_total ?? 0,
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
			statusElements,
			typeElements,
		]
	);

	const handleViewChange = useCallback(
		( nextView: View ) => {
			setView( ( previous ) => ( {
				...previous,
				...nextView,
				layout: nextView.layout ?? previous.layout,
			} ) );
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
							/* translators: %d: number of log entries. */
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

export default LogsTable;
