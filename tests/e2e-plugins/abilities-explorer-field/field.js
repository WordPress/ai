/**
 * Adds a read-only "Slug length" column to the Abilities Explorer.
 *
 * The `e2e_fields` query argument switches the filter's behavior so one
 * fixture covers the extension point's edge cases:
 *
 * - (absent): add the "Slug length" column.
 * - `collide`: also add a field with the built-in `slug` ID, which must not
 *   replace the built-in Slug column.
 * - `non-array`: return a string instead of the fields.
 * - `throw`: throw from the filter.
 *
 * @param {Object} hooks The shared `wp.hooks` instance.
 */
( function ( hooks ) {
	const mode = new URLSearchParams( window.location.search ).get(
		'e2e_fields'
	);

	hooks.addFilter(
		'ai.abilitiesExplorer.fields',
		'ai-e2e/abilities-explorer-field',
		function ( fields ) {
			if ( 'non-array' === mode ) {
				return 'not an array';
			}

			if ( 'throw' === mode ) {
				throw new Error( 'E2E fields filter failure' );
			}

			const next = fields.concat( [
				{
					id: 'e2e/slug-length',
					label: 'Slug length',
					type: 'integer',
					enableSorting: true,
					getValue( { item } ) {
						return item.slug.length;
					},
				},
			] );

			if ( 'collide' === mode ) {
				next.push( {
					id: 'slug',
					label: 'Hijacked slug',
					type: 'text',
					getValue() {
						return 'hijacked';
					},
				} );
			}

			return next;
		}
	);
} )( window.wp.hooks );
