/**
 * Chrome DevTools 3P tool definitions for the AI plugin.
 *
 * Exposes two tools:
 *   - get_ai_capabilities: lists registered AI features on the page
 *   - get_ai_request_history: returns the in-page AI request log
 */

/**
 * Internal dependencies
 */
import { getHistory } from './store';
import { getDevToolsRegistrations } from './registry';
import type { AIRequestRecord } from './store';

// Minimal typings for the Chrome DevTools 3P Tools API (no @types package yet).
export interface ToolDefinition {
	name: string;
	description: string;
	inputSchema: object;
	execute: ( args: Record< string, unknown > ) => unknown;
}

export interface ToolGroup {
	name: string;
	description: string;
	tools: ToolDefinition[];
}

type FeatureStatus = 'idle' | 'loading' | 'error';

interface CapabilityEntry {
	name: string;
	description: string;
	abilitySlug: string;
	status: FeatureStatus;
}

/**
 * Derives the current status of a feature from the request log.
 *
 * @since x.x.x
 * @param {string} abilitySlug The ability slug to check.
 * @return {FeatureStatus} Current status for the feature.
 */
function deriveStatus( abilitySlug: string ): FeatureStatus {
	// Get all records for this slug (most recent last).
	const matching = getHistory( 50 ).filter(
		( r: AIRequestRecord ) => r.ability === abilitySlug
	);

	if ( matching.length === 0 ) {
		return 'idle';
	}

	// getHistory returns newest-first, so matching[0] is the most recent.
	const latest = matching[ 0 ];

	if ( latest?.status === 'pending' ) {
		return 'loading';
	}
	if ( latest?.status === 'error' ) {
		return 'error';
	}
	return 'idle';
}

const getAiCapabilities: ToolDefinition = {
	name: 'get_ai_capabilities',
	description:
		'Lists the AI features registered on this page, including their name, description, ability slug, and current status (idle/loading/error).',
	inputSchema: {
		type: 'object',
		properties: {},
	},
	execute: () => {
		const registered = getDevToolsRegistrations();
		const seenSlugs = new Set< string >(
			registered.map( ( f ) => f.abilitySlug )
		);

		const features: CapabilityEntry[] = registered.map( ( f ) => ( {
			name: f.name,
			description: f.description,
			abilitySlug: f.abilitySlug,
			status: deriveStatus( f.abilitySlug ),
		} ) );

		// Include unregistered abilities that have run.
		for ( const record of getHistory( 50 ) ) {
			if ( ! seenSlugs.has( record.ability ) ) {
				seenSlugs.add( record.ability );
				features.push( {
					name: record.ability,
					description: record.ability,
					abilitySlug: record.ability,
					status: deriveStatus( record.ability ),
				} );
			}
		}

		return { features };
	},
};

const getAiRequestHistory: ToolDefinition = {
	name: 'get_ai_request_history',
	description:
		'Returns recent AI ability calls made on this page, including the ability slug, input, output, duration, and status.',
	inputSchema: {
		type: 'object',
		properties: {
			limit: {
				type: 'integer',
				minimum: 1,
				maximum: 50,
				default: 10,
				description: 'Maximum number of records to return.',
			},
		},
	},
	execute: ( args ) => {
		const { limit } = args as { limit?: number };
		return {
			requests: getHistory( typeof limit === 'number' ? limit : 10 ),
		};
	},
};

/**
 * Returns the ToolGroup to register with Chrome DevTools.
 *
 * @since x.x.x
 * @return {ToolGroup} The ToolGroup for the AI plugin.
 */
export function getToolGroup(): ToolGroup {
	return {
		name: 'WordPress AI Plugin',
		description:
			'Inspect AI feature capabilities and request history for the WordPress AI Plugin.',
		tools: [ getAiCapabilities, getAiRequestHistory ],
	};
}
