<?php
/**
 * Well-Known C2PA Discovery Handler.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Content_Provenance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles /.well-known/c2pa discovery endpoint per C2PA 2.x §6.4.
 *
 * @since 0.5.0
 */
class Well_Known_Handler {

	/**
	 * Query variable name used to identify this request.
	 *
	 * @since 0.5.0
	 */
	public const QUERY_VAR = 'c2pa_well_known';

	/**
	 * Register the rewrite rule for /.well-known/c2pa.
	 *
	 * @since 0.5.0
	 */
	public static function add_rewrite_rule(): void {
		add_rewrite_rule(
			'^\.well-known/c2pa/?$',
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
		add_filter(
			'query_vars',
			static function ( array $vars ): array {
				$vars[] = self::QUERY_VAR;
				return $vars;
			}
		);
	}

	/**
	 * If the current request is for /.well-known/c2pa, output the discovery document.
	 *
	 * @since 0.5.0
	 */
	public static function maybe_handle(): void {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		$document = self::build_document();

		header( 'Content-Type: application/json' );
		header( 'Cache-Control: public, max-age=3600' );
		echo wp_json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Builds the C2PA well-known discovery document.
	 *
	 * @since 0.5.0
	 *
	 * @return array<string, mixed> The discovery document.
	 */
	public static function build_document(): array {
		return array(
			'@context'     => 'https://c2pa.org/schemas/c2pa-well-known/v1',
			'publisher'    => get_bloginfo( 'name' ),
			'url'          => home_url(),
			'signing'      => array(
				'active' => true,
				'spec'   => 'C2PA 2.3 Section A.7',
			),
			'verify'       => array(
				'endpoint' => rest_url( 'c2pa-provenance/v1/verify' ),
			),
			'generated_at' => gmdate( 'c' ),
		);
	}
}
