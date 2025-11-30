/**
 * WordPress dependencies
 */
import { Button, Card, CardBody, CardHeader } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
/**
 * External dependencies
 */
import React from 'react';

/**
 * Internal dependencies
 */
import type { CopyHandler, ServerDetails } from '../types';

interface ServerStatusCardProps {
	server: ServerDetails;
	onCopy: CopyHandler;
	profileUrl: string;
}

const ServerStatusCard: React.FC< ServerStatusCardProps > = ( {
	server,
	onCopy,
	profileUrl,
} ) => {
	const endpoint = server.http_endpoint ?? '';
	const cliCommand = server.cli_command ?? '';

	return (
		<Card className="ai-mcp-server__card ai-mcp-server__card--status">
			<CardHeader>
				<div className="ai-mcp-server__card-heading">
					<div className="ai-mcp-server__server-name">
						<h3>{ server.name }</h3>
					</div>
				</div>
			</CardHeader>
			<CardBody>
				<div className="ai-mcp-server__field">
					<div className="ai-mcp-server__field-label">
						{ __( 'HTTP endpoint', 'ai' ) }
					</div>
					<div className="ai-mcp-server__field-row">
						<code>
							{ endpoint || __( 'Not available yet', 'ai' ) }
						</code>
						<Button
							variant="secondary"
							onClick={ () =>
								onCopy( endpoint, __( 'HTTP endpoint', 'ai' ) )
							}
							disabled={ ! endpoint }
						>
							{ __( 'Copy URL', 'ai' ) }
						</Button>
					</div>
				</div>

				<div className="ai-mcp-server__field">
					<div className="ai-mcp-server__field-label">
						{ __( 'WP-CLI (STDIO)', 'ai' ) }
					</div>
					<div className="ai-mcp-server__field-row">
						<code>
							{ cliCommand ||
								__(
									'CLI command will appear once the server starts.',
									'ai'
								) }
						</code>
						<Button
							variant="secondary"
							onClick={ () =>
								onCopy(
									cliCommand,
									__( 'WP-CLI command', 'ai' )
								)
							}
							disabled={ ! cliCommand }
						>
							{ __( 'Copy Command', 'ai' ) }
						</Button>
					</div>
				</div>

				<div className="ai-mcp-server__field">
					<div className="ai-mcp-server__field-label">
						{ __( 'REST route', 'ai' ) }
					</div>
					<code>{ `/${ server.route_namespace }/${ server.route }` }</code>
				</div>

				<p className="ai-mcp-server__hint">
					{ __(
						'Use an Application Password when connecting Claude Desktop, Cursor, or other MCP clients.',
						'ai'
					) }
				</p>
				<Button variant="secondary" href={ profileUrl }>
					{ __( 'Manage Application Passwords', 'ai' ) }
				</Button>
			</CardBody>
		</Card>
	);
};

export default ServerStatusCard;
