<?php
/**
 * Decodes the active manifest of a C2PA manifest store into c2pa.decoded.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\C2pa_Monitor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the `c2pa.decoded` portion of the monitor record from raw JUMBF
 * manifest store bytes.
 *
 * Decode-only: no signature, certificate or hash checks are made, so every
 * value returned is an unverified claim read from the file.
 *
 * Only the active manifest (the last one in the store) is read. Its claim
 * (`c2pa.claim` v1 or `c2pa.claim.v2`) supplies the claim generator, and its
 * actions assertions (`c2pa.actions` / `c2pa.actions.v2`) supply the action
 * history and digital source type. v1 and v2 generator descriptions are
 * normalized into one `{ name, version }` shape.
 *
 * Never throws: failures are appended to the caller's errors array and the
 * method returns whatever could be decoded (or null when nothing could).
 *
 * @since x.x.x
 *
 * @phpstan-import-type Jumbf_Superbox from Jumbf_Reader
 */
class Manifest_Decoder {
	/**
	 * jumd type UUID of a standard manifest superbox ("c2ma").
	 *
	 * @var string
	 */
	private const MANIFEST_UUID = '63326d6100110010800000aa00389b71';

	/**
	 * jumd type UUID of an update manifest superbox ("c2um").
	 *
	 * @var string
	 */
	private const UPDATE_MANIFEST_UUID = '6332756d00110010800000aa00389b71';

	/**
	 * Label of the assertion store superbox inside a manifest.
	 *
	 * @var string
	 */
	private const ASSERTION_STORE_LABEL = 'c2pa.assertions';

	/**
	 * Matches claim labels: c2pa.claim (v1) and c2pa.claim.v2.
	 *
	 * @var string
	 */
	private const CLAIM_LABEL_PATTERN = '/^c2pa\.claim(\.v2)?$/';

	/**
	 * Matches actions assertion labels, including multi-instance `__N` suffixes.
	 *
	 * @var string
	 */
	private const ACTIONS_LABEL_PATTERN = '/^c2pa\.actions(\.v2)?(__\d+)?$/';

	/**
	 * Most actions to keep in the record.
	 *
	 * @var int
	 */
	private const MAX_ACTIONS = 100;

	/**
	 * Longest string value to keep, in characters.
	 *
	 * @var int
	 */
	private const MAX_STRING_LENGTH = 256;

	/**
	 * Decodes the active manifest of $bytes.
	 *
	 * @since x.x.x
	 *
	 * @param string                                          $bytes  Raw JUMBF manifest store bytes.
	 * @param list<array{stage: string, message: string}> $errors Error list; decode failures are appended.
	 * @return array{manifest_label: string|null, claim_generator: array{name: string, version: string|null}|null, digital_source_type: string|null, actions: list<array{action: string, when: string|null, softwareAgent: array{name: string, version: string|null}|null, digitalSourceType: string|null}>}|null
	 */
	public function decode( string $bytes, array &$errors ): ?array {
		try {
			$store = ( new Jumbf_Reader() )->parse( $bytes );
		} catch ( \UnexpectedValueException $e ) {
			$errors[] = array(
				'stage'   => 'decode_jumbf',
				'message' => $e->getMessage(),
			);
			return null;
		}

		$manifest = self::find_active_manifest( $store );
		if ( null === $manifest ) {
			$errors[] = array(
				'stage'   => 'decode_jumbf',
				'message' => esc_html__( 'The manifest store does not contain a manifest.', 'ai' ),
			);
			return null;
		}

		$decoded = array(
			'manifest_label'      => self::clean_string( $manifest['label'] ),
			'claim_generator'     => null,
			'digital_source_type' => null,
			'actions'             => array(),
		);

		$claim = $this->decode_claim( $bytes, $manifest, $errors );
		if ( null !== $claim ) {
			$decoded['claim_generator'] = self::claim_generator( $claim );
		}

		$decoded['actions']             = $this->decode_actions( $bytes, $manifest, $errors );
		$decoded['digital_source_type'] = self::digital_source_type( $decoded['actions'] );

		return $decoded;
	}

	/**
	 * Returns the last manifest superbox in the store.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $store Root superbox node.
	 * @phpstan-param Jumbf_Superbox $store
	 * @return array<string, mixed>|null
	 * @phpstan-return Jumbf_Superbox|null
	 */
	private static function find_active_manifest( array $store ): ?array {
		$active = null;

		foreach ( $store['children'] as $child ) {
			if ( ! in_array( $child['uuid'], array( self::MANIFEST_UUID, self::UPDATE_MANIFEST_UUID ), true ) ) {
				continue;
			}
			$active = $child;
		}

		return $active;
	}

	/**
	 * Decodes the claim map of $manifest.
	 *
	 * @since x.x.x
	 *
	 * @param string                                          $bytes    Raw store bytes.
	 * @param array<string, mixed>                            $manifest Manifest superbox node.
	 * @param list<array{stage: string, message: string}> $errors   Error list.
	 * @phpstan-param Jumbf_Superbox $manifest
	 * @return array<int|string, mixed>|null
	 */
	private function decode_claim( string $bytes, array $manifest, array &$errors ): ?array {
		foreach ( $manifest['children'] as $child ) {
			if ( null === $child['label'] || 1 !== preg_match( self::CLAIM_LABEL_PATTERN, $child['label'] ) ) {
				continue;
			}

			$claim = $this->decode_cbor_box( $bytes, $child, 'decode_claim', $errors );
			if ( null !== $claim && ! is_array( $claim ) ) {
				$errors[] = array(
					'stage'   => 'decode_claim',
					'message' => esc_html__( 'The claim is not a CBOR map.', 'ai' ),
				);
				return null;
			}

			return $claim;
		}

		$errors[] = array(
			'stage'   => 'decode_claim',
			'message' => esc_html__( 'The active manifest does not contain a claim.', 'ai' ),
		);
		return null;
	}

	/**
	 * Collects the actions from every actions assertion in $manifest, in
	 * assertion store order.
	 *
	 * @since x.x.x
	 *
	 * @param string                                          $bytes    Raw store bytes.
	 * @param array<string, mixed>                            $manifest Manifest superbox node.
	 * @param list<array{stage: string, message: string}> $errors   Error list.
	 * @phpstan-param Jumbf_Superbox $manifest
	 * @return list<array{action: string, when: string|null, softwareAgent: array{name: string, version: string|null}|null, digitalSourceType: string|null}>
	 */
	private function decode_actions( string $bytes, array $manifest, array &$errors ): array {
		$actions = array();

		foreach ( $manifest['children'] as $store ) {
			if ( self::ASSERTION_STORE_LABEL !== $store['label'] ) {
				continue;
			}

			foreach ( $store['children'] as $assertion ) {
				if ( null === $assertion['label'] || 1 !== preg_match( self::ACTIONS_LABEL_PATTERN, $assertion['label'] ) ) {
					continue;
				}

				$value = $this->decode_cbor_box( $bytes, $assertion, 'decode_assertion', $errors );
				if ( ! is_array( $value ) || ! isset( $value['actions'] ) || ! is_array( $value['actions'] ) ) {
					continue;
				}

				foreach ( $value['actions'] as $entry ) {
					$action = self::normalize_action( $entry );
					if ( null === $action ) {
						continue;
					}
					if ( count( $actions ) >= self::MAX_ACTIONS ) {
						return $actions;
					}
					$actions[] = $action;
				}
			}
		}

		return $actions;
	}

	/**
	 * Decodes the `cbor` content box of $superbox.
	 *
	 * @since x.x.x
	 *
	 * @param string                                          $bytes    Raw store bytes.
	 * @param array<string, mixed>                            $superbox Superbox node.
	 * @param string                                          $stage    Error stage name.
	 * @param list<array{stage: string, message: string}> $errors   Error list.
	 * @phpstan-param Jumbf_Superbox $superbox
	 * @return mixed Decoded value, or null when there is no CBOR box or it fails to decode.
	 */
	private function decode_cbor_box( string $bytes, array $superbox, string $stage, array &$errors ) {
		foreach ( $superbox['boxes'] as $box ) {
			if ( 'cbor' !== $box['type'] ) {
				continue;
			}

			try {
				return ( new Cbor_Decoder() )->decode( $bytes, $box['offset'], $box['length'] );
			} catch ( \UnexpectedValueException $e ) {
				$errors[] = array(
					'stage'   => $stage,
					'message' => esc_html( (string) $superbox['label'] ) . ': ' . $e->getMessage(),
				);
				return null;
			}
		}

		$errors[] = array(
			'stage'   => $stage,
			/* translators: %s: C2PA box label, e.g. c2pa.claim.v2. */
			'message' => sprintf( esc_html__( '%s has no CBOR content box.', 'ai' ), esc_html( (string) $superbox['label'] ) ),
		);
		return null;
	}

	/**
	 * Normalizes the claim generator of a v1 or v2 claim.
	 *
	 * v2 claims describe the generator with a `claim_generator_info` map.
	 * v1 claims carry a `claim_generator` user-agent string and may also carry
	 * a `claim_generator_info` list; the structured info wins when present.
	 *
	 * @since x.x.x
	 *
	 * @param array<int|string, mixed> $claim Decoded claim map.
	 * @return array{name: string, version: string|null}|null
	 */
	private static function claim_generator( array $claim ): ?array {
		$info = $claim['claim_generator_info'] ?? null;
		if ( is_array( $info ) && isset( $info[0] ) ) {
			$info = $info[0];
		}

		$generator = self::generator_info( $info );
		if ( null !== $generator ) {
			return $generator;
		}

		return self::generator_info( $claim['claim_generator'] ?? null );
	}

	/**
	 * Normalizes a generator description: either a plain string or a
	 * `{ name, version }` map.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $info Raw generator value.
	 * @return array{name: string, version: string|null}|null
	 */
	private static function generator_info( $info ): ?array {
		$name    = null;
		$version = null;

		if ( is_array( $info ) ) {
			$name    = self::clean_string( $info['name'] ?? null );
			$version = self::clean_string( $info['version'] ?? null );
		} else {
			$name = self::clean_string( $info );
		}

		if ( null === $name ) {
			return null;
		}

		return array(
			'name'    => $name,
			'version' => $version,
		);
	}

	/**
	 * Reduces a raw action entry to the keys kept in the record.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $entry Raw action map.
	 * @return array{action: string, when: string|null, softwareAgent: array{name: string, version: string|null}|null, digitalSourceType: string|null}|null
	 */
	private static function normalize_action( $entry ): ?array {
		if ( ! is_array( $entry ) ) {
			return null;
		}

		$action = self::clean_string( $entry['action'] ?? null );
		if ( null === $action ) {
			return null;
		}

		return array(
			'action'            => $action,
			'when'              => self::clean_string( $entry['when'] ?? null ),
			'softwareAgent'     => self::generator_info( $entry['softwareAgent'] ?? null ),
			'digitalSourceType' => self::clean_string( $entry['digitalSourceType'] ?? null ),
		);
	}

	/**
	 * Picks the digital source type: the one on the `c2pa.created` action
	 * when present, otherwise the first one found on any action.
	 *
	 * @since x.x.x
	 *
	 * @param list<array{action: string, when: string|null, softwareAgent: array{name: string, version: string|null}|null, digitalSourceType: string|null}> $actions Normalized actions.
	 * @return string|null
	 */
	private static function digital_source_type( array $actions ): ?string {
		$fallback = null;

		foreach ( $actions as $action ) {
			if ( null === $action['digitalSourceType'] ) {
				continue;
			}
			if ( 'c2pa.created' === $action['action'] ) {
				return $action['digitalSourceType'];
			}
			if ( null !== $fallback ) {
				continue;
			}

			$fallback = $action['digitalSourceType'];
		}

		return $fallback;
	}

	/**
	 * Returns $value when it is a non-empty, valid UTF-8 string, truncated to
	 * MAX_STRING_LENGTH characters; null otherwise.
	 *
	 * CBOR byte strings decode to PHP strings too, so this check is what keeps
	 * arbitrary binary out of the JSON-encoded record.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private static function clean_string( $value ): ?string {
		if ( ! is_string( $value ) || '' === $value || 1 !== preg_match( '//u', $value ) ) {
			return null;
		}

		return mb_substr( $value, 0, self::MAX_STRING_LENGTH );
	}
}
