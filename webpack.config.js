const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

const webpackConfig = {
	...defaultConfig,
	entry: {
		...defaultConfig.entry(),
		'features/title-generation': './src/features/title-generation/index.js'
	},
};

module.exports = webpackConfig;
