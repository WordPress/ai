/**
 * Provider status utilities.
 */

/**
 * WordPress dependencies
 */
import { dispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

interface ProviderStatus {
	hasProvider: boolean;
	connectorsUrl: string;
}

declare global {
	interface Window {
		aiProviderData?: ProviderStatus;
	}
}

export function getProviderData(): ProviderStatus {
	return (
		window.aiProviderData ?? {
			hasProvider: false,
			connectorsUrl: '',
		}
	);
}

/**
 * Checks whether an AI provider is available and creates a notice to guide them if not.
 *
 * @param noticeId Unique ID for the notice to prevent duplicates.
 * @return True if a provider is available, false otherwise.
 */
export function ensureProvider( noticeId: string ): boolean {
	const providerStatus = getProviderData();

	if ( providerStatus.hasProvider ) {
		return true;
	}

	const { connectorsUrl } = providerStatus;

	( dispatch( noticesStore ) as any ).createErrorNotice(
		__(
			'This feature requires an AI Connector to function properly.',
			'ai'
		),
		{
			id: noticeId,
			isDismissible: true,
			actions: connectorsUrl
				? [
						{
							label: __( 'Manage Connectors', 'ai' ),
							url: connectorsUrl,
						},
				  ]
				: [],
		}
	);

	return false;
}

/**
 * Returns whether an AI provider is currently configured.
 */
export function isProviderAvailable(): boolean {
	return getProviderData().hasProvider;
}
