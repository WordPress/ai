const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const webpackConfig = {
	...defaultConfig,
	entry: {
		...defaultConfig.entry(),
		'features/title-generation':
			'./src/features/title-generation/index.tsx',
	},
};

module.exports = webpackConfig;
