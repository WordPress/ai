/**
 * PostCSS config for wp-scripts legacy builds (`build-scripts/`).
 * Prepends design-system token fallbacks, matching the wp-build routes pipeline.
 */
const postcssPluginsPreset = require( '@wordpress/postcss-plugins-preset' );
const dsTokenFallbacksModule = require(
	'@wordpress/theme/postcss-plugins/postcss-ds-token-fallbacks'
);
const dsTokenFallbacks =
	dsTokenFallbacksModule.default || dsTokenFallbacksModule;

/** @type {import('postcss-load-config').ConfigFn} */
module.exports = ( ctx ) => {
	const plugins = [ dsTokenFallbacks, ...postcssPluginsPreset ];

	if ( ctx.env === 'production' ) {
		plugins.push(
			require( 'cssnano' )( {
				preset: [
					'default',
					{
						discardComments: {
							removeAll: true,
						},
					},
				],
			} )
		);
	}

	return { plugins };
};
