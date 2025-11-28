/**
 * WordPress dependencies
 */
import { Button, Modal } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * External dependencies
 */
import React from 'react';

/**
 * Internal dependencies
 */
import type { LogEntry } from '../types';

interface LogDetailModalProps {
	log: LogEntry;
	onClose: () => void;
}

const formatTimestamp = ( timestamp: string ): string => {
	const date = new Date( timestamp + 'Z' );
	return date.toLocaleString( undefined, {
		dateStyle: 'full',
		timeStyle: 'medium',
	} );
};

const formatCost = ( cost: number | null ): string => {
	if ( cost === null ) {
		return '-';
	}
	if ( cost < 0.01 ) {
		return '$' + cost.toFixed( 6 );
	}
	return '$' + cost.toFixed( 4 );
};

const formatKindLabel = ( value: string ): string =>
	value
		.split( '_' )
		.map( ( part ) => part.charAt( 0 ).toUpperCase() + part.slice( 1 ) )
		.join( ' ' );

const formatTokensPerSecond = ( value: number | null ): string => {
	if ( value === null ) {
		return '-';
	}
	if ( value >= 1000 ) {
		return ( value / 1000 ).toFixed( 1 ) + 'K';
	}
	return value.toFixed( 1 );
};

const LogDetailModal: React.FC< LogDetailModalProps > = ( {
	log,
	onClose,
} ) => {
	const inputPreview =
		typeof log.context?.input_preview === 'string'
			? log.context.input_preview
			: null;
	const outputPreview =
		typeof log.context?.output_preview === 'string'
			? log.context.output_preview
			: null;
	const requestKind =
		typeof log.context?.request_kind === 'string'
			? log.context.request_kind
			: null;

	const imageUrlValue = log.context?.image_urls;
	const imageUrls = Array.isArray( imageUrlValue )
		? imageUrlValue.filter(
				( url ): url is string =>
					typeof url === 'string' && url.length > 0
		  )
		: [];

	const base64Value = log.context?.image_base64_samples;
	const base64Images = Array.isArray( base64Value )
		? base64Value
				.map( ( sample ) =>
					typeof sample === 'object' && sample !== null
						? sample
						: null
				)
				.filter(
					(
						sample
					): sample is { data: string; mime?: string } =>
						Boolean(
							sample &&
								typeof sample === 'object' &&
								typeof ( sample as { data?: unknown } ).data ===
									'string' &&
								( sample as { data: string } ).data.length > 0
						)
				)
		: [];

	const handleCopyId = async () => {
		try {
			await navigator.clipboard.writeText( log.id );
		} catch ( e ) {
			// Fallback for older browsers
			const textarea = document.createElement( 'textarea' );
			textarea.value = log.id;
			document.body.appendChild( textarea );
			textarea.select();
			document.execCommand( 'copy' );
			document.body.removeChild( textarea );
		}
	};

	const getStatusIcon = ( status: string ): string => {
		switch ( status ) {
			case 'success':
				return '\u2713'; // Checkmark
			case 'error':
				return '\u2717'; // X mark
			case 'timeout':
				return '\u23F1'; // Stopwatch
			default:
				return '';
		}
	};

	return (
		<Modal
			title={ __( 'Request Details', 'ai' ) }
			onRequestClose={ onClose }
			className="ai-request-logs__modal"
			size="large"
		>
			<div className="ai-request-logs__detail">
				<div className="ai-request-logs__detail-header">
					<code className="ai-request-logs__detail-operation">
						{ log.operation }
					</code>
					<span
						className={ `ai-request-logs__status ai-request-logs__status--${ log.status }` }
					>
						{ getStatusIcon( log.status ) } { log.status }
					</span>
				</div>

				<div className="ai-request-logs__detail-section">
					<h3>{ __( 'General', 'ai' ) }</h3>
					<table className="ai-request-logs__detail-table">
						<tbody>
							<tr>
								<th>{ __( 'Timestamp', 'ai' ) }</th>
								<td>{ formatTimestamp( log.timestamp ) }</td>
							</tr>
							<tr>
								<th>{ __( 'Duration', 'ai' ) }</th>
								<td>
									{ log.duration_ms !== null
										? sprintf(
												/* translators: %d: request duration in milliseconds. */
												__( '%d ms', 'ai' ),
												log.duration_ms
										  )
										: '-' }
								</td>
							</tr>
							<tr>
								<th>{ __( 'Type', 'ai' ) }</th>
								<td>{ log.type }</td>
							</tr>
							{ requestKind && (
								<tr>
									<th>{ __( 'Request Kind', 'ai' ) }</th>
									<td>{ formatKindLabel( requestKind ) }</td>
								</tr>
							) }
							{ log.user_id && (
								<tr>
									<th>{ __( 'User ID', 'ai' ) }</th>
									<td>{ log.user_id }</td>
								</tr>
							) }
						</tbody>
					</table>
				</div>

				{ ( log.provider || log.model ) && (
					<div className="ai-request-logs__detail-section">
						<h3>{ __( 'Provider & Model', 'ai' ) }</h3>
						<table className="ai-request-logs__detail-table">
							<tbody>
								{ log.provider && (
									<tr>
										<th>{ __( 'Provider', 'ai' ) }</th>
										<td>{ log.provider }</td>
									</tr>
								) }
								{ log.model && (
									<tr>
										<th>{ __( 'Model', 'ai' ) }</th>
										<td>{ log.model }</td>
									</tr>
								) }
							</tbody>
						</table>
					</div>
				) }

				{ ( log.tokens_input !== null ||
					log.tokens_output !== null ) && (
					<div className="ai-request-logs__detail-section">
						<h3>{ __( 'Token Usage', 'ai' ) }</h3>
						<table className="ai-request-logs__detail-table">
							<tbody>
								<tr>
									<th>{ __( 'Input Tokens', 'ai' ) }</th>
									<td>
										{ log.tokens_input?.toLocaleString() ??
											'-' }
									</td>
								</tr>
								<tr>
									<th>{ __( 'Output Tokens', 'ai' ) }</th>
									<td>
										{ log.tokens_output?.toLocaleString() ??
											'-' }
									</td>
								</tr>
								<tr>
									<th>{ __( 'Total Tokens', 'ai' ) }</th>
									<td>
										{ log.tokens_total?.toLocaleString() ??
											'-' }
									</td>
								</tr>
								<tr>
									<th>{ __( 'Tokens per Second', 'ai' ) }</th>
									<td>
										{ formatTokensPerSecond(
											log.tokens_per_second ?? null
										) }
									</td>
								</tr>
								<tr>
									<th>{ __( 'Estimated Cost', 'ai' ) }</th>
									<td>{ formatCost( log.cost_estimate ) }</td>
								</tr>
							</tbody>
						</table>
					</div>
				) }

				{ log.error_message && (
					<div className="ai-request-logs__detail-section ai-request-logs__detail-section--error">
						<h3>{ __( 'Error', 'ai' ) }</h3>
						<pre className="ai-request-logs__detail-error">
							{ log.error_message }
						</pre>
					</div>
				) }

				{ inputPreview && (
					<div className="ai-request-logs__detail-section">
						<h3>{ __( 'Input Preview', 'ai' ) }</h3>
						<pre className="ai-request-logs__detail-context">
							{ inputPreview }
						</pre>
					</div>
				) }

				{ outputPreview && (
					<div className="ai-request-logs__detail-section">
						<h3>{ __( 'Output Preview', 'ai' ) }</h3>
						<pre className="ai-request-logs__detail-context">
							{ outputPreview }
						</pre>
					</div>
				) }

				{ ( imageUrls.length > 0 || base64Images.length > 0 ) && (
					<div className="ai-request-logs__detail-section">
						<h3>{ __( 'Generated Images', 'ai' ) }</h3>
						<div className="ai-request-logs__image-grid">
							{ imageUrls.map( ( url, index ) => (
								<figure key={ `image-url-${ index }` }>
									<img
										src={ url }
										alt={ __(
											'Generated image output',
											'ai'
										) }
									/>
									<figcaption>
										{ sprintf(
											/* translators: %d: image index */
											__( 'Image %d', 'ai' ),
											index + 1
										) }
									</figcaption>
								</figure>
							) ) }
							{ base64Images.map( ( sample, index ) => (
								<figure key={ `image-b64-${ index }` }>
									<img
										src={ `data:${ sample.mime ?? 'image/png' };base64,${ sample.data }` }
										alt={ __(
											'Generated image output (base64)',
											'ai'
										) }
									/>
									<figcaption>
										{ sprintf(
											/* translators: %d: image index */
											__( 'Image %d', 'ai' ),
											imageUrls.length + index + 1
										) }
									</figcaption>
								</figure>
							) ) }
						</div>
					</div>
				) }

				{ log.context && Object.keys( log.context ).length > 0 && (
					<div className="ai-request-logs__detail-section">
						<h3>{ __( 'Context', 'ai' ) }</h3>
						<pre className="ai-request-logs__detail-context">
							{ JSON.stringify( log.context, null, 2 ) }
						</pre>
					</div>
				) }

				<div className="ai-request-logs__detail-footer">
					<code className="ai-request-logs__detail-id">
						{ log.id }
					</code>
					<Button
						variant="secondary"
						size="small"
						onClick={ handleCopyId }
					>
						{ __( 'Copy Log ID', 'ai' ) }
					</Button>
				</div>
			</div>
		</Modal>
	);
};

export default LogDetailModal;
