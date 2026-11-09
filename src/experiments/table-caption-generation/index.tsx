/**
 * WordPress dependencies
 */
import {
	InspectorControls,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import {
	Button,
	Flex,
	FlexItem,
	Notice,
	PanelBody,
	TextareaControl,
} from '@wordpress/components';
import { createHigherOrderComponent } from '@wordpress/compose';
import { useDispatch, useSelect } from '@wordpress/data';
import { useRef, useState } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';
import { speak } from '@wordpress/a11y';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { runAbility } from '../../utils/run-ability';
import { getErrorMessage } from '../../utils/errors';
import { getRichTextHTML } from '../../utils/blocks';
import { getSnapshot, getTableContent, type TableAttributes } from './utils';

interface BlockEditProps {
	clientId: string;
	name: string;
	isSelected: boolean;
	attributes: TableAttributes;
}

declare global {
	interface Window {
		aiTableCaptionGenerationData?: { enabled: boolean };
	}
}

/**
 * Renders generation and review controls for one table.
 *
 * @param props            Component properties.
 * @param props.clientId   Table client ID.
 * @param props.attributes Current table attributes.
 */
function CaptionControls( {
	clientId,
	attributes,
}: Pick< BlockEditProps, 'clientId' | 'attributes' > ) {
	const [ isLoading, setIsLoading ] = useState( false );
	const [ suggestion, setSuggestion ] = useState< string | null >( null );
	const [ error, setError ] = useState( '' );
	const request = useRef< ReturnType< typeof getSnapshot > >( null );
	const busy = useRef( false );
	const generateButton = useRef< HTMLButtonElement >( null );
	const { updateBlockAttributes } = useDispatch( blockEditorStore );
	const isEditable = useSelect(
		( selectFn ) => getSnapshot( clientId, selectFn ) !== null,
		[ clientId ]
	);
	const stale =
		!! request.current &&
		( request.current.table !== getTableContent( attributes ) ||
			request.current.caption !== getRichTextHTML( attributes.caption ) );

	const generate = async () => {
		if ( busy.current ) {
			return;
		}
		const snapshot = getSnapshot( clientId );
		if ( ! snapshot?.postId ) {
			setError(
				__(
					'Save the post as a draft before generating a caption.',
					'ai'
				)
			);
			return;
		}
		if ( Array.from( snapshot.table ).length > 20000 ) {
			setError(
				__(
					'This table is too large for the caption POC. Use a smaller table.',
					'ai'
				)
			);
			return;
		}
		busy.current = true;
		setIsLoading( true );
		setError( '' );
		setSuggestion( null );
		request.current = snapshot;
		try {
			const result = await runAbility< string >(
				'ai/table-caption-generation',
				{
					post_id: snapshot.postId,
					table: snapshot.table,
					title: snapshot.title,
					context: snapshot.context,
				}
			);
			if ( typeof result !== 'string' || ! result.trim() ) {
				throw new Error(
					__( 'No caption was generated. Please try again.', 'ai' )
				);
			}
			setSuggestion( result.trim() );
			speak( __( 'Caption suggestion ready for review.', 'ai' ) );
		} catch ( caught ) {
			setError( getErrorMessage( caught ) );
		} finally {
			busy.current = false;
			setIsLoading( false );
		}
	};

	const apply = () => {
		const latest = getSnapshot( clientId );
		if (
			! suggestion?.trim() ||
			! latest ||
			! request.current ||
			latest.postId !== request.current.postId ||
			latest.table !== request.current.table ||
			latest.caption !== request.current.caption
		) {
			setError(
				__(
					'The table or caption changed. Generate a new suggestion before applying it.',
					'ai'
				)
			);
			return;
		}
		// Caption is RichText HTML: encode plain text instead of inserting markup.
		const caption = suggestion
			.trim()
			.replaceAll( '&', '&amp;' )
			.replaceAll( '<', '&lt;' )
			.replaceAll( '>', '&gt;' );
		updateBlockAttributes( clientId, { caption } );
		setSuggestion( null );
		generateButton.current?.focus();
		speak(
			__( 'Caption applied. Save the post to keep your changes.', 'ai' )
		);
	};

	if ( ! isEditable ) {
		return null;
	}

	return (
		<InspectorControls>
			<PanelBody title={ __( 'AI table caption', 'ai' ) } initialOpen>
				<Flex direction="column" gap={ 3 }>
					<FlexItem>
						<p>
							{ __(
								'Suggest a short caption from the table and nearby post text.',
								'ai'
							) }
						</p>
						<Button
							__next40pxDefaultSize
							ref={ generateButton }
							type="button"
							variant="secondary"
							isBusy={ isLoading }
							disabled={ isLoading }
							onClick={ generate }
						>
							{ isLoading
								? __( 'Generating caption…', 'ai' )
								: __( 'Generate caption', 'ai' ) }
						</Button>
					</FlexItem>
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
					{ suggestion !== null && (
						<>
							<TextareaControl
								__nextHasNoMarginBottom
								label={ __( 'Suggested caption', 'ai' ) }
								value={ suggestion }
								onChange={ setSuggestion }
								help={ __(
									'Review before applying. This replaces the current table caption.',
									'ai'
								) }
							/>
							{ stale && (
								<Notice
									status="warning"
									isDismissible={ false }
								>
									{ __(
										'The table or caption changed. Generate a new suggestion.',
										'ai'
									) }
								</Notice>
							) }
							<Flex wrap>
								<Button
									__next40pxDefaultSize
									type="button"
									variant="primary"
									disabled={
										isLoading ||
										stale ||
										! suggestion.trim()
									}
									onClick={ apply }
								>
									{ __( 'Apply caption', 'ai' ) }
								</Button>
								<Button
									__next40pxDefaultSize
									type="button"
									variant="tertiary"
									onClick={ () => {
										setSuggestion( null );
										generateButton.current?.focus();
									} }
								>
									{ __( 'Discard', 'ai' ) }
								</Button>
							</Flex>
						</>
					) }
				</Flex>
			</PanelBody>
		</InspectorControls>
	);
}

const withTableCaption = createHigherOrderComponent( ( BlockEdit ) => {
	return ( props: BlockEditProps ) => (
		<>
			<BlockEdit { ...props } />
			{ props.name === 'core/table' &&
				props.isSelected &&
				window.aiTableCaptionGenerationData?.enabled && (
					<CaptionControls
						key={ props.clientId }
						clientId={ props.clientId }
						attributes={ props.attributes }
					/>
				) }
		</>
	);
}, 'withTableCaption' );

addFilter(
	'editor.BlockEdit',
	'ai/table-caption-generation',
	withTableCaption
);
