/**
 * Pure presentation helpers for the connector approval UI.
 */

/**
 * Internal dependencies
 */
import type { ApprovalMatrix, Connector, PluginSummary } from '../types';

/**
 * Formats a timestamp as a human-readable string.
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
 * Returns the display label for a connector given its ID.
 * @param {Connector[]} connectors Array of connectors.
 * @param {string}      id         Connector ID.
 * @return string
 */
export function connectorLabel( connectors: Connector[], id: string ): string {
	return connectors.find( ( connector ) => connector.id === id )?.name ?? id;
}

/**
 * Returns the union of active plugins and plugins that appear in the approval
 * matrix (even if inactive), deduplicated and sorted for rendering.
 * @param {PluginSummary[]} plugins   Active plugin summaries.
 * @param {ApprovalMatrix}  approvals Plugin basename → connector ID → approved.
 * @return {PluginSummary[]} Plugin summaries sorted by name.
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
