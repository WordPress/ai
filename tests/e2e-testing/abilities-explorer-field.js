/**
 * Adds a read-only "Slug length" column to the Abilities Explorer through the
 * `ai.abilitiesExplorer.fields` filter. Loaded by the E2E Testing plugin when
 * the Explorer is opened with `e2e_fields` set.
 *
 * @param {Object} hooks The shared `wp.hooks` instance.
 */
( function ( hooks ) {
	hooks.addFilter(
		'ai.abilitiesExplorer.fields',
		'ai-e2e/abilities-explorer-field',
		function ( fields ) {
			return fields.concat( [
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
		}
	);
} )( window.wp.hooks );
