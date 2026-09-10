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

export default function SuggestionList( {
	suggestions,
	onAccept,
	onDismiss,
}: Props ) {
	if ( suggestions.length === 0 ) {
		return null;
	}

	return (
		<ul className="ai-internal-links__suggestions">
			{ suggestions.map( ( suggestion ) => (
				<li
					key={ suggestion.anchor_text }
					className="ai-internal-links__suggestion"
				>
					<p className="ai-internal-links__suggestion-anchor">
						<strong>{ `"${ suggestion.anchor_text }"` }</strong>
					</p>
					<p className="ai-internal-links__suggestion-target">
						{ __( 'Links to:', 'ai' ) }{ ' ' }
						<ExternalLink href={ suggestion.url }>
							{ suggestion.title }
						</ExternalLink>
					</p>
					{ suggestion.context && (
						<p className="ai-internal-links__suggestion-context">
							{ `"…${ suggestion.context }…"` }
						</p>
					) }
					<div className="ai-internal-links__suggestion-actions">
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
