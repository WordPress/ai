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
 * a template. Each asks only for what the tool list can deliver, so each ends in
 * something to act on: choices, a filtered list, drafts to approve or a template
 * to approve. Choosing one fills the composer rather than sending it — the
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
				'Look at my 10 most recent posts and the topics I write about most. Suggest three related topics I have not covered yet.',
				'ai'
			),
		},
		{
			label: __( 'Audit content', 'ai' ),
			prompt: __(
				'List the posts tagged Updates from 2023 that have no featured image.',
				'ai'
			),
		},
		{
			label: __( 'Plan a series', 'ai' ),
			prompt: __(
				'Plan a 5-part series on remote work, with a title and short excerpt for each, as drafts I can review.',
				'ai'
			),
		},
		{
			label: __( 'Write a template', 'ai' ),
			prompt: __(
				'Create a 404 page template that helps visitors find my most popular categories.',
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
