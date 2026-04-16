/**
 * Summarization plugin component.
 */

/**
 * WordPress dependencies
 */
import { Button, Flex, FlexItem } from '@wordpress/components';
import { PluginPostStatusInfo } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { update } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { useSummaryGeneration } from '../functions/useSummaryGeneration';

const { aiSummarizationData } = window as any;

/**
 * Summarization plugin component.
 */
export default function SummarizationPlugin() {
	const { isSummarizing, hasSummary, handleSummarize } =
		useSummaryGeneration();

	let buttonLabel: string = __( 'Generate Summary', 'ai' );

	if ( isSummarizing ) {
		buttonLabel = __( 'Generating…', 'ai' );
	} else if ( hasSummary ) {
		buttonLabel = __( 'Re-generate Summary', 'ai' );
	}
	const buttonDescription = hasSummary
		? __(
				'This will update the AI generated summary block with a new summary of the content of this post.',
				'ai'
		  )
		: __(
				'This will create a block that is a summary of the content of this post.',
				'ai'
		  );

	// Don't render if disabled.
	if ( ! aiSummarizationData?.enabled ) {
		return null;
	}

	return (
		<PluginPostStatusInfo>
			<Flex
				direction="column"
				className="ai-summarization-plugin-container"
				gap={ 2 }
			>
				<FlexItem>
					<Button
						className="ai-summarization-plugin-button"
						variant="secondary"
						label={ buttonLabel }
						icon={ update }
						onClick={ handleSummarize }
						disabled={ isSummarizing }
						isBusy={ isSummarizing }
						__next40pxDefaultSize
					>
						{ buttonLabel }
					</Button>
				</FlexItem>
				<FlexItem>
					<span className="description">{ buttonDescription }</span>
				</FlexItem>
			</Flex>
		</PluginPostStatusInfo>
	);
}
