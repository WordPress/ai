import apiFetch from '@wordpress/api-fetch';
import { WriteResponse, GeneratedFile } from './types';

const NAMESPACE = '/wordpress-ai-plugin-builder/v1';

export async function writeFiles(
	pluginSlug: string,
	files: GeneratedFile[],
	force: boolean = false
): Promise< WriteResponse > {
	return apiFetch< WriteResponse >( {
		path: `${ NAMESPACE }/write-files`,
		method: 'POST',
		data: {
			plugin_slug: pluginSlug,
			files,
			force,
		},
	} );
}

export async function activatePlugin( pluginFile: string ): Promise< any > {
	// The WP Core REST API expects the plugin "file" which looks like "slug/slug.php"
	// but the route encodes the slash, or you don't encode the slash?
	// The route is `/wp/v2/plugins/<plugin>`
	return apiFetch( {
		path: `/wp/v2/plugins/${ pluginFile }`,
		method: 'POST',
		data: {
			status: 'active',
		},
	} );
}
