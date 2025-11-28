import { Button, Card, CardBody, CardHeader, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import React from 'react';

import type { CopyHandler, ServerDetails } from '../types';

interface ServerStatusCardProps {
	server: ServerDetails;
	globalEnabled: boolean;
	saving: boolean;
	onToggleGlobal: ( nextValue: boolean ) => void;
	onCopy: CopyHandler;
	profileUrl: string;
}

const ServerStatusCard: React.FC< ServerStatusCardProps > = ( {
	server,
	globalEnabled,
	saving,
	onToggleGlobal,
	onCopy,
	profileUrl,
} ) => {
	const statusKey = ! globalEnabled ? 'disabled' : server.status ?? 'initializing';
	const endpoint = server.http_endpoint ?? '';
	const cliCommand = server.cli_command ?? '';

	const statusLabelMap: Record< string, string > = {
		disabled: __( 'Disabled', 'ai' ),
		initializing: __( 'Starting…', 'ai' ),
		running: __( 'Running', 'ai' ),
	};

	const dotClass = [
		'ai-mcp-server__status-dot',
		statusKey === 'running' ? 'ai-mcp-server__status-dot--running' : '',
		statusKey === 'disabled' ? 'ai-mcp-server__status-dot--disabled' : '',
	]
		.filter( Boolean )
		.join( ' ' );

	return (
		<Card className="ai-mcp-server__card ai-mcp-server__card--status">
			<CardHeader>
				<div className="ai-mcp-server__card-heading">
					<div className="ai-mcp-server__status-text">
						<span className={ dotClass }></span>
						<strong>{ statusLabelMap[ statusKey ] ?? statusLabelMap.initializing }</strong>
					</div>
					<ToggleControl
						label={ __( 'Enable MCP', 'ai' ) }
						checked={ globalEnabled }
						onChange={ onToggleGlobal }
						disabled={ saving }
					/>
				</div>
			</CardHeader>
			<CardBody>
				<div className="ai-mcp-server__field">
					<label>{ __( 'HTTP endpoint', 'ai' ) }</label>
					<div className="ai-mcp-server__field-row">
						<code>{ endpoint || __( 'Not available yet', 'ai' ) }</code>
						<Button
							variant="secondary"
							onClick={ () => onCopy( endpoint, __( 'HTTP endpoint', 'ai' ) ) }
							disabled={ ! endpoint }
						>
							{ __( 'Copy URL', 'ai' ) }
						</Button>
					</div>
				</div>

				<div className="ai-mcp-server__field">
					<label>{ __( 'WP-CLI (STDIO)', 'ai' ) }</label>
					<div className="ai-mcp-server__field-row">
						<code>{ cliCommand || __( 'CLI command will appear once the server starts.', 'ai' ) }</code>
						<Button
							variant="secondary"
							onClick={ () => onCopy( cliCommand, __( 'WP-CLI command', 'ai' ) ) }
							disabled={ ! cliCommand }
						>
							{ __( 'Copy Command', 'ai' ) }
						</Button>
					</div>
				</div>

				<div className="ai-mcp-server__field">
					<label>{ __( 'REST route', 'ai' ) }</label>
					<code>{ `/${ server.route_namespace }/${ server.route }` }</code>
				</div>

				<p className="ai-mcp-server__hint">
					{ __( 'Use an Application Password when connecting Claude Desktop, Cursor, or other MCP clients.', 'ai' ) }
				</p>
				<Button
					variant="secondary"
					href={ profileUrl }
				>
					{ __( 'Manage Application Passwords', 'ai' ) }
				</Button>
			</CardBody>
		</Card>
	);
};

export default ServerStatusCard;
