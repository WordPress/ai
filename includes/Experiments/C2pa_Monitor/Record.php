<?php
/**
 * Builds and persists the _wpai_monitor_record postmeta entry.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\C2pa_Monitor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates and writes the structured monitor record into postmeta.
 *
 * The record is stored as a single JSON-encoded string at the
 * `_wpai_monitor_record` key. Storing JSON (rather than a serialized PHP
 * array) keeps the value portable for REST APIs and downstream tooling
 * without depending on PHP's serialize format.
 *
 * @see https://raw.githubusercontent.com/decentralized-identity/credential-schemas/main/community-schemas/WordPress/schemas/wpai-monitor-record/schema.json
 *     Subject-only JSON Schema (DIF credential-schemas; wrap in a VC at issuance if needed).
 * @see C2pa_Monitor::CONTEXT_URL for the JSON-LD context embedded in every stored record.
 *
 * @since 0.7.0
 */
class Record {
	/**
	 * Required top-level keys for a valid record.
	 *
	 * @var string[]
	 */
	private const REQUIRED_KEYS = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- List of top-level postmeta record keys, same pattern as Experiments::EXPERIMENT_CLASSES.
		'@context',
		'schema_version',
		'captured_at',
		'duration_ms',
		'source',
		'traditional',
		'c2pa',
		'errors',
	);

	/**
	 * Persists the record for $attachment_id. Returns true on success.
	 *
	 * Validation is intentionally lenient: missing keys are filled with
	 * defaults rather than rejected, so the fail-open boundary in the
	 * feature class always produces *some* record. Encoding errors are
	 * the only hard failure.
	 *
	 * @since 0.7.0
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $record        Record array.
	 * @return bool
	 */
	public static function store( int $attachment_id, array $record ): bool {
		if ( $attachment_id <= 0 ) {
			return false;
		}

		$normalized = self::normalize( $record );

		$encoded = wp_json_encode( $normalized );
		if ( false === $encoded ) {
			return false;
		}

		return false !== update_post_meta( $attachment_id, C2pa_Monitor::POSTMETA_KEY, wp_slash( $encoded ) );
	}

	/**
	 * Loads and decodes the stored record, or null if not present.
	 *
	 * Convenience accessor used by tests and PR 2 / PR 3 consumers.
	 *
	 * @since 0.7.0
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, mixed>|null
	 */
	public static function load( int $attachment_id ): ?array {
		$raw = get_post_meta( $attachment_id, C2pa_Monitor::POSTMETA_KEY, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Fills missing keys with defaults so persisted records always match the
	 * documented schema shape.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string, mixed> $record Input record.
	 * @return array<string, mixed>
	 */
	private static function normalize( array $record ): array {
		foreach ( self::REQUIRED_KEYS as $key ) {
			if ( array_key_exists( $key, $record ) ) {
				continue;
			}
			$record[ $key ] = self::default_for( $key );
		}

		$record['source']      = is_array( $record['source'] ) ? $record['source'] : self::default_for( 'source' );
		$record['traditional'] = is_array( $record['traditional'] ) ? $record['traditional'] : self::default_for( 'traditional' );
		$record['c2pa']        = is_array( $record['c2pa'] ) ? $record['c2pa'] : self::default_for( 'c2pa' );
		$record['errors']      = is_array( $record['errors'] ) ? $record['errors'] : array();

		return $record;
	}

	/**
	 * Returns the default value for a top-level record key.
	 *
	 * @since 0.7.0
	 *
	 * @param string $key Key name.
	 * @return mixed
	 */
	private static function default_for( string $key ) {
		switch ( $key ) {
			case '@context':
				return array( 'https://schema.org/', C2pa_Monitor::CONTEXT_URL );
			case 'schema_version':
				return C2pa_Monitor::SCHEMA_VERSION;
			case 'captured_at':
				return gmdate( 'Y-m-d\TH:i:s\Z' );
			case 'duration_ms':
				return 0;
			case 'source':
				return array(
					'attachment_id'          => 0,
					'original_path_relative' => '',
					'size_bytes'             => 0,
					'mime'                   => '',
				);
			case 'traditional':
				return array(
					'exif' => array(),
					'iptc' => array(),
					'xmp'  => array(),
				);
			case 'c2pa':
				return array(
					'present' => false,
					'format'  => null,
				);
			case 'errors':
				return array();
		}
		return null;
	}
}
