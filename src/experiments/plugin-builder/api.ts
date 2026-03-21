import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
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

export async function downloadPlugin( pluginSlug: string ): Promise< void > {
	const { restUrl, nonce } = window.aiPluginBuilder;

	const url = new URL( `${ restUrl }download` );
	url.searchParams.set( 'plugin_slug', pluginSlug );

	const response = await fetch( url.toString(), {
		method: 'GET',
		headers: {
			'X-WP-Nonce': nonce,
		},
	} );

	if ( ! response.ok ) {
		const text = await response.text();
		throw new Error( text || __( 'Failed to generate ZIP archive.', 'ai' ) );
	}

	const blob = await response.blob();
	const blobUrl = URL.createObjectURL( blob );
	const anchor = document.createElement( 'a' );
	anchor.href = blobUrl;
	anchor.download = `${ pluginSlug }.zip`;
	document.body.appendChild( anchor );
	anchor.click();
	document.body.removeChild( anchor );
	URL.revokeObjectURL( blobUrl );
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
