<?php
/**
 * Secrets API: prototype-format fallback store (plugin-only, never copied to core)
 *
 * @package SecretsAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves a read whose new-format record does not exist yet from the prototype's
 * option row instead, upgrading it to the current format on the way through.
 *
 * The problem this solves is adoption, not compatibility. The AI plugin was built
 * on the prototype, so its sites have credentials sitting in prototype-format
 * rows. When that plugin moves to the Secrets API, every one of those sites would
 * otherwise see wp_get_secret() return null for a credential it demonstrably has,
 * and would need an explicit migration run -- per site -- before working again.
 * Sites that nobody remembers to migrate would simply break, and a credential that
 * cannot be re-entered from memory means a rebuild.
 *
 * So instead: a miss falls through to the prototype row, the value is re-encrypted
 * into a proper current-format record, and the caller gets a normal WP_Secret. The
 * next read hits the new record directly and never comes back here. The upgrade is
 * one-way and happens once per secret, on first use.
 *
 * Only the unnamespaced form participates. wp_get_secret( 'api_key' ) consults the
 * prototype's 'api_key'; wp_get_secret( 'myplugin/api_key' ) consults nothing,
 * because the prototype had no namespaces and so cannot have owned that name.
 * Nothing is rewritten or inferred -- see prototype_key_for().
 *
 * A note on what this is NOT. It does not implement the prototype's API, reinstate
 * its function names, or let prototype-era code keep running -- that would be a
 * compatibility layer, and there is deliberately none. It reads one option row,
 * once, and never writes to or deletes anything the prototype owns. Both systems
 * keep working on the same site throughout, because the two option namespaces do
 * not overlap.
 *
 * ---
 *
 * Implemented as a decorator around whatever store would otherwise be active,
 * rather than as changes to WP_Secrets_Option_Store, for two reasons. Core has no
 * business knowing this format ever existed, and src/ stays a clean file-copy
 * candidate. And it composes: this wraps the default option store, and a
 * secrets.php drop-in that installs a host store replaces it entirely, which is
 * the right outcome -- a host serving secrets from its own platform has no
 * prototype rows to inherit.
 */
final class Secrets_API_Prototype_Fallback_Store implements WP_Secrets_Store {

	/**
	 * The store actually holding current-format records.
	 *
	 * @var WP_Secrets_Store
	 */
	private $inner;

	/**
	 * Names currently mid-upgrade, guarding against re-entry.
	 *
	 * The upgrade writes through _wp_secrets_set(), which reads the prior record
	 * first -- landing back in this class's own get() for the same name. Without
	 * this, that second miss would start another upgrade, and so on.
	 *
	 * @var array<string, bool>
	 */
	private $upgrading = array();

	/**
	 * Wraps an existing store.
	 *
	 * @param WP_Secrets_Store $inner The store to delegate to.
	 */
	public function __construct( WP_Secrets_Store $inner ) {
		$this->inner = $inner;
	}

	/**
	 * Reads a record, falling back to the prototype format on a miss.
	 *
	 * @param string $name    The secret's namespaced name.
	 * @param bool   $network Whether this is a network-scope secret.
	 *
	 * @return array|null|WP_Error
	 */
	public function get( $name, $network = false ) {
		$record = $this->inner->get( $name, $network );

		// Only an outright miss is a candidate. A WP_Error means the store is
		// unhealthy, and quietly answering from somewhere else would turn a
		// fail-closed read into a fail-open one.
		if ( null !== $record ) {
			return $record;
		}

		// The prototype had no network scope, so there is nothing to inherit.
		if ( $network ) {
			return null;
		}

		if ( isset( $this->upgrading[ $name ] ) ) {
			return null;
		}

		return $this->upgrade_from_prototype( $name );
	}

	/**
	 * Reads the prototype row behind a name, writes it into the current format,
	 * and returns the resulting record.
	 *
	 * Failure at any step returns null -- the same answer as the miss that got us
	 * here. A site with no prototype rows, an unreadable prototype row, or a
	 * read-only store all end up reporting the secret as absent, which is what it
	 * is as far as the current format is concerned.
	 *
	 * @param string $name The secret's namespaced name.
	 *
	 * @return array|null
	 */
	private function upgrade_from_prototype( $name ) {
		$prototype_key = $this->prototype_key_for( $name );

		if ( null === $prototype_key ) {
			return null;
		}

		$plaintext = ( new Secrets_API_Legacy_Reader() )->get( $prototype_key );

		if ( is_wp_error( $plaintext ) ) {
			return null;
		}

		$this->upgrading[ $name ] = true;

		/*
		 * Written through the normal set path rather than assembled here: it
		 * derives the master key, binds the AAD, and fires wp_secret_changed with
		 * the 'imported' action, so an upgrade that happens silently at read time
		 * is still visible to anything auditing secret changes. Flagged
		 * needs_rotation for the same reason wp_import_option_as_secret() does --
		 * this credential has been sitting in the prototype's format, and
		 * re-encrypting it now does not undo wherever it has already been.
		 */
		$result = _wp_secrets_set( $name, $plaintext, false, true, 'imported' );

		wp_secrets_memzero( $plaintext );

		unset( $this->upgrading[ $name ] );

		if ( is_wp_error( $result ) ) {
			return null;
		}

		return $this->inner->get( $name, false );
	}

	/**
	 * Whether a current-format record already exists, without the fallback.
	 *
	 * Ordinary callers want the fallback -- that is the entire point. The migrator
	 * does not: it needs to report whether a secret was already in the current
	 * format, and asking through get() would upgrade the very thing it is trying
	 * to describe. That is not just a cosmetic reporting problem; it would make
	 * `--dry-run` write.
	 *
	 * @param string $name The secret's namespaced name.
	 *
	 * @return bool
	 */
	public function has_current_record( $name ) {
		return is_array( $this->inner->get( $name, false ) );
	}

	/**
	 * Maps a name onto the prototype key that would have held it, which is only
	 * ever the identical name.
	 *
	 * The prototype's keyspace is flat, so an unnamespaced name corresponds to
	 * exactly one prototype key and a namespaced one corresponds to none at all.
	 * There is no rewriting here: 'api_key' looks at 'api_key', and
	 * 'myplugin/api_key' looks at nothing.
	 *
	 * An earlier version dropped the namespace instead, so that
	 * wp_get_secret( 'anything/api_key' ) inherited the prototype's 'api_key'.
	 * That worked, but it meant any namespace could silently claim any prototype
	 * row, and a caller had no way to tell which of its names were quietly wired
	 * to prototype data. Making the unnamespaced form a legal call
	 * (wp_secrets_validate_name() accepts it and reports through
	 * _doing_it_wrong()) removes the need for the rewrite entirely: code being
	 * ported off the prototype passes the same key it always passed, and gets the
	 * value it always got, with nothing inferred on its behalf.
	 *
	 * @param string $name The secret's name.
	 *
	 * @return string|null The prototype key, or null if this name can never
	 *                      correspond to one.
	 */
	private function prototype_key_for( $name ) {
		if ( ! is_string( $name ) || '' === $name ) {
			return null;
		}

		/*
		 * The prototype's keyspace allowed namespaced keys, and its own documentation
		 * used them, so a row like _secret_my-plugin/api_key can exist. This still
		 * refuses to map one: guessing which prototype row a namespaced name refers to
		 * is what an earlier revision did, and it let any namespace claim any row.
		 * Namespaced prototype keys are migrated explicitly, by wp secret
		 * migrate-legacy, rather than picked up implicitly here.
		 */
		if ( false !== strpos( $name, '/' ) ) {
			return null;
		}

		return $name;
	}

	/**
	 * Writes a current-format record. Delegated unchanged.
	 *
	 * @param string $name    The secret's namespaced name.
	 * @param array  $record  The record to store.
	 * @param bool   $network Whether this is a network-scope secret.
	 *
	 * @return bool|WP_Error
	 */
	public function set( $name, $record, $network = false ) {
		return $this->inner->set( $name, $record, $network );
	}

	/**
	 * Deletes a current-format record. The prototype's row is left alone, so a
	 * delete followed by a read will inherit the prototype value again rather than
	 * reporting absence -- the same answer the site would have given before the
	 * Secrets API was installed at all.
	 *
	 * @param string $name    The secret's namespaced name.
	 * @param bool   $network Whether this is a network-scope secret.
	 *
	 * @return bool|WP_Error
	 */
	public function delete( $name, $network = false ) {
		return $this->inner->delete( $name, $network );
	}

	/**
	 * Lists current-format names only. Prototype rows that have not been read yet
	 * are not listed: they are not secrets of this API until something asks for
	 * one by name. `wp secret migrate-legacy --dry-run` is the way to see what is
	 * still sitting in the old format.
	 *
	 * @param bool $network Whether to list network-scope secrets.
	 *
	 * @return array|WP_Error
	 */
	public function list_names( $network = false ) {
		return $this->inner->list_names( $network );
	}
}
