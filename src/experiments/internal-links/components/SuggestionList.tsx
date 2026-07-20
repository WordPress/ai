/**
 * WordPress dependencies
 */
import { Button, ExternalLink } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { check, trash } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import type { LinkSuggestion } from '../hooks/useInternalLinks';

interface Props {
	suggestions: LinkSuggestion[];
	onAccept: ( suggestion: LinkSuggestion ) => void;
	onDismiss: ( suggestion: LinkSuggestion ) => void;
}

/**
 * SuggestionList component.
 *
 * Each suggestion shows:
 * - The anchor text (the exact phrase that will become the link).
 * - The target page title and URL (as a preview link).
 * - The surrounding context sentence.
 * - Accept (applies the link) and Dismiss (removes suggestion) buttons.
 *
 * @param props Component props.
 */
export default function SuggestionList( {
	suggestions,
	onAccept,
	onDismiss,
}: Props ) {
	if ( suggestions.length === 0 ) {
		return null;
	}

	return (
		<ul
			className="ai-internal-links__suggestions"
			style={ { listStyle: 'none', margin: 0, padding: 0 } }
		>
			{ suggestions.map( ( suggestion ) => (
				<li
					key={ suggestion.anchor_text }
					className="ai-internal-links__suggestion"
					style={ {
						borderBottom: '1px solid #ddd',
						paddingBottom: '10px',
						marginBottom: '10px',
					} }
				>
					{ /* Anchor text */ }
					<p style={ { margin: '0 0 4px' } }>
						<strong>{ `"${ suggestion.anchor_text }"` }</strong>
					</p>

					{ /* Target page */ }
					<p
						style={ {
							margin: '0 0 4px',
							fontSize: '12px',
							color: '#555',
						} }
					>
						{ __( 'Links to:', 'ai' ) }{ ' ' }
						<ExternalLink href={ suggestion.url }>
							{ suggestion.title }
						</ExternalLink>
					</p>

					{ /* Context */ }
					{ suggestion.context && (
						<p
							style={ {
								margin: '0 0 8px',
								fontSize: '11px',
								color: '#757575',
								fontStyle: 'italic',
							} }
						>
							{ `"…${ suggestion.context }…"` }
						</p>
					) }

					{ /* Actions */ }
					<div style={ { display: 'flex', gap: '6px' } }>
						<Button
							variant="secondary"
							icon={ check }
							iconSize={ 16 }
							size="small"
							onClick={ () => onAccept( suggestion ) }
							__next40pxDefaultSize={ false }
						>
							{ __( 'Accept', 'ai' ) }
						</Button>
						<Button
							variant="tertiary"
							icon={ trash }
							iconSize={ 16 }
							size="small"
							onClick={ () => onDismiss( suggestion ) }
							__next40pxDefaultSize={ false }
						>
							{ __( 'Dismiss', 'ai' ) }
						</Button>
					</div>
				</li>
			) ) }
		</ul>
	);
}
