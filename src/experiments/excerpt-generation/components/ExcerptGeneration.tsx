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

	// Ensure the experiment is enabled.
	if ( ! aiExcerptGenerationData?.enabled ) {
		return null;
	}

	const buttonLabel = hasExcerpt
		? __( 'Re-generate excerpt', 'ai' )
		: __( 'Generate excerpt', 'ai' );

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
