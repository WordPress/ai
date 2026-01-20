/**
 * Inline button component for the excerpt link area.
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
 * Inline button component for generating excerpts next to the excerpt link.
 *
 * @return {JSX.Element | null} The inline button component.
 */
export default function ExcerptInlineButton(): JSX.Element | null {
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
			variant="link"
			size="small"
			onClick={ handleGenerate }
			disabled={ isGenerating }
			isBusy={ isGenerating }
			className="ai-excerpt-inline-button"
			label={ buttonLabel }
			showTooltip
		/>
	);
}
