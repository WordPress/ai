import { Button, Card, CardBody, CardHeader, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import React from 'react';

import StatusBadge from './StatusBadge';
import type { CopyHandler, ServerDetails } from '../types';

interface ServerStatusCardProps {
	server: ServerDetails;
	savingServer: boolean;
	onToggleServer: ( nextValue: boolean ) => void;
	onCopy: CopyHandler;
	profileUrl: string;
}

const ServerStatusCard: React.FC< ServerStatusCardProps > = ( {
	server,
	savingServer,
	onToggleServer,
	onCopy,
	profileUrl,
} ) => {
	const statusKey = server.status ?? 'initializing';
	const endpoint = server.http_endpoint ?? '';
	const cliCommand = server.cli_command ?? '';

	return (
		<Card className="ai-mcp-server__card ai-mcp-server__card--status">
			<CardHeader>
				<div className="ai-mcp-server__card-heading">
					<StatusBadge status={ statusKey } />
					<ToggleControl
						label={ __( 'Enable this server', 'ai' ) }
						checked={ server.enabled }
						onChange={ onToggleServer }
						disabled={ savingServer }
						__nextHasNoMarginBottom
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
