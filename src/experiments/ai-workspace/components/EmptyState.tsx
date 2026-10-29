/**
 * The workspace empty state: what this screen is for, and four ways into it.
 */

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Renders the starting state of a conversation (R2).
 *
 * The four suggestions are the workspace's capability classes phrased the way a
 * person would type them: a retrieval question, an audit, a multi-post plan and
 * a code request. Choosing one fills the composer rather than sending it — the
 * prompt is a starting point to edit, not a command to run, which matters most
 * for the two that go on to propose writes.
 *
 * @param props          Component props.
 * @param props.onSelect Called with the chosen prompt text.
 * @return The rendered empty state.
 */
export default function EmptyState( {
	onSelect,
}: {
	onSelect: ( prompt: string ) => void;
} ) {
	const suggestions = [
		{
			label: __( 'Find a gap', 'ai' ),
			prompt: __(
				'Look at my last 10 published posts. What three related topics have I not covered that would fit my tone?',
				'ai'
			),
		},
		{
			label: __( 'Audit content', 'ai' ),
			prompt: __(
				'Find all posts tagged Updates published in 2023 that have no featured image.',
				'ai'
			),
		},
		{
			label: __( 'Plan a series', 'ai' ),
			prompt: __(
				'Generate titles and short excerpts for a 5-part series on remote work.',
				'ai'
			),
		},
		{
			label: __( 'Write a template', 'ai' ),
			prompt: __(
				'Write the HTML for a custom 404 page that lists my most popular categories.',
				'ai'
			),
		},
	];

	return (
		<div className="ai-workspace__empty">
			<h2 className="ai-workspace__empty-title">
				{ __( 'What are we working on?', 'ai' ) }
			</h2>

			<p className="ai-workspace__empty-intro">
				{ __(
					'Ask in plain language. In Site Context the assistant can look up content you are allowed to read; in General Knowledge it answers without touching your site.',
					'ai'
				) }
			</p>

			<ul className="ai-workspace__suggestions">
				{ suggestions.map( ( suggestion ) => (
					<li key={ suggestion.label }>
						<Button
							__next40pxDefaultSize
							className="ai-workspace__suggestion"
							onClick={ () => onSelect( suggestion.prompt ) }
						>
							<span className="ai-workspace__suggestion-label">
								{ suggestion.label }
							</span>
							<span className="ai-workspace__suggestion-prompt">
								{ suggestion.prompt }
							</span>
						</Button>
					</li>
				) ) }
			</ul>
		</div>
	);
}
