/**
 * Unit coverage for the Explorer's AI Workspace states.
 */
/**
 * External dependencies
 */
import { describe, expect, test } from 'vitest';

/**
 * Internal dependencies
 */
import {
	getWorkspaceChange,
	getWorkspaceState,
	getWorkspaceStateLabels,
} from '../workspace';
import type { AbilityListItem } from '../types';

const item = ( onSurface: boolean, removed: boolean ): AbilityListItem => ( {
	slug: 'test/ability',
	name: 'Test',
	description: '',
	provider: null,
	provider_label: null,
	origin: 'Plugin',
	category: null,
	show_in_rest: false,
	show_in_mcp: false,
	conversational_surface: onSurface,
	owner_excluded: removed,
	meta: null,
	unencodable_fields: [],
} );

describe( 'getWorkspaceState', () => {
	test( 'an ability the Workspace uses is used, with Remove', () => {
		expect( getWorkspaceState( item( true, false ) ) ).toBe( 'used' );
		expect( getWorkspaceChange( item( true, false ) ) ).toBe( 'remove' );
	} );

	test( 'an ability the owner removed is removed, with Restore', () => {
		expect( getWorkspaceState( item( false, true ) ) ).toBe( 'removed' );
		expect( getWorkspaceChange( item( false, true ) ) ).toBe( 'restore' );
	} );

	test( 'an ability the Workspace never uses has no state and no change', () => {
		expect( getWorkspaceState( item( false, false ) ) ).toBeNull();
		expect( getWorkspaceChange( item( false, false ) ) ).toBeNull();
	} );

	test( 'labels name the AI Workspace in plain words', () => {
		expect( getWorkspaceStateLabels() ).toEqual( {
			used: 'Used by AI Workspace',
			removed: 'Removed from AI Workspace',
		} );
	} );
} );
