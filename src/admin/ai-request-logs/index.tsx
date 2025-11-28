/**
 * Internal dependencies
 */
import './style.scss';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { Notice } from '@wordpress/components';
import { dispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { __ } from '@wordpress/i18n';
/**
 * External dependencies
 */
import React, { useCallback, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';

import HeaderPeriodSelector from './components/HeaderPeriodSelector';
import LogDetailModal from './components/LogDetailModal';
import LogsTable from './components/LogsTable';
import SettingsPanel from './components/SettingsPanel';
import SummaryCards from './components/SummaryCards';
import type {
	FilterOptions,
	LocalizedSettings,
	LogEntry,
	LogFilters,
	LogSummary,
} from './types';

const settings: LocalizedSettings =
	window.aiAiRequestLogsSettings ??
	window.AiRequestLogsSettings ??
	( () => {
		throw new Error( 'AiRequestLogsSettings is not defined.' );
	} )();

const providerMetadata = settings.providerMetadata ?? {};

apiFetch.use( apiFetch.createNonceMiddleware( settings.rest.nonce ) );
apiFetch.use( apiFetch.createRootURLMiddleware( settings.rest.root ) );

const showNotice = (
	status: 'success' | 'error' | 'warning',
	message: string
) =>
	dispatch( noticesStore ).createNotice( status, message, {
		type: 'snackbar',
	} );

const getErrorMessage = ( error: unknown ): string => {
	if ( typeof error === 'string' ) {
		return error;
	}
	if ( error && typeof error === 'object' && 'message' in error ) {
		return String( ( error as { message: string } ).message );
	}
	return __( 'Something went wrong. Please try again.', 'ai' );
};

const defaultFilters: LogFilters = {
	type: '',
	status: '',
	provider: '',
	operation: [],
	search: '',
	period: 'day',
};

const App: React.FC = () => {
	// Settings state
	const [ enabled, setEnabled ] = useState( settings.initialState.enabled );
	const [ retentionDays, setRetentionDays ] = useState(
		settings.initialState.retentionDays
	);

	// Summary state
	const [ summary, setSummary ] = useState< LogSummary >(
		settings.initialState.summary
	);
	const [ summaryPeriod, setSummaryPeriod ] = useState<
		'minute' | 'hour' | 'day' | 'week' | 'month' | 'all'
	>( 'day' );
	const [ summaryLoading, setSummaryLoading ] = useState( false );

	// Logs state
	const [ logs, setLogs ] = useState< LogEntry[] >( [] );
	const [ logsLoading, setLogsLoading ] = useState( true );
	const [ page, setPage ] = useState( 1 );
	const [ totalPages, setTotalPages ] = useState( 1 );
	const [ total, setTotal ] = useState( 0 );

	// Filters state
	const [ filters, setFilters ] = useState< LogFilters >( defaultFilters );
	const [ filterOptions, setFilterOptions ] = useState< FilterOptions >(
		settings.initialState.filters
	);

	// UI state
	const [ selectedLog, setSelectedLog ] = useState< LogEntry | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ saving, setSaving ] = useState( false );
	const [ purging, setPurging ] = useState( false );

	// Fetch summary
	const fetchSummary = useCallback( async ( period: string ) => {
		setSummaryLoading( true );
		try {
			const response = await apiFetch< LogSummary >( {
				path: `${ settings.rest.routes.summary }?period=${ period }`,
			} );
			setSummary( response );
		} catch ( apiError ) {
			showNotice( 'error', __( 'Unable to load summary data.', 'ai' ) );
		} finally {
			setSummaryLoading( false );
		}
	}, [] );

	// Fetch logs
	const fetchLogs = useCallback( async () => {
		setLogsLoading( true );
		try {
			const params = new URLSearchParams( {
				page: String( page ),
				per_page: '25',
			} );
			if ( filters.type ) {
				params.append( 'type', filters.type );
			}
			if ( filters.status ) {
				params.append( 'status', filters.status );
			}
			if ( filters.provider ) {
				params.append( 'provider', filters.provider );
			}
			if ( filters.operation.length > 0 ) {
				// Send as comma-separated for REST API
				params.append( 'operation', filters.operation.join( ',' ) );
			}
			if ( filters.search ) {
				params.append( 'search', filters.search );
			}

			const response = ( await apiFetch< LogEntry[] >( {
				path: `${ settings.rest.routes.logs }?${ params.toString() }`,
				parse: false,
			} ) ) as Response;

			const data = await response.json();
			setLogs( data );
			setTotal(
				parseInt( response.headers.get( 'X-WP-Total' ) || '0', 10 )
			);
			setTotalPages(
				parseInt( response.headers.get( 'X-WP-TotalPages' ) || '1', 10 )
			);
			setError( null );
		} catch ( apiError ) {
			setError( getErrorMessage( apiError ) );
		} finally {
			setLogsLoading( false );
		}
	}, [ page, filters ] );

	// Fetch filter options
	const fetchFilters = useCallback( async () => {
		try {
			const response = await apiFetch< FilterOptions >( {
				path: settings.rest.routes.filters,
			} );
			// Ensure operations array exists (fallback for older backends)
			setFilterOptions( {
				...response,
				operations: response.operations ?? [],
			} );
		} catch ( apiError ) {
			showNotice(
				'error',
				__( 'Unable to load filter metadata.', 'ai' )
			);
		}
	}, [] );

	// Initial load
	useEffect( () => {
		fetchLogs();
		fetchFilters();
	}, [ fetchLogs, fetchFilters ] );

	// Handle period change
	const handlePeriodChange = (
		period: 'minute' | 'hour' | 'day' | 'week' | 'month' | 'all'
	) => {
		setSummaryPeriod( period );
		fetchSummary( period );
	};

	// Handle filter change
	const handleFilterChange = (
		key: keyof LogFilters,
		value: string | string[]
	) => {
		setFilters( ( prev ) => ( { ...prev, [ key ]: value } ) );
		setPage( 1 );
	};

	// Handle settings update
	const handleSettingsUpdate = async (
		newEnabled?: boolean,
		newRetention?: number
	) => {
		setSaving( true );
		try {
			const data: Record< string, unknown > = {};
			if ( newEnabled !== undefined ) {
				data.enabled = newEnabled;
			}
			if ( newRetention !== undefined ) {
				data.retention_days = newRetention;
			}

			await apiFetch( {
				path: settings.rest.routes.logs,
				method: 'POST',
				data,
			} );

			if ( newEnabled !== undefined ) {
				setEnabled( newEnabled );
			}
			if ( newRetention !== undefined ) {
				setRetentionDays( newRetention );
			}

			showNotice( 'success', __( 'Settings saved.', 'ai' ) );
		} catch ( apiError ) {
			showNotice( 'error', getErrorMessage( apiError ) );
		} finally {
			setSaving( false );
		}
	};

	// Handle purge
	const handlePurge = async () => {
		setPurging( true );
		try {
			await apiFetch( {
				path: settings.rest.routes.logs,
				method: 'DELETE',
			} );

			// Clear logs immediately for instant UI feedback
			setLogs( [] );
			setTotal( 0 );
			setTotalPages( 1 );
			setPage( 1 );

			showNotice( 'success', __( 'All logs have been purged.', 'ai' ) );
			fetchSummary( summaryPeriod );
			fetchFilters();
		} catch ( apiError ) {
			showNotice( 'error', getErrorMessage( apiError ) );
		} finally {
			setPurging( false );
		}
	};

	return (
		<div className="ai-request-logs__app">
			<HeaderPeriodSelector
				period={ summaryPeriod }
				onPeriodChange={ handlePeriodChange }
				loading={ summaryLoading }
			/>

			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			<SummaryCards summary={ summary } loading={ summaryLoading } />

			<div className="ai-request-logs__main">
				<LogsTable
					logs={ logs }
					filters={ filters }
					filterOptions={ filterOptions }
					onFilterChange={ handleFilterChange }
					onViewLog={ setSelectedLog }
					loading={ logsLoading }
					page={ page }
					totalPages={ totalPages }
					total={ total }
					onPageChange={ setPage }
					providerMetadata={ providerMetadata }
				/>

				<SettingsPanel
					enabled={ enabled }
					retentionDays={ retentionDays }
					onToggleEnabled={ ( value ) =>
						handleSettingsUpdate( value, undefined )
					}
					onRetentionChange={ ( value ) =>
						handleSettingsUpdate( undefined, value )
					}
					onPurgeLogs={ handlePurge }
					saving={ saving }
					purging={ purging }
				/>
			</div>

			{ selectedLog && (
				<LogDetailModal
					log={ selectedLog }
					onClose={ () => setSelectedLog( null ) }
				/>
			) }
		</div>
	);
};

const mountNode = document.getElementById( 'ai-request-logs-root' );

if ( mountNode ) {
	const root = createRoot( mountNode );
	root.render( <App /> );
}
