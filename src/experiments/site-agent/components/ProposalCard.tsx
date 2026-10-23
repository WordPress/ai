/**
 * Proposal approval card.
 */

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { Proposal } from '../types';

const FIELD_LABELS: Record< string, string > = {
	title: __( 'Title', 'ai' ),
	content: __( 'Content', 'ai' ),
	excerpt: __( 'Excerpt', 'ai' ),
	status: __( 'Status', 'ai' ),
	post_type: __( 'Type', 'ai' ),
	name: __( 'Setting', 'ai' ),
	value: __( 'New value', 'ai' ),
	id: __( 'Item ID', 'ai' ),
	section: __( 'Section', 'ai' ),
	search: __( 'Search', 'ai' ),
	limit: __( 'Limit', 'ai' ),
};

/**
 * Formats an argument value for display.
 *
 * @param value Argument value.
 */
function formatValue( value: unknown ): string {
	if ( typeof value === 'string' ) {
		return value;
	}

	if ( value === null || value === undefined ) {
		return '';
	}

	try {
		const json = JSON.stringify( value, null, 2 );

		return typeof json === 'string' ? json : String( value );
	} catch {
		return String( value );
	}
}

interface ProposalCardProps {
	proposal: Proposal;
	busy: boolean;
	onApprove: () => void;
	onDecline: () => void;
}

/**
 * Renders the exact stored values and the two decisions.
 *
 * @param props Component props.
 */
export default function ProposalCard( {
	proposal,
	busy,
	onApprove,
	onDecline,
}: ProposalCardProps ): React.JSX.Element {
	const args = Object.entries( proposal.args ?? {} );

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

			<dl className="ai-site-agent__proposal-values">
				{ args.map( ( [ key, value ] ) => {
					const text = formatValue( value );

					return (
						<div
							key={ key }
							className="ai-site-agent__proposal-row"
						>
							<dt>{ FIELD_LABELS[ key ] ?? key }</dt>
							<dd>
								{ text.includes( '\n' ) ? (
									<pre>{ text }</pre>
								) : (
									text
								) }
							</dd>
						</div>
					);
				} ) }
			</dl>

			<p className="ai-site-agent__proposal-note">
				{ __(
					'Approve only if these exact values are right. Nothing has been written yet, and this proposal expires after 30 minutes.',
					'ai'
				) }
			</p>

			<div className="ai-site-agent__proposal-actions">
				<Button
					__next40pxDefaultSize
					variant="primary"
					onClick={ onApprove }
					disabled={ busy }
					isBusy={ busy }
				>
					{ __( 'Approve', 'ai' ) }
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
