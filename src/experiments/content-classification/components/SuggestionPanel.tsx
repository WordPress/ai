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
import { useInstanceId } from '@wordpress/compose';
import { useRef } from '@wordpress/element';

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
		minContentLength,
	} = useContentClassification( taxonomy );

	const taxonomyObject = useSelect(
		( selectFn ) => selectFn( coreStore ).getTaxonomy( taxonomy ),
		[ taxonomy ]
	);

	const classificationHintId = useInstanceId(
		SuggestionPanel,
		'suggestion-panel-classification-hint'
	);

	const classificationSuggestionsId = useInstanceId(
		SuggestionPanel,
		'suggestion-panel-classification-suggestions'
	);

	const shouldFocusFirstAcceptButton = useRef( false );
	const shouldFocusGenerateButton = useRef( false );

	const taxonomyLabel: string = taxonomyObject?.name ?? taxonomy;

	const hasSuggestions = suggestions.length > 0;

	/**
	 * Focuses the generate button when focus has been requested.
	 *
	 * @param node The mounted generate button element.
	 */
	const focusGenerateButtonOnMount = ( node: HTMLButtonElement | null ) => {
		if ( shouldFocusGenerateButton.current && node ) {
			node.focus();
			shouldFocusGenerateButton.current = false;
		}
	};

	/**
	 * Focuses the first suggestion's accept button when requested.
	 *
	 * @param node The mounted accept button element.
	 */
	const focusFirstAcceptButtonOnMount = (
		node: HTMLButtonElement | null
	) => {
		if ( shouldFocusFirstAcceptButton.current && node ) {
			node.focus();
			shouldFocusFirstAcceptButton.current = false;
		}
	};

	/**
	 * Generates suggestions and schedules the related focus transitions.
	 *
	 * @param focusGenerateButton Whether to focus the generate button first.
	 */
	const generateAndMoveFocus = ( focusGenerateButton: boolean ) => {
		shouldFocusGenerateButton.current = focusGenerateButton;
		shouldFocusFirstAcceptButton.current = true;
		handleGenerate();
	};

	/**
	 * Dismisses all suggestions and returns focus to the generate button.
	 */
	const dismissAllAndMoveFocus = () => {
		shouldFocusFirstAcceptButton.current = false;
		shouldFocusGenerateButton.current = true;
		handleDismissAll();
	};

	/**
	 * Schedules focus after removing a suggestion.
	 *
	 * @param mayRestoreSuggestion Whether the suggestion may be restored.
	 */
	const prepareFocusAfterRemoval = ( mayRestoreSuggestion: boolean ) => {
		shouldFocusFirstAcceptButton.current =
			suggestions.length > 1 || mayRestoreSuggestion;
		shouldFocusGenerateButton.current = suggestions.length === 1;
	};

	return (
		<div className="ai-content-classification">
			{ ! hasSuggestions && (
				<Button
					accessibleWhenDisabled
					icon={ update }
					variant="secondary"
					onClick={ () => generateAndMoveFocus( false ) }
					disabled={ isGenerating || ! hasEnoughContent }
					isBusy={ isGenerating }
					ref={ focusGenerateButtonOnMount }
					className="ai-content-classification__generate-button"
					__next40pxDefaultSize
					aria-describedby={
						! hasEnoughContent ? classificationHintId : undefined
					}
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
				<p
					id={ classificationHintId }
					className="ai-content-classification__hint components-base-control__help"
					style={ { color: '#757575' } }
				>
					{ sprintf(
						/* translators: %d: Minimum content length in characters. */
						__(
							'Content Classification will be available when the post content has at least %d characters.',
							'ai'
						),
						minContentLength
					) }
				</p>
			) }

			{ hasSuggestions && (
				<div className="ai-content-classification__suggestions">
					<h3
						id={ classificationSuggestionsId }
						className="ai-content-classification__suggestions-title"
					>
						{ sprintf(
							/* translators: %s: Taxonomy label (e.g., "Tags" or "Categories"). */
							__( 'Suggested %s', 'ai' ),
							taxonomyLabel
						) }
					</h3>
					<div
						className="ai-content-classification__pills"
						aria-labelledby={ classificationSuggestionsId }
						role="list"
					>
						{ suggestions.map( ( suggestion: TagSuggestion ) => (
							<span
								key={ suggestion.term }
								className={ `ai-content-classification__pill${
									suggestion.is_new
										? ' ai-content-classification__pill--new'
										: ''
								}` }
								role="listitem"
							>
								<Button
									className="ai-content-classification__pill-accept"
									onClick={ () => {
										prepareFocusAfterRemoval( true );
										handleAccept( suggestion );
									} }
									ref={
										suggestion === suggestions[ 0 ]
											? focusFirstAcceptButtonOnMount
											: undefined
									}
									label={ sprintf(
										/* translators: %s: Term name. */
										__( 'Add "%s"', 'ai' ),
										suggestion.term
									) }
									size="small"
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
									onClick={ () => {
										prepareFocusAfterRemoval( false );
										handleDismiss( suggestion );
									} }
									label={ sprintf(
										/* translators: %s: Term name. */
										__( 'Dismiss "%s"', 'ai' ),
										suggestion.term
									) }
									size="small"
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
								variant="link"
								onClick={ () => generateAndMoveFocus( true ) }
							>
								{ __( 'Suggest again', 'ai' ) }
							</Button>
						</FlexItem>
						<FlexItem>
							<Button
								variant="link"
								onClick={ dismissAllAndMoveFocus }
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
