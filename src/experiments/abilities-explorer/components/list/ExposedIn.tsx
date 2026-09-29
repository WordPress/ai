/**
 * The "Exposed in" cell: where an ability is offered, and why the assistant
 * does not hold it when it does not (R5).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getSettings } from '../../settings';
import type { AbilityListItem } from '../../types';

/**
 * The assistant state an ability is in, and the value the "Exposed in"
 * filter matches on.
 *
 * Three states, not two. An ability held back only by the temporary admission
 * gate has done everything asked of it, and showing it as "not the assistant"
 * next to abilities that declared nothing would tell its author to go and fix
 * something that is already correct.
 */
export type AssistantState = 'on' | 'pending' | 'off';

/**
 * Returns an ability's assistant state.
 *
 * @param item The ability.
 * @return The state.
 */
export function getAssistantState( item: AbilityListItem ): AssistantState {
	if ( item.conversational_surface ) {
		return 'on';
	}

	if ( 'awaiting_enable' === item.surface_reason ) {
		return 'pending';
	}

	return 'off';
}

/**
 * The labels for each assistant state, as the badge and the filter show them.
 *
 * @return The labels keyed by state.
 */
export function getAssistantStateLabels(): Record< AssistantState, string > {
	return {
		on: __( 'Assistant', 'ai' ),
		pending: __( 'Assistant (eligible)', 'ai' ),
		off: __( 'Not the assistant', 'ai' ),
	};
}

const STATE_ICONS: Record< AssistantState, string > = {
	on: 'dashicons-yes-alt',
	pending: 'dashicons-clock',
	off: 'dashicons-minus',
};

/**
 * Returns the reason line for an ability the assistant does not hold.
 *
 * The commonest reason, `not_public`, is left unsaid: the badge already reads
 * "not the assistant", and repeating it under every such row buries the rows
 * where the reason is the interesting part.
 *
 * @param item The ability.
 * @return The reason, or null when none is shown.
 */
function getReasonLabel( item: AbilityListItem ): string | null {
	const reason = item.surface_reason;

	if ( item.conversational_surface || null === reason ) {
		return null;
	}

	if ( 'not_public' === reason ) {
		return null;
	}

	return (
		item.surface_reason_label ??
		getSettings().surfaceReasonLabels[ reason ] ??
		null
	);
}

/**
 * Renders the "Exposed in" cell.
 *
 * @param props      Component props.
 * @param props.item The ability.
 * @return The cell.
 */
export default function ExposedIn( { item }: { item: AbilityListItem } ) {
	const state = getAssistantState( item );
	const label = getAssistantStateLabels()[ state ];
	const reason = getReasonLabel( item );

	return (
		<div className="ai-abilities-explorer__exposed-in">
			<span className="ability-surface-badges">
				{ item.show_in_rest && (
					<span className="ability-surface-badge">
						{ __( 'REST', 'ai' ) }
					</span>
				) }
				{ item.show_in_mcp && (
					<span className="ability-surface-badge">
						{ __( 'MCP', 'ai' ) }
					</span>
				) }
				<span
					className={ `ability-surface-badge ability-surface ability-surface-${ state }` }
				>
					{ /*
					 * The icon is decorative and the word carries the meaning:
					 * an icon alone would leave a screen reader with nothing.
					 */ }
					<span
						className={ `dashicons ${ STATE_ICONS[ state ] }` }
						aria-hidden="true"
					/>
					{ label }
				</span>
			</span>
			{ 'on' === state && (
				/*
				 * Collapsed, because a full tool description per row buries the
				 * table. Not a tooltip: this is the exact string handed to the
				 * model, passed through unchanged and never trimmed (#588), and
				 * judging it means reading and selecting long-form prose.
				 */
				<details className="ability-surface-details">
					<summary>{ __( 'Text the model sees', 'ai' ) }</summary>
					<p className="description ability-surface-description">
						{ item.description ?? '' }
					</p>
				</details>
			) }
			{ null !== reason && (
				<p className="description ability-surface-reason">{ reason }</p>
			) }
		</div>
	);
}
