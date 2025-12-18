/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
/**
 * External dependencies
 */
import React from 'react';

type StatusKey = 'running' | 'initializing' | 'disabled';

const statusLabelMap: Record< StatusKey, string > = {
	running: __( 'Running', 'ai' ),
	initializing: __( 'Starting…', 'ai' ),
	disabled: __( 'Disabled', 'ai' ),
};

interface StatusBadgeProps {
	status: StatusKey;
}

export const getStatusLabel = ( status: StatusKey ): string =>
	statusLabelMap[ status ] ?? statusLabelMap.initializing;

const StatusBadge: React.FC< StatusBadgeProps > = ( { status } ) => {
	const dotClass = [
		'ai-mcp-server__status-dot',
		status === 'running' ? 'ai-mcp-server__status-dot--running' : '',
		status === 'disabled' ? 'ai-mcp-server__status-dot--disabled' : '',
	]
		.filter( Boolean )
		.join( ' ' );

	return (
		<span className="ai-mcp-server__status-text">
			<span className={ dotClass } aria-hidden="true"></span>
			<strong>{ getStatusLabel( status ) }</strong>
		</span>
	);
};

export default StatusBadge;
