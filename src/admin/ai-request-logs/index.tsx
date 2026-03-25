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
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * External dependencies
 */
import React, {
	useCallback,
	useDeferredValue,
	useEffect,
	useState,
} from 'react';
import { createRoot } from 'react-dom/client';

/**
 * Internal dependencies
 */
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
	LogsQuery,
	SummaryPeriod,
} from './types';

const settings: LocalizedSettings =
	window.aiRequestLogsSettings ??
	window.aiAiRequestLogsSettings ??
	window.AiRequestLogsSettings ??
	( () => {
		throw new Error( 'AiRequestLogsSettings is not defined.' );
	} )();

const providerMetadata = settings.providerMetadata ?? {};
const LOGS_QUERY_STORAGE_KEY = 'ai.requestLogs.query';

const DEFAULT_LOGS_QUERY: LogsQuery = {
	page: 1,
	search: '',
	type: '',
	status: '',
	provider: '',
	operation: '',
	tokensFilter: '',
};

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

const normalizeLogsQuery = ( raw: unknown ): LogsQuery => {
	const parsed =
		raw && typeof raw === 'object' ? ( raw as Partial< LogsQuery > ) : {};

	return {
		page:
			typeof parsed.page === 'number' && parsed.page > 0
				? Math.floor( parsed.page )
				: DEFAULT_LOGS_QUERY.page,
		search:
			typeof parsed.search === 'string'
				? parsed.search
				: DEFAULT_LOGS_QUERY.search,
		type:
			typeof parsed.type === 'string'
				? parsed.type
				: DEFAULT_LOGS_QUERY.type,
		status:
			typeof parsed.status === 'string'
				? parsed.status
				: DEFAULT_LOGS_QUERY.status,
		provider:
			typeof parsed.provider === 'string'
				? parsed.provider
				: DEFAULT_LOGS_QUERY.provider,
		operation:
			typeof parsed.operation === 'string'
				? parsed.operation
				: DEFAULT_LOGS_QUERY.operation,
		tokensFilter:
			typeof parsed.tokensFilter === 'string'
				? parsed.tokensFilter
				: DEFAULT_LOGS_QUERY.tokensFilter,
	};
};

const getInitialLogsQuery = (): LogsQuery => {
	try {
		const persisted = window.localStorage.getItem( LOGS_QUERY_STORAGE_KEY );

		if ( ! persisted ) {
			return DEFAULT_LOGS_QUERY;
		}

		return normalizeLogsQuery( JSON.parse( persisted ) );
	} catch {
		return DEFAULT_LOGS_QUERY;
	}
};

const App: React.FC = () => {
	const [ enabled, setEnabled ] = useState( settings.initialState.enabled );
	const [ retentionDays, setRetentionDays ] = useState(
		settings.initialState.retentionDays
	);

	const [ summary, setSummary ] = useState< LogSummary >(
		settings.initialState.summary
	);
	const [ summaryPeriod, setSummaryPeriod ] =
		useState< SummaryPeriod >( 'day' );
	const [ summaryLoading, setSummaryLoading ] = useState( false );

	const [ logs, setLogs ] = useState< LogEntry[] >( [] );
	const [ logsLoading, setLogsLoading ] = useState( true );
	const [ totalPages, setTotalPages ] = useState( 1 );
	const [ total, setTotal ] = useState( 0 );
	const [ logsQuery, setLogsQuery ] =
		useState< LogsQuery >( getInitialLogsQuery );
	const deferredSearch = useDeferredValue( logsQuery.search );

	const [ filterOptions, setFilterOptions ] = useState< FilterOptions >(
		settings.initialState.filters
	);

	const [ selectedLog, setSelectedLog ] = useState< LogEntry | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ saving, setSaving ] = useState( false );
	const [ purging, setPurging ] = useState( false );

	useEffect( () => {
		try {
			window.localStorage.setItem(
				LOGS_QUERY_STORAGE_KEY,
				JSON.stringify( logsQuery )
			);
		} catch {
			// Ignore localStorage persistence failures.
		}
	}, [ logsQuery ] );

	const fetchSummary = useCallback( async ( period: SummaryPeriod ) => {
		setSummaryLoading( true );
		try {
			const response = await apiFetch< LogSummary >( {
				path: `${ settings.rest.routes.summary }?period=${ period }`,
			} );
			setSummary( response );
		} catch {
			showNotice( 'error', __( 'Unable to load summary data.', 'ai' ) );
		} finally {
			setSummaryLoading( false );
		}
	}, [] );

	const fetchLogs = useCallback( async () => {
		setLogsLoading( true );

		try {
			const params = new URLSearchParams( {
				page: String( logsQuery.page ),
				per_page: '25',
			} );

			if ( logsQuery.type ) {
				params.append( 'type', logsQuery.type );
			}
			if ( logsQuery.status ) {
				params.append( 'status', logsQuery.status );
			}
			if ( logsQuery.provider ) {
				params.append( 'provider', logsQuery.provider );
			}
			if ( logsQuery.operation ) {
				params.append( 'operation', logsQuery.operation );
			}
			if ( logsQuery.tokensFilter ) {
				params.append( 'tokens_filter', logsQuery.tokensFilter );
			}
			if ( deferredSearch ) {
				params.append( 'search', deferredSearch );
			}

			const response = await apiFetch< LogEntry[], false >( {
				path: `${ settings.rest.routes.logs }?${ params.toString() }`,
				parse: false,
			} );

			const data = ( await response.json() ) as LogEntry[];

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
	}, [
		deferredSearch,
		logsQuery.operation,
		logsQuery.page,
		logsQuery.provider,
		logsQuery.status,
		logsQuery.tokensFilter,
		logsQuery.type,
	] );

	const fetchFilters = useCallback( async () => {
		try {
			const response = await apiFetch< FilterOptions >( {
				path: settings.rest.routes.filters,
			} );

			setFilterOptions( {
				...response,
				operations: response.operations ?? [],
			} );
		} catch {
			showNotice(
				'error',
				__( 'Unable to load filter metadata.', 'ai' )
			);
		}
	}, [] );

	useEffect( () => {
		fetchFilters();
	}, [ fetchFilters ] );

	useEffect( () => {
		fetchLogs();
	}, [ fetchLogs ] );

	const handlePeriodChange = ( period: SummaryPeriod ) => {
		setSummaryPeriod( period );
		fetchSummary( period );
	};

	const handleSettingsUpdate = async (
		newEnabled?: boolean,
		newRetention?: number
	) => {
		setSaving( true );

		try {
			const data: {
				enabled?: boolean;
				retention_days?: number;
			} = {};

			if ( undefined !== newEnabled ) {
				data.enabled = newEnabled;
			}

			if ( undefined !== newRetention ) {
				data.retention_days = newRetention;
			}

			await apiFetch( {
				path: settings.rest.routes.logs,
				method: 'POST',
				data,
			} );

			if ( undefined !== newEnabled ) {
				setEnabled( newEnabled );
			}

			if ( undefined !== newRetention ) {
				setRetentionDays( newRetention );
			}

			showNotice( 'success', __( 'Settings saved.', 'ai' ) );
		} catch ( apiError ) {
			showNotice( 'error', getErrorMessage( apiError ) );
		} finally {
			setSaving( false );
		}
	};

	const handlePurge = async () => {
		setPurging( true );

		try {
			await apiFetch( {
				path: settings.rest.routes.logs,
				method: 'DELETE',
			} );

			setLogs( [] );
			setTotal( 0 );
			setTotalPages( 1 );
			setLogsQuery( ( previous ) => ( {
				...previous,
				page: 1,
			} ) );

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
					query={ logsQuery }
					setQuery={ setLogsQuery }
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
