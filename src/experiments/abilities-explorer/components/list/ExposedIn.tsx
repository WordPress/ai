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
 * An ability's assistant state; `pending` means only the admission gate holds it back.
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
 * Returns the reason line for an ability the assistant does not hold; `not_public` is too common to show.
 *
 * @param item The ability.
 * @return The reason, or null when none is shown.
 */
function getReasonLabel( item: AbilityListItem ): string | null {
	const reason = item.surface_reason;

	if (
		item.conversational_surface ||
		null === reason ||
		'not_public' === reason
	) {
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
					<span
						className={ `dashicons ${ STATE_ICONS[ state ] }` }
						aria-hidden="true"
					/>
					{ label }
				</span>
			</span>
			{ 'on' === state && (
				// Not a tooltip: the exact model-facing text is long and must be selectable.
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
