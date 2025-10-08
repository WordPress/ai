<?php
/**
 * Bootstrap file for PHPStan analysis.
 *
 * Provides light-weight stubs for common WordPress functions so that
 * static analysis can run without loading the full WP stack.
 *
 * @package WordPress\AI\Tests
 */

$autoloader = __DIR__ . '/../vendor/autoload.php';
if ( file_exists( $autoloader ) ) {
	require_once $autoloader;
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ) {}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ) {}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ) {}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		return $value;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $namespace, string $route, array $args = array(), bool $override = false ) {}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ) {
		return true;
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() {
		return true;
	}
}
