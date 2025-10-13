<?php
/**
 * Bootstrap file for PHPStan analysis.
 *
 * Loads WordPress stubs for static analysis.
 *
 * @package WordPress\AI\Tests
 */

$autoloader = __DIR__ . '/../vendor/autoload.php';
if ( file_exists( $autoloader ) ) {
	require_once $autoloader;
}
