/**
 * WordPress dependencies
 */
import { InspectorControls } from '@wordpress/block-editor';
import {
	Button,
	ExternalLink,
	Flex,
	FlexItem,
	Notice,
	PanelBody,
	TextareaControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getProviderData } from '../../../utils/provider-status';
import { useTableCaption } from '../hooks/useTableCaption';

/**
 * Renders the selected table's generation, preview, and explicit apply controls.
 *
 * @param props          Component properties.
 * @param props.clientId Table client ID.
 */
export default function CaptionControls( { clientId }: { clientId: string } ) {
	const caption = useTableCaption( clientId );
	if ( ! caption.source ) {
		return null;
	}
	const provider = getProviderData();
	let generateLabel: string = __( 'Generate caption', 'ai' );
	if ( caption.isLoading ) {
		generateLabel = __( 'Generating caption…', 'ai' );
	} else if ( caption.proposal ) {
		generateLabel = __( 'Regenerate caption', 'ai' );
	}

	return (
		<InspectorControls>
			<PanelBody title={ __( 'AI table caption', 'ai' ) } initialOpen>
				<Flex direction="column" gap={ 3 }>
					{ ! provider.hasProvider && (
						<Notice status="warning" isDismissible={ false }>
							<p>
								{ __(
									'Connect an AI provider to generate table captions.',
									'ai'
								) }
							</p>
							{ provider.connectorsUrl && (
								<ExternalLink href={ provider.connectorsUrl }>
									{ __( 'Manage Connectors', 'ai' ) }
								</ExternalLink>
							) }
						</Notice>
					) }
					<FlexItem>
						<Button
							__next40pxDefaultSize
							className="ai-table-caption-generation__generate"
							type="button"
							variant="secondary"
							isBusy={ caption.isLoading }
							disabled={
								caption.isLoading || ! provider.hasProvider
							}
							onClick={ caption.generate }
						>
							{ generateLabel }
						</Button>
					</FlexItem>
					{ caption.error && (
						<Notice status="error" isDismissible={ false }>
							{ caption.error }
						</Notice>
					) }
					{ caption.proposal !== null && (
						<>
							<TextareaControl
								__nextHasNoMarginBottom
								label={ __( 'Suggested caption', 'ai' ) }
								value={ caption.proposal.text }
								onChange={ caption.edit }
								disabled={ caption.isLoading }
								rows={ 3 }
								help={
									caption.source.caption.trim()
										? __(
												'Review before applying. This replaces the current table caption.',
												'ai'
										  )
										: __(
												'Review before applying. The table cells and formatting will stay unchanged.',
												'ai'
										  )
								}
							/>
							{ caption.isStale && (
								<Notice
									status="warning"
									isDismissible={ false }
								>
									{ __(
										'The table, caption, or surrounding context changed. Generate a new suggestion.',
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
										caption.isLoading ||
										caption.isStale ||
										! caption.proposal.text.trim()
									}
									onClick={ caption.apply }
								>
									{ __( 'Apply caption', 'ai' ) }
								</Button>
								<Button
									__next40pxDefaultSize
									type="button"
									variant="tertiary"
									disabled={ caption.isLoading }
									onClick={ caption.discard }
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
