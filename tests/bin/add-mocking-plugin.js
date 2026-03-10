#!/usr/bin/env node

/**
 * External dependencies
 */
const fs = require( 'fs' );

const path = `${ process.cwd() }/.wp-env.override.json`;

// eslint-disable-next-line import/no-dynamic-require
const config = fs.existsSync( path ) ? require( path ) : {};

config.plugins = [
	'.',
	'https://downloads.wordpress.org/plugin/ai-provider-for-openai.zip',
	'./tests/e2e-request-mocking',
];

try {
	fs.writeFileSync( path, JSON.stringify( config ) );
} catch ( err ) {
	// eslint-disable-next-line no-console
	console.error( err );
}
