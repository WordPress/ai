/**
 * Pure presentation helpers for the connector approval UI.
 */

/**
 * Internal dependencies
 */
import type { Connector, PluginSummary, ApprovalMatrix } from '../types';

/**
 * Formats a timestamp as a human-readable string.
 *
 * @param {number} timestamp Timestamp in seconds.
 * @return string
 */
export function formatTimestamp( timestamp: number ): string {
	if ( ! timestamp ) {
		return '—';
	}
	return new Date( timestamp * 1000 ).toLocaleString();
}

/**
 * Returns the label for a connector.
 *
 * @param {Connector[]} connectors Array of connectors.
 * @param {string}      id         Connector ID.
 * @return string
 */
export function connectorLabel( connectors: Connector[], id: string ): string {
	return connectors.find( ( connector ) => connector.id === id )?.name ?? id;
}

/**
 * Extracts the plugin slug (the directory segment) from a basename.
 *
 * @param {string} basename Plugin basename.
 * @return string
 */
function pluginSlug( basename: string ): string {
	return basename.split( '/' )[ 0 ] ?? basename;
}

/**
 * Whether a given plugin is the owner of a connector.
 *
 * Owner basenames come from `wp_get_connectors()[$id]['plugin']['file']` and
 * match the shape of regular plugin basenames, so a slug comparison is enough.
 *
 * @param {Connector}     connector Connector.
 * @param {PluginSummary} plugin    Plugin summary.
 * @return {boolean} Whether the plugin is the owner of the connector.
 */
export function isOwnerPlugin(
	connector: Connector,
	plugin: PluginSummary
): boolean {
	if ( ! connector.owner ) {
		return false;
	}

	if ( connector.owner === plugin.basename ) {
		return true;
	}

	return pluginSlug( connector.owner ) === pluginSlug( plugin.basename );
}

/**
 * Returns the union of active plugins and plugins that appear in the approval
 * matrix (even if inactive), deduplicated and sorted for rendering.
 *
 * @param {PluginSummary[]} plugins   Array of plugin summaries.
 * @param {ApprovalMatrix}  approvals Approval matrix.
 * @return {PluginSummary[]} Array of plugin summaries.
 */
export function buildMatrixPluginList(
	plugins: PluginSummary[],
	approvals: ApprovalMatrix
): PluginSummary[] {
	const basenames = new Set< string >( [
		...plugins.map( ( plugin ) => plugin.basename ),
		...Object.keys( approvals ),
	] );

	return Array.from( basenames )
		.map( ( basename ) => ( {
			basename,
			name:
				plugins.find( ( plugin ) => plugin.basename === basename )
					?.name ?? basename,
		} ) )
		.sort( ( a, b ) => a.name.localeCompare( b.name ) );
}
