/**
 * Suggestion panel component for displaying AI-generated taxonomy suggestions.
 */

/**
 * WordPress dependencies
 */
import { Button, Flex, FlexItem } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { __, isRTL, sprintf } from '@wordpress/i18n';
import { close as closeIcon, update } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { useContentClassification } from './useContentClassification';
import type { TagSuggestion } from '../types';

interface SuggestionPanelProps {
	taxonomy: string;
}

/**
 * SuggestionPanel component.
 *
 * Displays a button to generate suggestions and renders suggestion pills
 * that can be accepted or dismissed.
 *
 * @param props          Component props.
 * @param props.taxonomy The taxonomy to generate suggestions for.
 * @return The suggestion panel component.
 */
export default function SuggestionPanel( {
	taxonomy,
}: SuggestionPanelProps ): React.JSX.Element | null {
	const {
		isGenerating,
		suggestions,
		hasEnoughContent,
		handleGenerate,
		handleAccept,
		handleDismiss,
		handleDismissAll,
	} = useContentClassification( taxonomy );

	const taxonomyObject: any = useSelect(
		( selectFn ) => selectFn( coreStore ).getTaxonomy( taxonomy ),
		[ taxonomy ]
	);
	const taxonomyLabel: string = taxonomyObject?.name ?? taxonomy;

	const hasSuggestions = suggestions.length > 0;

	return (
		<div className="ai-content-classification">
			{ ! hasSuggestions && (
				<Button
					accessibleWhenDisabled
					icon={ update }
					variant="secondary"
					onClick={ handleGenerate }
					disabled={ isGenerating || ! hasEnoughContent }
					isBusy={ isGenerating }
					className="ai-content-classification__generate-button"
					__next40pxDefaultSize
				>
					{ isGenerating
						? __( 'Generating…', 'ai' )
						: sprintf(
								/* translators: %s: Taxonomy label (e.g., "Tags" or "Categories"). */
								__( 'Suggest %s', 'ai' ),
								taxonomyLabel
						  ) }
				</Button>
			) }

			{ ! hasEnoughContent && ! hasSuggestions && (
				<p className="ai-content-classification__hint components-base-control__help">
					{ __(
						'Add more content to enable AI suggestions (approximately 150 words).',
						'ai'
					) }
				</p>
			) }

			{ hasSuggestions && (
				<div className="ai-content-classification__suggestions">
					<h3 className="ai-content-classification__suggestions-title">
						{ sprintf(
							/* translators: %s: Taxonomy label (e.g., "Tags" or "Categories"). */
							__( 'Suggested %s', 'ai' ),
							taxonomyLabel
						) }
					</h3>
					<div className="ai-content-classification__pills">
						{ suggestions.map( ( suggestion: TagSuggestion ) => (
							<span
								key={ suggestion.term }
								className={ `ai-content-classification__pill${
									suggestion.is_new
										? ' ai-content-classification__pill--new'
										: ''
								}` }
							>
								<Button
									className="ai-content-classification__pill-accept"
									onClick={ () => handleAccept( suggestion ) }
									label={ sprintf(
										/* translators: %s: Term name. */
										__( 'Add "%s"', 'ai' ),
										suggestion.term
									) }
								>
									{ suggestion.parent && (
										<span className="ai-content-classification__pill-parent">
											{ suggestion.parent +
												( isRTL() ? ' ‹ ' : ' › ' ) }
										</span>
									) }
									{ suggestion.term }
									{ suggestion.is_new && (
										<span className="ai-content-classification__pill-badge">
											{ __( 'new', 'ai' ) }
										</span>
									) }
								</Button>
								<Button
									className="ai-content-classification__pill-dismiss"
									icon={ closeIcon }
									iconSize={ 16 }
									onClick={ () =>
										handleDismiss( suggestion )
									}
									label={ sprintf(
										/* translators: %s: Term name. */
										__( 'Dismiss "%s"', 'ai' ),
										suggestion.term
									) }
								/>
							</span>
						) ) }
					</div>
					<Flex
						gap={ 3 }
						className="ai-content-classification__actions"
					>
						<FlexItem>
							<Button
								accessibleWhenDisabled
								variant="link"
								onClick={ handleGenerate }
								disabled={ isGenerating }
							>
								{ __( 'Suggest again', 'ai' ) }
							</Button>
						</FlexItem>
						<FlexItem>
							<Button
								variant="link"
								onClick={ handleDismissAll }
								isDestructive
							>
								{ __( 'Dismiss all', 'ai' ) }
							</Button>
						</FlexItem>
					</Flex>
				</div>
			) }
		</div>
	);
}
