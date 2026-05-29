/**
 * Summarization plugin component.
 */

/**
 * WordPress dependencies
 */
import { Button, Flex, FlexItem } from '@wordpress/components';
import { PluginPostStatusInfo } from '@wordpress/editor';
import { __, sprintf } from '@wordpress/i18n';
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
	const {
		isSummarizing,
		hasSummary,
		handleSummarize,
		isContentTooShort,
		minContentLength,
	} = useSummaryGeneration();

	let buttonLabel: string = __( 'Generate Summary', 'ai' );

	if ( isSummarizing ) {
		buttonLabel = __( 'Generating…', 'ai' );
	} else if ( hasSummary ) {
		buttonLabel = __( 'Regenerate Summary', 'ai' );
	}

	const isDisabled = isSummarizing || isContentTooShort;

	let buttonDescription: string;

	if ( isContentTooShort ) {
		buttonDescription = sprintf(
			/* translators: %d: minimum number of characters required */
			__(
				'Summarization will be available when the post content has at least %d characters.',
				'ai'
			),
			minContentLength
		);
	} else if ( hasSummary ) {
		buttonDescription = __(
			'This will update the generated summary block with a new summary of the content of this post.',
			'ai'
		);
	} else {
		buttonDescription = __(
			'This will create a block that is a summary of the content of this post.',
			'ai'
		);
	}

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
						accessibleWhenDisabled
						className="ai-summarization-plugin-button"
						variant="secondary"
						label={ buttonLabel }
						icon={ update }
						onClick={ handleSummarize }
						disabled={ isDisabled }
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
