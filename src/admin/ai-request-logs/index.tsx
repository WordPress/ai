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
import type { Filter, View } from '@wordpress/dataviews';
import { store as noticesStore } from '@wordpress/notices';
import { __ } from '@wordpress/i18n';

/**
 * External dependencies
 */
import React, { useCallback, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';

/**
 * Internal dependencies
 */
import { usePersistedView } from '../hooks/usePersistedView';

import HeaderPeriodSelector from './components/HeaderPeriodSelector';
import LogDetailModal from './components/LogDetailModal';
import LogsTable from './components/LogsTable';
import SettingsPanel from './components/SettingsPanel';
import SummaryCards from './components/SummaryCards';
import type {
	FilterOptions,
	LocalizedSettings,
	LogEntry,
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

const defaultView: View = {
	type: 'table',
	perPage: 25,
	page: 1,
	search: '',
	filters: [],
	fields: [
		'timestamp',
		'operation',
		'provider',
		'tokens_total',
		'duration_ms',
		'status',
		'actions',
	],
	sort: {
		field: 'timestamp',
		direction: 'desc',
	},
	layout: {
		density: 'comfortable',
	},
};

const normalizeFilterValue = ( raw: unknown ): string => {
	if ( Array.isArray( raw ) ) {
		return normalizeFilterValue( raw[ 0 ] );
	}
	if (
		raw &&
		typeof raw === 'object' &&
		'value' in ( raw as Record< string, unknown > )
	) {
		return normalizeFilterValue( ( raw as { value: unknown } ).value );
	}
	if ( typeof raw === 'string' ) {
		return raw;
	}
	return '';
};

const normalizeFilterArrayValue = ( raw: unknown ): string[] => {
	if ( Array.isArray( raw ) ) {
		return raw.map( ( item ) => {
			if ( typeof item === 'string' ) {
				return item;
			}
			if ( item && typeof item === 'object' && 'value' in item ) {
				return String( ( item as { value: unknown } ).value );
			}
			return String( item );
		} );
	}
	if ( typeof raw === 'string' && raw ) {
		return [ raw ];
	}
	return [];
};

const extractFilterValue = (
	filters: Filter[] | undefined,
	field: string
): string => {
	const match = filters?.find( ( entry ) => entry.field === field );
	return normalizeFilterValue( match?.value ?? '' );
};

const extractFilterArrayValue = (
	filters: Filter[] | undefined,
	field: string
): string[] => {
	const match = filters?.find( ( entry ) => entry.field === field );
	return normalizeFilterArrayValue( match?.value ?? [] );
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
	const [ totalPages, setTotalPages ] = useState( 1 );
	const [ total, setTotal ] = useState( 0 );

	const { view, setView } = usePersistedView< View >(
		'ai-request-logs',
		defaultView
	);
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
				page: String( view.page ?? 1 ),
				per_page: '25',
			} );
			const typeFilter = extractFilterValue( view.filters, 'type' );
			const statusFilter = extractFilterValue( view.filters, 'status' );
			const providerFilter = extractFilterValue(
				view.filters,
				'provider'
			);
			const operationFilter = extractFilterArrayValue(
				view.filters,
				'operation'
			);
			const operationPatternFilter = extractFilterValue(
				view.filters,
				'operation_pattern'
			);
			const tokensFilterValue = extractFilterValue(
				view.filters,
				'tokens_total'
			);
			const searchTerm = view.search ?? '';

			if ( typeFilter ) {
				params.append( 'type', typeFilter );
			}
			if ( statusFilter ) {
				params.append( 'status', statusFilter );
			}
			if ( providerFilter ) {
				params.append( 'provider', providerFilter );
			}
			if ( operationFilter.length > 0 ) {
				params.append( 'operation', operationFilter.join( ',' ) );
			}
			if ( operationPatternFilter ) {
				params.append( 'operation_pattern', operationPatternFilter );
			}
			if ( tokensFilterValue ) {
				params.append( 'tokens_filter', tokensFilterValue );
			}
			if ( searchTerm ) {
				params.append( 'search', searchTerm );
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
	}, [ view.filters, view.page, view.search ] );

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
			setView( ( prev ) => ( { ...prev, page: 1 } ) );

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
					filterOptions={ filterOptions }
					onViewLog={ setSelectedLog }
					loading={ logsLoading }
					totalPages={ totalPages }
					total={ total }
					view={ view }
					setView={ setView }
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
