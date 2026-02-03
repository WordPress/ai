module.exports = {
	root: true,
	extends: [ 'plugin:@wordpress/eslint-plugin/recommended' ],
	plugins: [ 'import' ],
	parserOptions: {
		sourceType: 'module',
		ecmaFeatures: {
			jsx: true,
		},
		project: './tsconfig.json',
	},
	settings: {
		'import/resolver': {
			typescript: {
				project: './tsconfig.json',
			},
		},
	},
	env: {
		browser: true,
		es6: true,
		node: true,
	},
	rules: {
		// React best practices
		'react/jsx-boolean-value': 'error',
		'react/jsx-curly-brace-presence': [
			'error',
			{ props: 'never', children: 'never' },
		],

		// WordPress-specific rules, lifted from gutenberg
		'@wordpress/dependency-group': 'error',
		'@wordpress/data-no-store-string-literals': 'error',
		'@wordpress/wp-global-usage': 'error',
		'@wordpress/react-no-unsafe-timeout': 'error',
		'@wordpress/i18n-hyphenated-range': 'error',
		'@wordpress/i18n-no-flanking-whitespace': 'error',
		'@wordpress/i18n-text-domain': [
			'error',
			{
				allowedTextDomain: 'ai',
			},
		],
		'@wordpress/no-unsafe-wp-apis': 'off',
		'import/default': 'error',
		'import/named': 'error',
		'import/no-extraneous-dependencies': [
			'error',
			{
				devDependencies: [
					'**/*.@(spec|test).@(j|t)s?(x)',
					'**/@(webpack|jest).config.@(j|t)s',
					'**/scripts/**',
				],
			},
		],
		'no-restricted-imports': [
			'error',
			{
				paths: [
					{
						name: 'lodash',
						message: 'Please use native functionality instead.',
					},
					{
						name: 'classnames',
						message:
							"Please use `clsx` instead. It's a lighter and faster drop-in replacement for `classnames`.",
					},
					{
						name: 'redux',
						importNames: [ 'combineReducers' ],
						message:
							'Please use `combineReducers` from `@wordpress/data` instead.',
					},
				],
			},
		],
		'no-restricted-syntax': [
			'error',
			{
				selector:
					'ImportDeclaration[source.value=/^@wordpress\\u002F.+\\u002F/]',
				message:
					'Path access on WordPress dependencies is not allowed.',
			},
			{
				selector: 'JSXAttribute[name.name="id"][value.type="Literal"]',
				message:
					'Do not use string literals for IDs; use withInstanceId instead.',
			},
			{
				selector:
					'CallExpression[callee.object.name="Math"][callee.property.name="random"]',
				message:
					"Do not use Math.random() to generate unique IDs; use withInstanceId instead. (If you're not generating unique IDs: ignore this message.)",
			},
		],
	},
	overrides: [
		{
			files: [ '**/*.ts?(x)' ],
			rules: {
				'@typescript-eslint/consistent-type-imports': [
					'error',
					{
						prefer: 'type-imports',
						disallowTypeAnnotations: false,
					},
				],
				'@typescript-eslint/no-shadow': 'error',
				'no-shadow': 'off',
				'jsdoc/require-param': 'off',
				'jsdoc/require-param-type': 'off',
				'jsdoc/require-returns-type': 'off',
			},
		},
	],
};
