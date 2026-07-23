/**
 * WordPress dependencies
 */
import { Button, Flex, FlexItem, Spinner } from '@wordpress/components';
import { PluginPostStatusInfo } from '@wordpress/editor';
import { useInstanceId } from '@wordpress/compose';
import { __, sprintf } from '@wordpress/i18n';
import { link } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { useInternalLinks } from '../hooks/useInternalLinks';
import SuggestionList from './SuggestionList';

export default function InternalLinksPlugin() {
	const {
		isLoading,
		suggestions,
		isContentTooShort,
		minContentLength,
		fetchSuggestions,
		acceptSuggestion,
		dismissSuggestion,
	} = useInternalLinks();

	const descriptionId = useInstanceId(
		InternalLinksPlugin,
		'internal-links-plugin-description'
	);

	if ( ! ( window as any ).aiInternalLinksData?.enabled ) {
		return null;
	}

	const buttonLabel = isLoading
		? __( 'Suggesting links…', 'ai' )
		: __( 'Suggest Internal Links', 'ai' );

	const buttonDescription = isContentTooShort
		? sprintf(
				/* translators: %d: minimum number of characters required. */
				__(
					'Internal Link Suggestions will be available when the post content has at least %d characters.',
					'ai'
				),
				minContentLength
		  )
		: __(
				'Analyses this post and suggests relevant internal links using existing text as anchor text.',
				'ai'
		  );

	return (
		<PluginPostStatusInfo>
			<Flex direction="column" gap={ 2 }>
				<FlexItem>
					<Button
						accessibleWhenDisabled
						variant="secondary"
						icon={ isLoading ? <Spinner /> : link }
						onClick={ fetchSuggestions }
						isBusy={ isLoading }
						disabled={ isLoading || isContentTooShort }
						className="ai-internal-links__plugin-button"
						__next40pxDefaultSize
						aria-describedby={ descriptionId }
					>
						{ buttonLabel }
					</Button>
				</FlexItem>

				<FlexItem>
					<span
						id={ descriptionId }
						className="description ai-internal-links__plugin-description"
					>
						{ buttonDescription }
					</span>
				</FlexItem>

				{ suggestions.length > 0 && (
					<FlexItem>
						<p className="description ai-internal-links__suggestions-header">
							{ sprintf(
								/* translators: %d: number of suggestions found. */
								__( '%d suggestion(s) found.', 'ai' ),
								suggestions.length
							) }
						</p>
						<SuggestionList
							suggestions={ suggestions }
							onAccept={ acceptSuggestion }
							onDismiss={ dismissSuggestion }
						/>
					</FlexItem>
				) }
			</Flex>
		</PluginPostStatusInfo>
	);
}
