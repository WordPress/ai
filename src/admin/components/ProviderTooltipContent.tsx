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
			<div className="ai-provider-tooltip__body">
				<div className="ai-provider-tooltip__header">
					<strong className="ai-provider-tooltip__name">{ metadata.name }</strong>
					<span className="ai-provider-tooltip__badge">
						{ metadata.type === 'client'
							? __( 'Local', 'ai' )
							: __( 'Cloud', 'ai' ) }
					</span>
				</div>
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
									<span className="ai-provider-tooltip__model-name">{ model.name }</span>
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
			</div>
			{ metadata.url && (
				<div className="ai-provider-tooltip__footer">
					<a
						className="ai-provider-tooltip__link"
						href={ metadata.url }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'Open API key settings', 'ai' ) }
					</a>
				</div>
			) }
		</div>
	);
};

export default ProviderTooltipContent;
