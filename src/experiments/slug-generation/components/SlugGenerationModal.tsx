/**
 * WordPress dependencies
 */
import {
	Button,
	Flex,
	FlexItem,
	Modal,
	TextControl,
	Spinner,
} from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
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

export default function SlugGenerationModal( {
	suggestions,
	onClose,
	onSelect,
	onRegenerate,
	isRegenerating,
}: SlugGenerationModalProps ): React.JSX.Element {
	const [ selectedSlug, setSelectedSlug ] = useState( '' );
	const labelId = useInstanceId(
		SlugGenerationModal,
		'ai-slug-generation-suggestions-label'
	);

	// Pre-select the first suggestion by default
	useEffect( () => {
		if ( suggestions.length > 0 && ! selectedSlug ) {
			setSelectedSlug( suggestions[ 0 ] ?? '' );
		}
	}, [ suggestions, selectedSlug ] );

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
				<span
					id={ labelId }
					className="components-base-control__label"
					style={ {
						display: 'block',
						marginBottom: '8px',
						fontWeight: 600,
					} }
				>
					{ __( 'Suggested Slugs', 'ai' ) }
				</span>
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
					<Flex
						direction="column"
						gap="2"
						align="stretch"
						role="group"
						aria-labelledby={ labelId }
					>
						{ suggestions.map( ( slug, index ) => (
							<Button
								key={ index }
								variant={
									selectedSlug === slug
										? 'primary'
										: 'secondary'
								}
								onClick={ () => setSelectedSlug( slug ) }
								disabled={ isRegenerating }
								style={ {
									justifyContent: 'flex-start',
									textAlign: 'left',
									padding: '12px 16px',
									height: 'auto',
									whiteSpace: 'normal',
									wordBreak: 'break-all',
								} }
								__next40pxDefaultSize
							>
								{ slug }
							</Button>
						) ) }
					</Flex>
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
