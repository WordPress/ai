/**
 * Content classification experiment plugin registration.
 */

/**
 * WordPress dependencies
 */
import { addFilter } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import SuggestionPanel from './components/SuggestionPanel';
import { exposeToDevTools } from '../../utils/devtools';

/**
 * Styles
 */
import './index.scss';

exposeToDevTools( {
	name: 'Content Classification',
	description: 'Suggests tags and categories for the current post using AI.',
	abilitySlug: 'ai/content-classification',
} );

const SUPPORTED_TAXONOMIES = [ 'post_tag', 'category' ];

/**
 * Wraps the taxonomy selector component with the AI suggestion panel.
 *
 * @param OriginalComponent The original taxonomy selector component.
 * @return The wrapped component.
 */
function withContentClassification(
	OriginalComponent: React.ComponentType< any >
) {
	return function ContentClassificationWrapper( props: any ) {
		const { slug } = props;

		if ( ! SUPPORTED_TAXONOMIES.includes( slug ) ) {
			return <OriginalComponent { ...props } />;
		}

		return (
			<>
				<OriginalComponent { ...props } />
				<SuggestionPanel taxonomy={ slug } />
			</>
		);
	};
}

addFilter(
	'editor.PostTaxonomyType',
	'ai/content-classification',
	withContentClassification
);
