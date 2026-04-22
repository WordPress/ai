/**
 * Character count indicator component for meta descriptions.
 */

/**
 * WordPress dependencies
 */
import { sprintf, __ } from '@wordpress/i18n';

const MIN_LENGTH = 140;
const MAX_LENGTH = 160;

interface CharacterCountProps {
	count: number;
}

/**
 * Renders a color-coded character count indicator.
 *
 * Green when within 140–160 range, yellow outside.
 *
 * @param props       Component props.
 * @param props.count The current character count.
 */
export default function CharacterCount( {
	count,
}: CharacterCountProps ): React.JSX.Element {
	const isInRange = count >= MIN_LENGTH && count <= MAX_LENGTH;

	let rangeClass = 'ai-meta-description__char-count';
	rangeClass += isInRange
		? ` ${ rangeClass }--in-range`
		: ` ${ rangeClass }--out-of-range`;

	return (
		<span
			className={ rangeClass }
			aria-label={ sprintf(
				/* translators: %d: character count */
				__( '%d characters', 'ai' ),
				count
			) }
		>
			{ sprintf(
				/* translators: %d: character count */
				__( '%d characters', 'ai' ),
				count
			) }
		</span>
	);
}
