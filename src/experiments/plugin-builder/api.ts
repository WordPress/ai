import apiFetch from '@wordpress/api-fetch';
import {
	AnyGenerateResponse,
	StatusResponse,
	InstallResponse,
	PluginPlan,
	GeneratedFile,
} from './types';

const NAMESPACE = '/wordpress-ai-plugin-builder/v1';

export async function generate(
	description: string,
	complexity: 'simple' | 'complex' = 'simple',
	previous_plan: PluginPlan | null = null,
	previous_files: GeneratedFile[] | null = null,
): Promise<AnyGenerateResponse> {
	return apiFetch<AnyGenerateResponse>({
		path: `${NAMESPACE}/generate`,
		method: 'POST',
		data: {
			description,
			complexity,
			previous_plan,
			previous_files,
		},
	});
}

export async function getStatus(jobId: string): Promise<StatusResponse> {
	return apiFetch<StatusResponse>({
		path: `${NAMESPACE}/status/${jobId}?t=${Date.now()}`,
		method: 'GET',
		// Prevent cache plugin caching on frontend side
		headers: {
			'Cache-Control': 'no-cache, no-store, must-revalidate',
			'Pragma': 'no-cache',
			'Expires': '0'
		}
	});
}

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
