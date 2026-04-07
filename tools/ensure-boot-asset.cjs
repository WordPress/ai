/**
 * wp-build pages expect build/modules/boot/index.min.asset.php, but this plugin
 * does not ship a local boot package. We ensure that file exists by proxying
 * Core's boot asset file, with a tiny fallback for local development.
 */
/**
 * External dependencies
 */
const fs = require( 'fs' );
const path = require( 'path' );

const bootAssetPath = path.join(
	path.dirname( __dirname ),
	'build',
	'modules',
	'boot',
	'index.min.asset.php'
);

if ( fs.existsSync( bootAssetPath ) ) {
	process.exit( 0 );
}

const dependencies = [ 'react-jsx-runtime' ];

const dependencyList = dependencies
	.map( ( handle ) => `'${ handle }'` )
	.join( ', ' );
const fileContents = `<?php
$core_asset = ABSPATH . WPINC . '/js/dist/script-modules/boot/index.min.asset.php';
if ( file_exists( $core_asset ) ) {
	return require $core_asset;
}
return array('dependencies' => array(${ dependencyList }), 'version' => 'wp-build-fallback');`;

fs.mkdirSync( path.dirname( bootAssetPath ), { recursive: true } );
fs.writeFileSync( bootAssetPath, fileContents );
