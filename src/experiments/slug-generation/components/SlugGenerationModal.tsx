/**
 * WordPress dependencies
 */
import {
	Button,
	Flex,
	FlexItem,
	Modal,
	RadioControl,
	TextControl,
	Spinner,
} from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

interface SlugGenerationModalProps {
	suggestions: string[];
	onClose: () => void;
	onSelect: ( slug: string ) => void;
	onRegenerate: () => void;
	isRegenerating: boolean;
}

/**
 * Renders the modal dialog for inspecting, editing, and selecting generated slug suggestions.
 *
 * @param props                Component props.
 * @param props.suggestions    List of suggested slugs.
 * @param props.onClose        Callback when modal is closed.
 * @param props.onSelect       Callback when a slug is selected.
 * @param props.onRegenerate   Callback when regeneration is triggered.
 * @param props.isRegenerating Whether suggestions are currently being generated.
 * @return The modal component.
 */
export default function SlugGenerationModal( {
	suggestions,
	onClose,
	onSelect,
	onRegenerate,
	isRegenerating,
}: SlugGenerationModalProps ): React.JSX.Element {
	const [ selectedSlug, setSelectedSlug ] = useState( '' );

	// Select the first suggestion whenever a new list of suggestions is received.
	useEffect( () => {
		if ( suggestions.length > 0 ) {
			setSelectedSlug( suggestions[ 0 ] ?? '' );
		}
	}, [ suggestions ] );

	return (
		<Modal
			title={ __( 'Slug suggestions', 'ai' ) }
			onRequestClose={ onClose }
			isFullScreen={ false }
			size="medium"
			className="ai-slug-generation-modal"
		>
			<p className="ai-slug-generation-subtitle">
				{ __(
					'Review, edit, and insert a suggested slug or regenerate new options.',
					'ai'
				) }
			</p>

			<div className="ai-slug-generation-suggestions-list">
				{ isRegenerating && suggestions.length === 0 ? (
					<Flex
						align="center"
						justify="center"
						className="ai-slug-generation-loading"
					>
						<Spinner />
						<span>{ __( 'Generating suggestions…', 'ai' ) }</span>
					</Flex>
				) : (
					<RadioControl
						label={ __( 'Suggested Slugs', 'ai' ) }
						selected={ selectedSlug }
						options={ suggestions.map( ( slug ) => ( {
							label: slug,
							value: slug,
						} ) ) }
						onChange={ setSelectedSlug }
					/>
				) }
			</div>

			<TextControl
				label={ __( 'Selected slug', 'ai' ) }
				value={ selectedSlug }
				onChange={ setSelectedSlug }
				disabled={ isRegenerating }
				__nextHasNoMarginBottom
			/>

			<Flex
				justify="flex-end"
				gap="3"
				className="ai-slug-generation-actions"
			>
				<FlexItem>
					<Button
						variant="secondary"
						onClick={ onRegenerate }
						disabled={ isRegenerating }
						isBusy={ isRegenerating }
						accessibleWhenDisabled
						__next40pxDefaultSize
					>
						{ isRegenerating
							? __( 'Generating…', 'ai' )
							: __( 'Regenerate', 'ai' ) }
					</Button>
				</FlexItem>
				<FlexItem>
					<Button
						variant="primary"
						onClick={ () => onSelect( selectedSlug ) }
						disabled={ isRegenerating || ! selectedSlug }
						__next40pxDefaultSize
					>
						{ __( 'Insert', 'ai' ) }
					</Button>
				</FlexItem>
			</Flex>
		</Modal>
	);
}
