import apiFetch from '@wordpress/api-fetch';
import { WriteResponse, GeneratedFile } from './types';

declare global {
	interface Window {
		aiPluginBuilder: {
			restUrl: string;
			nonce: string;
			adminUrl: string;
		};
	}
}

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

export async function downloadPlugin(
	pluginSlug: string,
	files: GeneratedFile[]
): Promise< void > {
	const { restUrl, nonce } = window.aiPluginBuilder;

	const response = await fetch( `${ restUrl }download`, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': nonce,
		},
		body: JSON.stringify( { plugin_slug: pluginSlug, files } ),
	} );

	if ( ! response.ok ) {
		const text = await response.text();
		throw new Error( text || 'Failed to generate ZIP archive.' );
	}

	const blob = await response.blob();
	const url = URL.createObjectURL( blob );
	const anchor = document.createElement( 'a' );
	anchor.href = url;
	anchor.download = `${ pluginSlug }.zip`;
	document.body.appendChild( anchor );
	anchor.click();
	document.body.removeChild( anchor );
	URL.revokeObjectURL( url );
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
