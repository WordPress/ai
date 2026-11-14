/**
 * Proposal review and editing card.
 */

/**
 * WordPress dependencies
 */
import {
	Button,
	SelectControl,
	TextareaControl,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { Proposal } from '../types';
import SafeHtml from './SafeHtml';

/**
 * Returns a string value from proposal arguments.
 *
 * @param args  Proposal arguments.
 * @param field Field name.
 */
function stringValue( args: Record< string, unknown >, field: string ): string {
	const value = args[ field ];

	if ( typeof value === 'string' ) {
		return value;
	}

	if ( value === null || value === undefined ) {
		return '';
	}

	return String( value );
}

/**
 * Returns a display label for an editable value.
 *
 * @param field The field name.
 * @param value The raw value.
 */
function displayValue( field: string, value: string ): string {
	if ( 'post_type' === field ) {
		return 'page' === value ? __( 'Page', 'ai' ) : __( 'Post', 'ai' );
	}

	if ( 'status' === field ) {
		return 'pending' === value
			? __( 'Pending review', 'ai' )
			: __( 'Draft', 'ai' );
	}

	return value;
}

/**
 * Renders one proposed or current value.
 *
 * @param props        Component props.
 * @param props.value  The value.
 * @param props.format The value format.
 */
function ValuePreview( {
	value,
	format,
}: {
	value: string | null;
	format: 'text' | 'html';
} ): React.JSX.Element {
	if ( value === null || '' === value ) {
		return <span className="ai-site-agent__proposal-empty">—</span>;
	}

	if ( 'html' === format ) {
		return (
			<SafeHtml html={ value } className="ai-site-agent__proposal-html" />
		);
	}

	return <span className="ai-site-agent__proposal-text">{ value }</span>;
}

interface ProposalCardProps {
	proposal: Proposal;
	busy: boolean;
	onApprove: ( args: Record< string, unknown > ) => void;
	onDecline: () => void;
}

/**
 * Renders the exact stored values, lets the person edit them, and handles the
 * two decisions.
 *
 * @param props           Component props.
 * @param props.proposal  The pending proposal.
 * @param props.busy      Whether a decision is in progress.
 * @param props.onApprove Called with the edited arguments.
 * @param props.onDecline Called when the proposal is declined.
 */
export default function ProposalCard( {
	proposal,
	busy,
	onApprove,
	onDecline,
}: ProposalCardProps ): React.JSX.Element {
	const [ values, setValues ] = useState< Record< string, unknown > >(
		proposal.args ?? {}
	);

	useEffect( () => {
		setValues( proposal.args ?? {} );
	}, [ proposal ] );

	const setValue = ( field: string, value: unknown ) => {
		setValues( ( current ) => ( { ...current, [ field ]: value } ) );
	};

	const changes = proposal.changes ?? [];
	const canEdit =
		'ai/site-agent-create-draft' === proposal.ability ||
		'ai/site-agent-update-post' === proposal.ability ||
		'ai/site-agent-update-setting' === proposal.ability;

	return (
		<section
			className="ai-site-agent__proposal"
			aria-label={ __( 'Action awaiting approval', 'ai' ) }
		>
			<header className="ai-site-agent__proposal-header">
				<h3>{ proposal.label }</h3>
				<span className="ai-site-agent__proposal-badge">
					{ __( 'Needs approval', 'ai' ) }
				</span>
			</header>

			{ proposal.description && (
				<p className="ai-site-agent__proposal-description">
					{ proposal.description }
				</p>
			) }

			<table className="ai-site-agent__proposal-table">
				<thead>
					<tr>
						<th scope="col">{ __( 'Field', 'ai' ) }</th>
						<th scope="col">{ __( 'Current', 'ai' ) }</th>
						<th scope="col">{ __( 'Proposed', 'ai' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ changes.map( ( change ) => {
						const proposed =
							change.field in values
								? displayValue(
										change.field,
										stringValue( values, change.field )
								  )
								: change.to;

						return (
							<tr key={ change.field }>
								<th scope="row">{ change.label }</th>
								<td>
									<ValuePreview
										value={ change.from }
										format={ change.format }
									/>
								</td>
								<td>
									<ValuePreview
										value={ proposed }
										format={ change.format }
									/>
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>

			{ canEdit && (
				<div className="ai-site-agent__proposal-editor">
					<h4>{ __( 'Edit before approving', 'ai' ) }</h4>

					{ 'ai/site-agent-create-draft' === proposal.ability && (
						<SelectControl
							__nextHasNoMarginBottom
							label={ __( 'Type', 'ai' ) }
							value={
								( stringValue( values, 'post_type' ) ||
									'post' ) as 'post' | 'page'
							}
							options={ [
								{ label: __( 'Post', 'ai' ), value: 'post' },
								{ label: __( 'Page', 'ai' ), value: 'page' },
							] }
							onChange={ ( value ) =>
								setValue( 'post_type', value )
							}
						/>
					) }

					{ ( 'ai/site-agent-create-draft' === proposal.ability ||
						'ai/site-agent-update-post' === proposal.ability ) && (
						<>
							<TextControl
								__nextHasNoMarginBottom
								label={ __( 'Title', 'ai' ) }
								value={ stringValue( values, 'title' ) }
								onChange={ ( value ) =>
									setValue( 'title', value )
								}
							/>
							<TextareaControl
								__nextHasNoMarginBottom
								label={ __( 'Content', 'ai' ) }
								help={ __(
									'HTML is allowed. The rendered preview is shown above.',
									'ai'
								) }
								rows={ 8 }
								value={ stringValue( values, 'content' ) }
								onChange={ ( value ) =>
									setValue( 'content', value )
								}
							/>
							<TextareaControl
								__nextHasNoMarginBottom
								label={ __( 'Excerpt', 'ai' ) }
								rows={ 3 }
								value={ stringValue( values, 'excerpt' ) }
								onChange={ ( value ) =>
									setValue( 'excerpt', value )
								}
							/>
						</>
					) }

					{ 'ai/site-agent-update-post' === proposal.ability && (
						<SelectControl
							__nextHasNoMarginBottom
							label={ __( 'Status', 'ai' ) }
							value={
								( stringValue( values, 'status' ) ||
									'draft' ) as 'draft' | 'pending'
							}
							options={ [
								{ label: __( 'Draft', 'ai' ), value: 'draft' },
								{
									label: __( 'Pending review', 'ai' ),
									value: 'pending',
								},
							] }
							onChange={ ( value ) =>
								setValue( 'status', value )
							}
						/>
					) }

					{ 'ai/site-agent-update-setting' === proposal.ability && (
						<TextControl
							__nextHasNoMarginBottom
							label={ __( 'New value', 'ai' ) }
							value={ stringValue( values, 'value' ) }
							onChange={ ( value ) => setValue( 'value', value ) }
						/>
					) }
				</div>
			) }

			<p className="ai-site-agent__proposal-note">
				{ __(
					'Nothing has been written yet. Approving runs the values shown here, and this proposal expires after 30 minutes.',
					'ai'
				) }
			</p>

			<div className="ai-site-agent__proposal-actions">
				<Button
					__next40pxDefaultSize
					variant="primary"
					onClick={ () => onApprove( values ) }
					disabled={ busy }
					isBusy={ busy }
				>
					{ __( 'Approve and run', 'ai' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="secondary"
					onClick={ onDecline }
					disabled={ busy }
				>
					{ __( 'Decline', 'ai' ) }
				</Button>
			</div>
		</section>
	);
}
