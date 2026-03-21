import apiFetch from '@wordpress/api-fetch';
import { WriteResponse, GeneratedFile, ChatHistory } from './types';

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

export async function getChatHistory(): Promise<ChatHistory[]> {
	return apiFetch<ChatHistory[]>({
		path: `${NAMESPACE}/history`,
		method: 'GET',
	});
}

export async function getChatById( id: number ): Promise<ChatHistory> {
	return apiFetch<ChatHistory>({
		path: `${NAMESPACE}/history/${id}`,
		method: 'GET',
	});
}

export async function getPluginFiles( pluginSlug: string ): Promise<{ plugin_slug: string, files: GeneratedFile[] }> {
	return apiFetch<{ plugin_slug: string, files: GeneratedFile[] }>({
		path: `${NAMESPACE}/files/${pluginSlug}`,
		method: 'GET',
	});
}

export async function saveChatHistory(
	messages: any[],
	pluginSlug?: string,
	postId?: number,
	title?: string
): Promise<ChatHistory> {
	return apiFetch<ChatHistory>({
		path: `${NAMESPACE}/history`,
		method: 'POST',
		data: {
			messages: JSON.stringify(messages),
			plugin_slug: pluginSlug,
			post_id: postId,
			title,
		},
	});
}
