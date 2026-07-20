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

/**
 * InternalLinksPlugin component.
 *
 * Renders a "Suggest Internal Links" button in the post status info panel.
 * Once clicked the button calls the AI ability, displays the suggestions
 * inline, and lets the editor accept or dismiss each one individually.
 */
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
				{ /* Primary button */ }
				<FlexItem>
					<Button
						accessibleWhenDisabled
						variant="secondary"
						icon={ isLoading ? <Spinner /> : link }
						onClick={ fetchSuggestions }
						isBusy={ isLoading }
						disabled={ isLoading || isContentTooShort }
						style={ {
							justifyContent: 'center',
							width: '100%',
						} }
						__next40pxDefaultSize
						aria-describedby={ descriptionId }
					>
						{ buttonLabel }
					</Button>
				</FlexItem>

				{ /* Description / status */ }
				<FlexItem>
					<span
						id={ descriptionId }
						className="description"
						style={ { color: '#757575' } }
					>
						{ buttonDescription }
					</span>
				</FlexItem>

				{ /* Suggestion list — shown after a successful fetch */ }
				{ suggestions.length > 0 && (
					<FlexItem>
						<p
							className="description"
							style={ {
								margin: '4px 0 8px',
								fontWeight: 600,
							} }
						>
							{ sprintf(
								/* translators: %d: number of suggestions found. */
								__(
									'%d suggestion(s) found. Review and accept or dismiss each one.',
									'ai'
								),
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
