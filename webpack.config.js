const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const webpackConfig = {
	...defaultConfig,
	entry: {
		...defaultConfig.entry(),
		'admin/settings': './src/admin/settings/index.scss',
		'experiments/example-experiment':
			'./src/experiments/example-experiment/index.tsx',
		'experiments/title-generation':
			'./src/experiments/title-generation/index.tsx',
	},
};

module.exports = webpackConfig;
