/**
 * Individual suggestion card component for meta description suggestions.
 */

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import CharacterCount from './CharacterCount';

interface SuggestionCardProps {
	text: string;
	characterCount: number;
	isSelected: boolean;
	onSelect: () => void;
}

/**
 * Renders a selectable meta description suggestion card.
 *
 * @param props                Component props.
 * @param props.text           The suggestion text.
 * @param props.characterCount The character count.
 * @param props.isSelected     Whether this card is currently selected.
 * @param props.onSelect       Callback when the card is selected.
 */
export default function SuggestionCard( {
	text,
	characterCount,
	isSelected,
	onSelect,
}: SuggestionCardProps ): JSX.Element {
	let suggestionCardClass = 'ai-meta-description__suggestion-card';
	if ( isSelected ) {
		suggestionCardClass += ` ${ suggestionCardClass }--selected`;
	}

	return (
		<Button
			className={ suggestionCardClass }
			onClick={ onSelect }
			aria-pressed={ isSelected }
			label={ __( 'Select this suggestion', 'ai' ) }
		>
			<span className="ai-meta-description__suggestion-text">
				{ text }
			</span>
			<CharacterCount count={ characterCount } />
		</Button>
	);
}
