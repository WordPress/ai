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
	currentSlug?: string;
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

	// Select the first suggestion whenever a new list of suggestions is received
	useEffect( () => {
		if ( suggestions.length > 0 ) {
			setSelectedSlug( suggestions[ 0 ] ?? '' );
		}
	}, [ suggestions ] );

	const handleInsert = () => {
		onSelect( selectedSlug );
	};

	return (
		<Modal
			title={ __( 'Slug suggestions', 'ai' ) }
			onRequestClose={ onClose }
			isFullScreen={ false }
			size="medium"
			className="ai-slug-generation-modal"
		>
			<p
				className="ai-slug-generation-subtitle"
				style={ { marginBottom: '20px' } }
			>
				{ __(
					'Review, edit, and insert a suggested slug or regenerate new options.',
					'ai'
				) }
			</p>

			<div
				className="ai-slug-generation-suggestions-list"
				style={ { marginBottom: '20px' } }
			>
				{ isRegenerating && suggestions.length === 0 ? (
					<Flex
						align="center"
						justify="center"
						style={ { padding: '24px 0' } }
					>
						<Spinner />
						<span style={ { marginLeft: '8px', color: '#757575' } }>
							{ __( 'Generating suggestions…', 'ai' ) }
						</span>
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
			/>

			<Flex justify="flex-end" gap="3" style={ { marginTop: '24px' } }>
				<FlexItem>
					<Button
						variant="secondary"
						onClick={ onRegenerate }
						disabled={ isRegenerating }
						isBusy={ isRegenerating }
						accessibleWhenDisabled
						__next40pxDefaultSize
					>
						{ __( 'Regenerate', 'ai' ) }
					</Button>
				</FlexItem>
				<FlexItem>
					<Button
						variant="primary"
						onClick={ handleInsert }
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
