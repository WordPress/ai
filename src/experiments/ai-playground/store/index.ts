/**
 * External dependencies
 */
import { combineStoreConfigs } from 'wp-store-utils';

/**
 * WordPress dependencies
 */
import { createReduxStore, register } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { STORE_NAME } from './name';
import capabilitiesStoreConfig from './capabilities';
import serviceModelStoreConfig from './service-model';
import messagesStoreConfig from './messages';

const storeConfig = combineStoreConfigs(
	capabilitiesStoreConfig,
	serviceModelStoreConfig,
	messagesStoreConfig
);

export const store = createReduxStore( STORE_NAME, storeConfig );
register( store );
