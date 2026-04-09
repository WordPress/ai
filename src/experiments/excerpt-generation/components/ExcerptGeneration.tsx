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
 * @return {JSX.Element | null} The excerpt generation component.
 */
export default function ExcerptGeneration(): JSX.Element | null {
	const { isGenerating, hasExcerpt, handleGenerate } = useExcerptGeneration();

	// Don't render if disabled.
	if ( ! aiExcerptGenerationData?.enabled ) {
		return null;
	}

	let buttonLabel: string = __( 'Generate excerpt', 'ai' );

	if ( isGenerating ) {
		buttonLabel = __( 'Generating...', 'ai' );
	} else if ( hasExcerpt ) {
		buttonLabel = __( 'Re-generate excerpt', 'ai' );
	}

	return (
		<Button
			icon={ update }
			variant="secondary"
			onClick={ handleGenerate }
			disabled={ isGenerating }
			isBusy={ isGenerating }
		>
			{ buttonLabel }
		</Button>
	);
}
