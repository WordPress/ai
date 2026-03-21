import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { WriteResponse, GeneratedFile, ChatHistory } from './types';

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

export async function downloadPlugin( pluginFile: string ): Promise< void > {
	const { restUrl, nonce } = window.aiPluginBuilder;

	const url = new URL( `${ restUrl }download` );
	url.searchParams.set( 'plugin_file', pluginFile );

	const response = await fetch( url.toString(), {
		method: 'GET',
		headers: {
			'X-WP-Nonce': nonce,
		},
	} );

	if ( ! response.ok ) {
		const text = await response.text();
		throw new Error(
			text || __( 'Failed to generate ZIP archive.', 'ai' )
		);
	}

	const blob = await response.blob();
	const blobUrl = URL.createObjectURL( blob );
	const anchor = document.createElement( 'a' );
	anchor.href = blobUrl;
	const pluginSlug = pluginFile.split( '/' )[ 0 ];
	anchor.download = `${ pluginSlug }.zip`;
	document.body.appendChild( anchor );
	anchor.click();
	document.body.removeChild( anchor );
	URL.revokeObjectURL( blobUrl );
}

export async function executeAbility(
	name: string,
	input: any
): Promise< any > {
	return apiFetch( {
		path: `/wp-abilities/v1/abilities/${ name }/run`,
		method: 'POST',
		data: { input },
	} );
}

export async function discoverAbilities(): Promise< any > {
	return apiFetch( {
		path: `/wp-abilities/v1/abilities`,
		method: 'GET',
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

export async function getChatHistory(): Promise< ChatHistory[] > {
	return apiFetch< ChatHistory[] >( {
		path: `${ NAMESPACE }/history`,
		method: 'GET',
	} );
}

export async function getChatById( id: number ): Promise< ChatHistory > {
	return apiFetch< ChatHistory >( {
		path: `${ NAMESPACE }/history/${ id }`,
		method: 'GET',
	} );
}

export async function deleteChatHistory(
	id: number
): Promise< { deleted: boolean } > {
	return apiFetch< { deleted: boolean } >( {
		path: `${ NAMESPACE }/history/${ id }`,
		method: 'DELETE',
	} );
}

export async function getPluginFiles(
	pluginSlug: string
): Promise< { plugin_slug: string; files: GeneratedFile[] } > {
	return apiFetch< { plugin_slug: string; files: GeneratedFile[] } >( {
		path: `${ NAMESPACE }/files/${ pluginSlug }`,
		method: 'GET',
	} );
}

export async function saveChatHistory(
	messages: any[],
	pluginSlug?: string,
	postId?: number,
	title?: string
): Promise< ChatHistory > {
	return apiFetch< ChatHistory >( {
		path: `${ NAMESPACE }/history`,
		method: 'POST',
		data: {
			messages: JSON.stringify( messages ),
			plugin_slug: pluginSlug,
			post_id: postId,
			title,
		},
	} );
}

export async function listPlugins(): Promise< any > {
	const perPage = 100;
	let page = 1;
	let allPlugins: any[] = [];

	// Fetch all pages of plugins until a page returns fewer than perPage items.
	while ( true ) {
		const pageItems = await apiFetch< any[] >( {
			path: `/wp/v2/plugins?per_page=${ perPage }&page=${ page }`,
			method: 'GET',
		} );

		if ( ! Array.isArray( pageItems ) || pageItems.length === 0 ) {
			break;
		}

		allPlugins = allPlugins.concat( pageItems );

		if ( pageItems.length < perPage ) {
			break;
		}

		page++;
	}

	return allPlugins;
}
