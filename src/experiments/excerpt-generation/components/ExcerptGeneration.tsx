/**
 * Excerpt generator component for the excerpt panel.
 */

/**
 * WordPress dependencies
 */
import { Button, Notice } from '@wordpress/components';
import { update } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { useExcerptGeneration } from './useExcerptGeneration';

const { aiExcerptGenerationData } = window as any;

/**
 * Props of the ExcerptGeneration component.
 */
export interface ExcerptGenerationProps {
	/**
	 * Applies the generated excerpt. Defaults to editing the post.
	 */
	onApply?: ( ( excerpt: string ) => void ) | undefined;
}

/**
 * ExcerptGeneration component.
 *
 * Provides a button to generate an excerpt.
 *
 * @param props         Component props.
 * @param props.onApply Applies the generated excerpt. Defaults to editing the post.
 * @return The excerpt generation component.
 */
export default function ExcerptGeneration( {
	onApply,
}: ExcerptGenerationProps = {} ): React.JSX.Element | null {
	const {
		isGenerating,
		hasExcerpt,
		isContentTooShort,
		tooShortLabel,
		handleGenerate,
	} = useExcerptGeneration( { onApply } );

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
		<Stack direction="column" gap="md">
			{ isContentTooShort && (
				<Notice status="warning" isDismissible={ false }>
					{ tooShortLabel }
				</Notice>
			) }

			<Button
				icon={ update }
				variant="secondary"
				label={ isContentTooShort ? tooShortLabel : buttonLabel }
				onClick={ handleGenerate }
				disabled={ isGenerating || isContentTooShort }
				accessibleWhenDisabled
				isBusy={ isGenerating }
				__next40pxDefaultSize
				style={ { alignSelf: 'flex-start' } }
			>
				{ buttonLabel }
			</Button>
		</Stack>
	);
}
