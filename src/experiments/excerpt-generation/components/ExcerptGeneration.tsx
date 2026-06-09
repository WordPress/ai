/**
 * Excerpt generator component for the excerpt panel.
 */

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { update } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useExcerptGeneration } from './useExcerptGeneration';

const { aiExcerptGenerationData } = window as any;

/**
 * ExcerptGeneration component.
 *
 * Provides a button to generate an excerpt.
 *
 * @return {React.JSX.Element | null} The excerpt generation component.
 */
export default function ExcerptGeneration(): React.JSX.Element | null {
	const {
		isGenerating,
		hasExcerpt,
		isContentTooShort,
		tooShortLabel,
		handleGenerate,
	} = useExcerptGeneration();

	// Don't render if disabled.
	if ( ! aiExcerptGenerationData?.enabled ) {
		return null;
	}

	let buttonLabel: string = __( 'Generate excerpt', 'ai' );

	if ( isGenerating ) {
		buttonLabel = __( 'Generating…', 'ai' );
	} else if ( hasExcerpt ) {
		buttonLabel = __( 'Regenerate excerpt', 'ai' );
	}

	return (
		<Button
			icon={ update }
			variant="secondary"
			label={ isContentTooShort ? tooShortLabel : buttonLabel }
			showTooltip
			onClick={ handleGenerate }
			disabled={ isGenerating || isContentTooShort }
			accessibleWhenDisabled
			isBusy={ isGenerating }
		>
			{ buttonLabel }
		</Button>
	);
}
