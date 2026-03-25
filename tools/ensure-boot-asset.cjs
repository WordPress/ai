const fs = require( 'fs' );
const path = require( 'path' );

const bootAssetPath = path.join(
	process.cwd(),
	'build',
	'modules',
	'boot',
	'index.min.asset.php'
);

if ( fs.existsSync( bootAssetPath ) ) {
	process.exit( 0 );
}

const dependencies = [
	'react',
	'react-dom',
	'react-jsx-runtime',
	'wp-commands',
	'wp-components',
	'wp-compose',
	'wp-core-data',
	'wp-data',
	'wp-editor',
	'wp-element',
	'wp-html-entities',
	'wp-i18n',
	'wp-keyboard-shortcuts',
	'wp-keycodes',
	'wp-notices',
	'wp-primitives',
	'wp-private-apis',
	'wp-theme',
	'wp-url',
];

const dependencyList = dependencies.map( ( handle ) => `'${ handle }'` ).join( ', ' );
const fileContents = `<?php return array('dependencies' => array(${ dependencyList }), 'version' => 'wp-build-fallback');`;

fs.mkdirSync( path.dirname( bootAssetPath ), { recursive: true } );
fs.writeFileSync( bootAssetPath, fileContents );
