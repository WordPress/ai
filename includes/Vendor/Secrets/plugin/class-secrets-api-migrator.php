<?php
/**
 * Secrets API: migrator (plugin-only, never copied to core)
 *
 * @package SecretsAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Copies secrets out of the prototype's on-disk format into the new one.
 *
 * Strictly additive, by construction rather than by flag: this reads the
 * prototype's option rows and writes new-format ones, and there is no code path
 * here that writes to, deletes, or otherwise disturbs anything the prototype
 * owns. A site that runs this ends up with both copies, and the prototype --
 * along with anything vendoring it -- keeps working exactly as before.
 *
 * That is a deliberate narrowing of the original plan, which had a
 * --delete-source flag to remove each legacy option once its migrated value
 * verified. Plugins built against the prototype are actively reading those
 * rows; deleting them is the one irreversible thing this plugin could do to
 * another team's working system, and no amount of verify-before-delete makes
 * that a good trade for a cleanup step an operator can perform explicitly with
 * `wp option delete` if they ever actually want it. Removing the capability
 * outright is a stronger guarantee than guarding it. See
 * the deletion seam documented in secrets-api.php.
 *
 * Re-running is safe: already-migrated keys are reported as skipped rather than
 * rewritten. Read failures (a record that will not decrypt) are reported per key
 * and never abort the run -- one bad key must not block migrating the rest.
 */
final class Secrets_API_Migrator {

	/**
	 * The AI plugin's vendored copy of the prototype's code. Its presence means the
	 * prototype's option rows are live, not historical -- worth telling the
	 * operator, since after migrating, the same credential exists in two places
	 * and the AI plugin will keep reading its own copy.
	 *
	 * No longer gates anything: nothing in this class can affect those rows.
	 *
	 * @var string
	 */
	const VENDORED_AI_PLUGIN_CLASS = 'WordPress\\AI\\Vendor\\Secrets\\Secrets_Manager';

	/**
	 * Migrates legacy secrets into the new format.
	 *
	 * $args accepts:
	 *
	 * - bool   $dry_run   Write nothing; report only. Default false.
	 * - string $name      Migrate only this legacy key. Default null (all).
	 * - array  $map       Legacy key => explicit new name, for keys whose derived
	 *                     name would not validate.
	 * - string $namespace Namespace prefixed onto a legacy key with no entry in
	 *                     $map. Defaults to none, which keeps the key exactly as
	 *                     the prototype spelled it.
	 *
	 * @param array $args Migration options, as described above.
	 *
	 * @return array {
	 *     @type bool  $vendor_detected Whether the vendored AI plugin class exists.
	 *     @type array $entries         One report entry per legacy key, each with
	 *                                  'legacy_key', 'new_name', 'status', 'message',
	 *                                  and, once migrated, 'fingerprint'. Never a
	 *                                  value.
	 * }
	 */
	public function migrate( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'dry_run'   => false,
				'name'      => null,
				'map'       => array(),
				'namespace' => '',
			)
		);

		$reader = new Secrets_API_Legacy_Reader();

		if ( null !== $args['name'] ) {
			$keys = array( $args['name'] );
		} else {
			$keys = $reader->list_keys();

			if ( is_wp_error( $keys ) ) {
				return array(
					'vendor_detected' => class_exists( self::VENDORED_AI_PLUGIN_CLASS ),
					'entries'         => array(
						array(
							'legacy_key' => null,
							'new_name'   => null,
							'status'     => 'error',
							'message'    => $keys->get_error_message(),
						),
					),
				);
			}
		}

		$vendor_detected = class_exists( self::VENDORED_AI_PLUGIN_CLASS );
		$entries         = array();

		foreach ( $keys as $key ) {
			$entries[] = $this->migrate_one( $reader, $key, $args );
		}

		return array(
			'vendor_detected' => $vendor_detected,
			'entries'         => $entries,
		);
	}

	/**
	 * Resolves a single legacy key's new name and copies it across if that has not
	 * already happened. Never touches the source.
	 *
	 * @param Secrets_API_Legacy_Reader $reader The legacy reader.
	 * @param string                    $key    Legacy key.
	 * @param array                     $args   Resolved args from migrate().
	 *
	 * @return array One report entry.
	 */
	private function migrate_one( $reader, $key, array $args ) {
		$new_name = $this->new_name_for( $key, $args );

		$entry = array(
			'legacy_key' => $key,
			'new_name'   => $new_name,
			'status'     => null,
			'message'    => '',
		);

		$name_check = wp_secrets_validate_name( $new_name );

		if ( is_wp_error( $name_check ) ) {
			$entry['status']  = 'needs_mapping';
			$entry['message'] = sprintf(
				/* translators: 1: Derived name. 2: Validation error message. 3: Legacy key. */
				__( 'Derived name "%1$s" is invalid (%2$s). Use --map=%3$s:<new-name> to specify one explicitly.', 'secrets-api' ),
				$new_name,
				$name_check->get_error_message(),
				$key
			);

			return $entry;
		}

		$already_migrated = $this->has_current_record( $new_name );

		if ( is_wp_error( $already_migrated ) ) {
			$entry['status']  = 'error';
			$entry['message'] = $already_migrated->get_error_message();

			return $entry;
		}

		if ( $args['dry_run'] ) {
			$entry['status'] = $already_migrated
				? 'skipped'
				: 'would_migrate';

			return $entry;
		}

		if ( $already_migrated ) {
			$entry['status'] = 'skipped';
		} else {
			$write_result = $this->write_new_secret( $reader, $key, $new_name );

			if ( is_wp_error( $write_result ) ) {
				$entry['status']  = 'error';
				$entry['message'] = $write_result->get_error_message();

				return $entry;
			}

			$entry['status']      = 'migrated';
			$entry['fingerprint'] = $write_result;
		}

		return $entry;
	}

	/**
	 * Resolves the new-format name a prototype key migrates to.
	 *
	 * Defaults to the key unchanged, with no namespace. That deliberately matches
	 * what Secrets_API_Prototype_Fallback_Store does on a read: if this method
	 * prefixed a namespace by default, running the migrator would write
	 * 'legacy/api_key' while a plain wp_get_secret( 'api_key' ) would still miss,
	 * fall through to the untouched prototype row, and upgrade it a second time
	 * into 'api_key'. One prototype secret, two current-format copies, diverging
	 * from the moment either is rotated.
	 *
	 * --namespace and --map both remain available for an operator who wants the
	 * migrated secret somewhere specific, which is a deliberate choice rather
	 * than a silent default.
	 *
	 * @param string $key  Prototype key.
	 * @param array  $args Resolved args from migrate().
	 *
	 * @return string
	 */
	private function new_name_for( $key, array $args ) {
		if ( isset( $args['map'][ $key ] ) ) {
			return $args['map'][ $key ];
		}

		if ( '' === $args['namespace'] ) {
			return $key;
		}

		return $args['namespace'] . '/' . $key;
	}

	/**
	 * Whether a current-format record already exists for a name.
	 *
	 * Deliberately not wp_get_secret(): with the read-time fallback store active,
	 * that call is itself an upgrade trigger, so using it here would report every
	 * prototype secret as already migrated and -- worse -- would make --dry-run
	 * write. Where that store is active, ask it to bypass its own fallback; any
	 * other store has no fallback to bypass and can be asked directly.
	 *
	 * @param string $name The secret's namespaced name.
	 *
	 * @return bool|WP_Error
	 */
	private function has_current_record( $name ) {
		$store = _wp_secrets_get_store();

		if ( $store instanceof Secrets_API_Prototype_Fallback_Store ) {
			return $store->has_current_record( $name );
		}

		$record = $store->get( $name, false );

		if ( is_wp_error( $record ) ) {
			return $record;
		}

		return is_array( $record );
	}

	/**
	 * Reads, writes, and independently verifies a single key, in that order. The
	 * plaintext is held only as long as writing and verifying require, then zeroed.
	 *
	 * @param Secrets_API_Legacy_Reader $reader   The legacy reader.
	 * @param string                    $key      Legacy key.
	 * @param string                    $new_name Validated new-format name.
	 *
	 * @return string|WP_Error The new secret's fingerprint on success. WP_Error on
	 *                         failure -- including if verification fails, in which
	 *                         case the new-format write may still have happened,
	 *                         but is reported as an error rather than a success.
	 */
	private function write_new_secret( $reader, $key, $new_name ) {
		$plaintext = $reader->get( $key );

		if ( is_wp_error( $plaintext ) ) {
			return $plaintext;
		}

		$master_key = _wp_secrets_get_key_manager()->get_master_key( 'site' );

		if ( is_wp_error( $master_key ) ) {
			wp_secrets_memzero( $plaintext );

			return $master_key;
		}

		// Fingerprint of the OLD value, computed before any write, so the
		// comparison below is against something recorded independently of
		// whatever the write path itself might report.
		$expected_fingerprint = ( new WP_Secrets_Cipher() )->fingerprint( $master_key, $plaintext );

		wp_secrets_memzero( $master_key );

		if ( is_wp_error( $expected_fingerprint ) ) {
			wp_secrets_memzero( $plaintext );

			return $expected_fingerprint;
		}

		$write_result = _wp_secrets_set( $new_name, $plaintext, false, true, 'imported' );

		wp_secrets_memzero( $plaintext );

		if ( is_wp_error( $write_result ) ) {
			return $write_result;
		}

		$new_secret = wp_get_secret( $new_name );

		if ( ! ( $new_secret instanceof WP_Secret ) ) {
			return new WP_Error(
				'legacy_migration_verify_failed',
				__( 'The migrated secret could not be read back for verification.', 'secrets-api' )
			);
		}

		if ( $expected_fingerprint !== $new_secret->fingerprint() ) {
			return new WP_Error(
				'legacy_migration_verify_failed',
				__( 'The migrated value does not match the legacy source.', 'secrets-api' )
			);
		}

		return $new_secret->fingerprint();
	}
}
