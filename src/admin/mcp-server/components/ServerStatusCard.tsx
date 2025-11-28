/**
 * WordPress dependencies
 */
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	TextControl,
} from '@wordpress/components';
import { check, pencil, closeSmall } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
/**
 * External dependencies
 */
import React, { useState } from 'react';

/**
 * Internal dependencies
 */
import type { CopyHandler, ServerDetails } from '../types';

interface ServerStatusCardProps {
	server: ServerDetails;
	onRename: ( newName: string ) => Promise< void >;
	onCopy: CopyHandler;
	profileUrl: string;
}

const ServerStatusCard: React.FC< ServerStatusCardProps > = ( {
	server,
	onRename,
	onCopy,
	profileUrl,
} ) => {
	const [ isEditing, setIsEditing ] = useState( false );
	const [ editName, setEditName ] = useState( server.name );
	const [ isSaving, setIsSaving ] = useState( false );

	const endpoint = server.http_endpoint ?? '';
	const cliCommand = server.cli_command ?? '';

	const handleStartEdit = () => {
		setEditName( server.name );
		setIsEditing( true );
	};

	const handleCancelEdit = () => {
		setEditName( server.name );
		setIsEditing( false );
	};

	const handleSaveEdit = async () => {
		if ( ! editName.trim() || editName === server.name ) {
			setIsEditing( false );
			return;
		}

		setIsSaving( true );
		try {
			await onRename( editName.trim() );
			setIsEditing( false );
		} finally {
			setIsSaving( false );
		}
	};

	const handleKeyDown = ( event: React.KeyboardEvent ) => {
		if ( event.key === 'Enter' ) {
			handleSaveEdit();
		} else if ( event.key === 'Escape' ) {
			handleCancelEdit();
		}
	};

	return (
		<Card className="ai-mcp-server__card ai-mcp-server__card--status">
			<CardHeader>
				<div className="ai-mcp-server__card-heading">
					<div className="ai-mcp-server__server-name">
						{ isEditing ? (
							<div className="ai-mcp-server__name-edit">
								<TextControl
									value={ editName }
									onChange={ setEditName }
									onKeyDown={ handleKeyDown }
									disabled={ isSaving }
									__nextHasNoMarginBottom
									__next40pxDefaultSize
								/>
								<Button
									icon={ check }
									label={ __( 'Save', 'ai' ) }
									onClick={ handleSaveEdit }
									disabled={ isSaving }
								/>
								<Button
									icon={ closeSmall }
									label={ __( 'Cancel', 'ai' ) }
									onClick={ handleCancelEdit }
									disabled={ isSaving }
								/>
							</div>
						) : (
							<div className="ai-mcp-server__name-display">
								<h3>{ server.name }</h3>
								<Button
									icon={ pencil }
									label={ __( 'Rename server', 'ai' ) }
									onClick={ handleStartEdit }
								/>
							</div>
						) }
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
