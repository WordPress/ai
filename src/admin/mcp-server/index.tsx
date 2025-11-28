import './style.scss';

import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, SelectControl, Spinner } from '@wordpress/components';
import { dispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { __, sprintf } from '@wordpress/i18n';
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';

import ConfigGenerator from './components/ConfigGenerator';
import ServerStatusCard from './components/ServerStatusCard';
import TestConnectionPanel from './components/TestConnectionPanel';
import ToolsTable from './components/ToolsTable';
import type {
	ConfigTemplate,
	LocalizedSettings,
	McpOverview,
	ServerSummary,
	TestResult,
	ToolSummary,
} from './types';

const settings: LocalizedSettings = window.aiMcpServerSettings;

apiFetch.use( apiFetch.createNonceMiddleware( settings.rest.nonce ) );
apiFetch.use( apiFetch.createRootURLMiddleware( settings.rest.root ) );

const showNotice = ( status: 'success' | 'error' | 'warning', message: string ) =>
	dispatch( noticesStore ).createNotice( status, message, { type: 'snackbar' } );

const getErrorMessage = ( error: unknown ): string => {
	if ( typeof error === 'string' ) {
		return error;
	}

	if ( error && typeof error === 'object' && 'message' in error ) {
		return String( ( error as { message: string } ).message );
	}

	return __( 'Something went wrong. Please try again.', 'ai' );
};

const getEnabledToolNames = ( tools: ToolSummary[] ): string[] => tools.filter( ( tool ) => tool.enabled ).map( ( tool ) => tool.name );

const App: React.FC = () => {
	const [ data, setData ] = useState< McpOverview | null >( null );
	const [ selectedServerId, setSelectedServerId ] = useState< string | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ savingTools, setSavingTools ] = useState( false );
	const [ savingSettings, setSavingSettings ] = useState( false );
	const [ testing, setTesting ] = useState( false );
	const [ testResult, setTestResult ] = useState< TestResult | null >( null );

	const fetchOverview = useCallback( async ( serverId?: string ) => {
		setLoading( true );
		try {
			const path =
				settings.rest.routes.overview + ( serverId ? `?server_id=${ encodeURIComponent( serverId ) }` : '' );
			const response = ( await apiFetch( { path } ) ) as McpOverview;
			setData( response );
			setSelectedServerId( response.activeServerId );
			setError( null );
		} catch ( apiError ) {
			setError( getErrorMessage( apiError ) );
		} finally {
			setLoading( false );
		}
	}, [] );

	useEffect( () => {
		fetchOverview();
	}, [ fetchOverview ] );

	const handleToggleServer = async ( nextValue: boolean ) => {
		setSavingSettings( true );
		try {
			const response = ( await apiFetch( {
				path: settings.rest.routes.enabled,
				method: 'POST',
				data: { enabled: nextValue, server_id: selectedServerId },
			} ) ) as McpOverview;
			setData( response );
			setSelectedServerId( response.activeServerId );
			showNotice(
				nextValue ? 'success' : 'warning',
				nextValue ? __( 'MCP enabled.', 'ai' ) : __( 'MCP disabled.', 'ai' )
			);
		} catch ( apiError ) {
			const message = getErrorMessage( apiError );
			showNotice( 'error', message );
			setError( message );
		} finally {
			setSavingSettings( false );
		}
	};

	const handleToggleTool = async ( name: string, nextValue: boolean ) => {
		if ( savingTools ) {
			return;
		}

		if ( ! data?.activeServerId ) {
			return;
		}

		const currentNames = getEnabledToolNames( data.tools );
		const payload = nextValue
			? Array.from( new Set( [ ...currentNames, name ] ) )
			: currentNames.filter( ( toolName ) => toolName !== name );

		setSavingTools( true );
		try {
			const response = ( await apiFetch( {
				path: settings.rest.routes.tools,
				method: 'POST',
				data: { tools: payload, serverId: data.activeServerId },
			} ) ) as McpOverview;
			setData( response );
			setSelectedServerId( response.activeServerId );
		} catch ( apiError ) {
			const message = getErrorMessage( apiError );
			showNotice( 'error', message );
		} finally {
			setSavingTools( false );
		}
	};

	const handleCopy = async ( value: string, label: string ) => {
		if ( ! value ) {
			return;
		}

		try {
			if ( navigator.clipboard?.writeText ) {
				await navigator.clipboard.writeText( value );
			} else {
				const temp = document.createElement( 'textarea' );
				temp.value = value;
				document.body.appendChild( temp );
				temp.select();
				document.execCommand( 'copy' );
				document.body.removeChild( temp );
			}

			showNotice( 'success', sprintf( __( '%s copied to clipboard.', 'ai' ), label ) );
		} catch ( copyError ) {
			showNotice( 'error', sprintf( __( 'Could not copy %s.', 'ai' ), label ) );
		}
	};

	const handleTestConnection = async () => {
		if ( ! data?.activeServerId ) {
			return;
		}

		setTesting( true );
		try {
			const response = ( await apiFetch( {
				path: settings.rest.routes.test,
				method: 'POST',
				data: { serverId: data.activeServerId },
			} ) ) as TestResult;
			setTestResult( response );
			showNotice( response.success ? 'success' : 'error', response.message );
		} catch ( apiError ) {
			const message = getErrorMessage( apiError );
			setTestResult( { success: false, code: null, message } );
			showNotice( 'error', message );
		} finally {
			setTesting( false );
		}
	};

	const templates = useMemo(
		() => ( data?.configTemplates ?? {} ) as Record< string, ConfigTemplate >,
		[ data?.configTemplates ]
	);

	const handleSelectServer = async ( serverId: string ) => {
		if ( ! serverId ) {
			return;
		}

		setSelectedServerId( serverId );
		await fetchOverview( serverId );
	};

	const handleAddServer = async () => {
		const name = window.prompt( __( 'Enter a name for the new server:', 'ai' ) );

		if ( ! name ) {
			return;
		}

		try {
			const response = ( await apiFetch( {
				path: settings.rest.routes.addServer,
				method: 'POST',
				data: {
					server: { name },
				},
			} ) ) as McpOverview;
			setData( response );
			setSelectedServerId( response.activeServerId );
			showNotice( 'success', __( 'Server created.', 'ai' ) );
		} catch ( apiError ) {
			showNotice( 'error', getErrorMessage( apiError ) );
		}
	};

	const activeServer = data?.activeServer ?? null;
	const serverOptions = ( data?.servers ?? [] ).map( ( server: ServerSummary ) => ( {
		label: server.name,
		value: server.id,
	} ) );

	return (
		<div className="ai-mcp-server__app">
			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			{ loading ? (
				<div className="ai-mcp-server__loading">
					<Spinner />
					<span>{ __( 'Loading MCP server data…', 'ai' ) }</span>
				</div>
			 ) : (
				<>
					<div className="ai-mcp-server__toolbar">
						<SelectControl
							label={ __( 'Server', 'ai' ) }
							value={ selectedServerId ?? '' }
							onChange={ handleSelectServer }
							options={ serverOptions }
							__nextHasNoMarginBottom
						/>
						<Button variant="secondary" onClick={ handleAddServer }>
							{ __( 'Add Server', 'ai' ) }
						</Button>
					</div>

					{ activeServer ? (
						<>
							<div className="ai-mcp-server__grid">
								<ServerStatusCard
									server={ activeServer }
									globalEnabled={ data?.enabled ?? false }
									saving={ savingSettings }
									onToggleGlobal={ handleToggleServer }
									onCopy={ handleCopy }
									profileUrl={ settings.profileUrl }
								/>
								<ConfigGenerator templates={ templates } onCopy={ handleCopy } />
							</div>

							<ToolsTable
								tools={ data?.tools ?? [] }
								saving={ savingTools }
								serverEnabled={ data?.enabled ?? false }
								onToggle={ handleToggleTool }
							/>

							<TestConnectionPanel testing={ testing } result={ testResult } onTest={ handleTestConnection } />
						</>
					) : (
						<Notice status="warning" isDismissible={ false }>
							{ __( 'No MCP servers are configured yet.', 'ai' ) }
						</Notice>
					) }
				</>
			 ) }
		</div>
	);
};

const mountNode = document.getElementById( 'ai-mcp-server-root' );

if ( mountNode ) {
	const root = createRoot( mountNode );
	root.render( <App /> );
}
