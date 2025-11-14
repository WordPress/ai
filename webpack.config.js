const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const webpackConfig = {
	...defaultConfig,
	entry: {
		...defaultConfig.entry(),
		'experiments/title-generation':
			'./src/experiments/title-generation/index.tsx',
	},
};

module.exports = webpackConfig;
