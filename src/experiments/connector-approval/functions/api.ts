/**
 * Network layer for the connector approval REST endpoints.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import type { ApprovalState } from '../types';

const REST_ROOT = '/ai/v1/connector-approvals';

let nonceMiddlewareInstalled = false;

/**
 * Installs the REST nonce middleware on first use.
 *
 * Running this lazily keeps module import side effects to a minimum and makes
 * the module safe to import from tests that stub the nonce globally.
 */
function ensureNonceMiddleware(): void {
	if ( nonceMiddlewareInstalled ) {
		return;
	}

	apiFetch.use(
		apiFetch.createNonceMiddleware(
			window.aiConnectorApproval?.nonce ?? ''
		)
	);
	nonceMiddlewareInstalled = true;
}

export function fetchApprovalState(): Promise< ApprovalState > {
	ensureNonceMiddleware();
	return apiFetch< ApprovalState >( { path: REST_ROOT } );
}

export function postApproval(
	pluginBasename: string,
	connectorId: string,
	approved: boolean
): Promise< ApprovalState > {
	ensureNonceMiddleware();
	return apiFetch< ApprovalState >( {
		path: REST_ROOT,
		method: 'POST',
		data: {
			plugin_basename: pluginBasename,
			connector_id: connectorId,
			approved,
		},
	} );
}

export function deletePending( key: string ): Promise< ApprovalState > {
	ensureNonceMiddleware();
	return apiFetch< ApprovalState >( {
		path: addQueryArgs( `${ REST_ROOT }/pending`, { key } ),
		method: 'DELETE',
	} );
}
