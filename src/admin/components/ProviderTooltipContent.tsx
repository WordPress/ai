/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ProviderMetadata } from '../types/providers';

interface ProviderTooltipContentProps {
	metadata: ProviderMetadata;
	activeModel?: string | null;
}

const ProviderTooltipContent: React.FC< ProviderTooltipContentProps > = ( {
	metadata,
	activeModel,
} ) => {
	const topModels = metadata.models?.slice( 0, 4 ) ?? [];

	return (
		<div className="ai-provider-tooltip">
			<strong>{ metadata.name }</strong>
			<span className="ai-provider-tooltip__type">
				{ metadata.type === 'client'
					? __( 'Local provider', 'ai' )
					: __( 'Cloud provider', 'ai' ) }
			</span>
			{ activeModel && (
				<span className="ai-provider-tooltip__model">
					{ sprintf(
						/* translators: %s: AI model name. */
						__( 'Requested model: %s', 'ai' ),
						activeModel
					) }
				</span>
			) }
			{ metadata.tooltip && (
				<span className="ai-provider-tooltip__hint">
					{ metadata.tooltip }
				</span>
			) }
			{ metadata.tooltip && (
				<p className="ai-provider-tooltip__hint">{ metadata.tooltip }</p>
			) }
			{ topModels.length > 0 && (
				<div className="ai-provider-tooltip__models">
					<span className="ai-provider-tooltip__section-title">
						{ __( 'Available models', 'ai' ) }
					</span>
					<ul>
						{ topModels.map( ( model ) => (
							<li key={ model.id }>
								<strong>{ model.name }</strong>
								{ model.capabilities?.length > 0 && (
									<span className="ai-provider-tooltip__capabilities">
										{ model.capabilities.join( ', ' ) }
									</span>
								) }
							</li>
						) ) }
					</ul>
				</div>
			) }
			{ metadata.url && (
				<a
					className="ai-provider-tooltip__link"
					href={ metadata.url }
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'Open API key settings', 'ai' ) }
				</a>
			) }
		</div>
	);
};

export default ProviderTooltipContent;
