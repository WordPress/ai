import apiFetch from '@wordpress/api-fetch';
import {
	InstallResponse,
	GeneratedFile,
} from './types';

const NAMESPACE = '/wordpress-ai-plugin-builder/v1';

export async function install(
	pluginSlug: string,
	files: GeneratedFile[],
	force: boolean = false
): Promise<InstallResponse> {
	return apiFetch<InstallResponse>({
		path: `${NAMESPACE}/install`,
		method: 'POST',
		data: {
			plugin_slug: pluginSlug,
			files,
			force,
		},
	});
}
