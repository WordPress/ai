/**
 * Pure presentation helpers for the connector approval UI.
 */

/**
 * Internal dependencies
 */
import type {
	ApprovalMatrix,
	CallerSummary,
	Connector,
	PluginSummary,
	ThemeSummary,
} from '../types';

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
 * Returns the union of active callers and callers that appear in the approval
 * matrix (even if inactive), deduplicated and sorted for rendering.
 * @param {PluginSummary[]} plugins   Active plugin summaries.
 * @param {ThemeSummary[]}  themes    Active theme summaries.
 * @param {ApprovalMatrix}  approvals Caller basename → connector ID → approved.
 * @return {CallerSummary[]} Caller summaries sorted by name.
 */
export function buildMatrixCallerList(
	plugins: PluginSummary[],
	themes: ThemeSummary[],
	approvals: ApprovalMatrix
): CallerSummary[] {
	const activeCallers = [
		...plugins.map( ( plugin ) => ( {
			...plugin,
			type: 'plugin' as const,
		} ) ),
		...themes.map( ( theme ) => ( { ...theme, type: 'theme' as const } ) ),
	];
	const basenames = new Set< string >( [
		...activeCallers.map( ( caller ) => caller.basename ),
		...Object.keys( approvals ),
	] );

	return Array.from( basenames )
		.map( ( basename ): CallerSummary => {
			const activeCaller = activeCallers.find(
				( caller ) => caller.basename === basename
			);

			return {
				basename,
				type: activeCaller?.type ?? 'unknown',
				name: activeCaller?.name ?? basename,
			};
		} )
		.sort( ( a, b ) => a.name.localeCompare( b.name ) );
}
