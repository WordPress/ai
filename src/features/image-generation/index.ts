/**
 * Image generation.
 */

/**
 * Internal dependencies
 */
import { exposeToDevTools } from '../../utils/devtools';
import './featured-image';
import './media-library';
import './media-library-editor';
import './inline';

exposeToDevTools( {
	name: 'Image Generation',
	description:
		'Generates images from text prompts and inserts them into posts, media library, or as featured images.',
	abilitySlug: 'ai/image-generation',
} );
