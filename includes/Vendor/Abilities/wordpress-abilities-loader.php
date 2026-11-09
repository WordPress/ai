<?php
/**
 * Optional multi-copy arbitration loader for the `wordpress/abilities` package.
 *
 * WordPress plugins commonly bundle Composer libraries inside their own
 * `vendor/` directory. When several active plugins each bundle
 * `wordpress/abilities`, plain autoloading is an FQCN race: whichever copy's
 * autoloader happens to run first defines the package's classes for the
 * whole process. Worst case, a consumer references an ability class that its
 * own copy contains while an OLDER copy won the race and lacks the file — a
 * fatal "class not found" for an up-to-date consumer.
 *
 * This file is the optional fix, following the pattern proven by Action
 * Scheduler (https://actionscheduler.org/usage/ — "Action Scheduler will
 * register the version in that file and then load the most recent version
 * of itself on the site"), adapted to a pure library:
 *
 *  - Every bundled copy of the package ships this identical file; it ships
 *    in the dist but is NEVER registered in composer autoload. A plugin
 *    that bundles the package requires the loader of ITS OWN copy at plugin
 *    file scope — before any use of the package's classes — and registers
 *    that copy:
 *
 *        wordpress_abilities_register( '0.1.0', __DIR__ . '/vendor/wordpress/abilities' );
 *
 *  - All registrations are recorded, and ONE spl autoloader serves the
 *    `WordPress\Abilities\` PSR-4 prefix from the NEWEST registered copy.
 *    Whichever copy's loader file is required first defines the functions;
 *    later copies requiring the same file are a no-op. That is safe only
 *    because the function bodies are a frozen public contract (below).
 *
 *  - Arbitration is LAZY: it resolves at the first class resolution, not at
 *    registration time. A library cannot assume WordPress hooks exist when
 *    its file is included (unlike Action Scheduler, which arbitrates on
 *    plugins_loaded), and lazy arbitration means copies whose loads arrive
 *    after another copy's include but before any class use still register
 *    and still participate. Once a class has actually loaded, PHP has
 *    defined it: the winner can no longer be changed, so later
 *    registrations are ignored, reported with a diagnostic notice
 *    (WordPress `_doing_it_wrong` where available, otherwise a plain
 *    `trigger_error` notice).
 *
 * === THE FROZEN FUNCTION CONTRACT ======================================
 *
 * `wordpress_abilities_register()`'s body is a frozen public contract; its
 * behavior must remain identical across all package versions. The same
 * applies to `wordpress_abilities_loaded_version()` and to the internal
 * helpers in this file, down to the guard below. Action Scheduler's
 * versioned `action_scheduler_register_3_x()` functions are the precedent:
 * whichever copy wins the "who defines the functions" race, every package
 * version defines them identically, so the only observable difference is
 * that the newest copy's src/ serves the classes.
 * =======================================================================
 *
 * The file works with or without WordPress. Strings are deliberately not
 * wrapped in translation functions (diagnostics only) so the file stays
 * self-contained in non-WP contexts. PHP 7.4-safe throughout (the package
 * floor). No side effects at include time: functions are defined, nothing
 * else runs.
 *
 * @package WordPress\Abilities
 */

if ( ! function_exists( 'wordpress_abilities_register' ) ) {

	/**
	 * Registers one bundled copy of the package for newest-wins arbitration.
	 *
	 * This function's body is a frozen public contract; its behavior must
	 * remain identical across all package versions.
	 *
	 * Call it once per bundled copy, when the consumer loads its code and
	 * before the first resolution of any `WordPress\Abilities\` class. The
	 * version argument is consumer-declared (a copy cannot read its own
	 * version before its classes load); the registered copies arbitrate
	 * lazily, at the first class resolution, and the newest version wins
	 * (`version_compare` semantics; the first registration of an equal
	 * version wins the tie). An invalid registration is skipped, reported
	 * through WordPress `_doing_it_wrong()` where available and a plain
	 * `trigger_error()` notice otherwise. Registering after the package has
	 * already booted (a class resolved through the arbitrator) is ignored
	 * with the same kind of notice: PHP has already fixed which files
	 * define the classes.
	 *
	 * @since 0.1.0
	 *
	 * @param string $version      Version of the copy being registered, as the consuming plugin declares it (e.g. the bundled copy's Manifest::VERSION).
	 * @param string $package_root Absolute path to the copy's package root: the directory that contains its src/ directory.
	 * @return void
	 */
	function wordpress_abilities_register( string $version, string $package_root ): void {
		// A class has already loaded through the arbitrator: PHP has
		// defined it, so registrations can no longer change which copy
		// serves the prefix. Ignore loudly, keeping the winner visible.
		$boot = _wordpress_abilities_state( 'boot-get' );
		if ( is_array( $boot ) ) {
			_wordpress_abilities_notice(
				sprintf(
					'The package already booted from version %1$s; the version %2$s registration was ignored.',
					$boot['version'],
					$version
				)
			);

			return;
		}

		// Validate the copy's package root. An unusable candidate is
		// reported and skipped; other candidates are unaffected.
		$package_root = rtrim( $package_root, '/\\' );
		$src          = $package_root . '/src';
		if ( '' === $version || '' === $package_root || ! is_dir( $src ) ) {
			_wordpress_abilities_notice(
				sprintf(
					'A registration for version %1$s was skipped: the package root %2$s does not contain a src directory, or the version string is empty.',
					$version,
					$package_root
				)
			);

			return;
		}

		// Record the candidate. Arbitration itself is deferred to the first
		// class resolution, so copies included later can still participate.
		_wordpress_abilities_state(
			'candidate-add',
			array(
				'version' => $version,
				'src'     => $src,
			)
		);

		// ONE autoloader serves every registered copy; register it once.
		if ( ! _wordpress_abilities_state( 'autoload-get' ) ) {
			_wordpress_abilities_state( 'autoload-set', true );
			spl_autoload_register( '_wordpress_abilities_autoload' );
		}
	}

	/**
	 * Returns the version the arbitrator selected, or null before arbitration.
	 *
	 * This function's body is a frozen public contract; its behavior must
	 * remain identical across all package versions.
	 *
	 * Null is returned until a `WordPress\Abilities\` class has actually
	 * been resolved through the arbitrator (lazy arbitration). Afterwards,
	 * the winning copy's version is returned — cheap diagnostics for site
	 * owners and support flows asking "which copy am I running?".
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The winning version once the arbitrator has resolved; null before that.
	 */
	function wordpress_abilities_loaded_version(): ?string {
		$boot = _wordpress_abilities_state( 'boot-get' );

		return is_array( $boot ) ? $boot['version'] : null;
	}

	/**
	 * PSR-4 autoloader serving all registered copies of the package.
	 *
	 * The first resolution of a `WordPress\Abilities\` class triggers the
	 * deferred arbitration; every resolution afterwards maps to the winning
	 * copy's src/ directory. Classes outside the prefix are not the
	 * package's and are ignored here. When arbitration finds no usable
	 * candidate, this returns without loading anything: PHP then reports
	 * the missing class through its own usual error handling, and the boot
	 * state stays open for valid registrations that may still arrive.
	 *
	 * @internal Part of the frozen contract implementation; behavior must remain identical across all package versions.
	 *
	 * @param string $class_name The class name PHP is resolving.
	 * @return void
	 */
	function _wordpress_abilities_autoload( string $class_name ): void {
		$prefix = 'WordPress\\Abilities\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return; // Outside the package's namespace: not ours to serve.
		}

		$boot = _wordpress_abilities_state( 'boot-get' );

		if ( ! is_array( $boot ) ) {
			// First resolution under the prefix: arbitrate now, so that
			// every copy present in the process has had its chance to
			// register first. Newest wins; ties keep the first registered.
			_wordpress_abilities_arbitrate();

			$boot = _wordpress_abilities_state( 'boot-get' );

			if ( ! is_array( $boot ) ) {
				return; // No usable candidate; leave the class undefined.
			}
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = $boot['src'] . '/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_file( $file ) ) {
			require $file;
		}
	}

	/**
	 * Picks the newest registered copy and records it as the boot state.
	 *
	 * Candidates are inspected in registration order: a strictly newer
	 * version (`version_compare`) replaces the current winner, so the first
	 * registration of an equal version wins the tie. When no usable
	 * candidate exists, the boot state stays unset; arbitration may run
	 * again at a later resolution.
	 *
	 * @internal Part of the frozen contract implementation; behavior must remain identical across all package versions.
	 *
	 * @return void
	 */
	function _wordpress_abilities_arbitrate(): void {
		$candidates = _wordpress_abilities_state( 'candidates-get' );
		$winner     = null;

		foreach ( $candidates as $candidate ) {
			if (
				null === $winner
				|| version_compare( $candidate['version'], $winner['version'], '>' )
			) {
				$winner = $candidate;
			}
		}

		if ( null === $winner || ! is_dir( $winner['src'] ) ) {
			return;
		}

		_wordpress_abilities_state( 'boot-set', $winner );
	}

	/**
	 * State storage for the arbitration loader.
	 *
	 * All loader state lives in this function's static variables — there
	 * are no globals, no classes, no side effects at include time.
	 *
	 * Accepted operations:
	 *  - 'candidates-get' => array   Returns candidates in registration order.
	 *  - 'candidate-add'  => bool    Appends one candidate array.
	 *  - 'autoload-get'   => bool    Whether the spl autoloader is registered.
	 *  - 'autoload-set'   => bool    Marks the spl autoloader as registered.
	 *  - 'boot-get'       => ?array  Winning candidate once arbitrated, else null.
	 *  - 'boot-set'       => bool    Records the winning candidate.
	 *
	 * @internal Part of the frozen contract implementation; behavior must remain identical across all package versions.
	 *
	 * @param string     $op    Operation name, from the list above.
	 * @param mixed|null $value Value to store, when the operation writes state.
	 * @return mixed The operation's result, described above.
	 */
	function _wordpress_abilities_state( string $op, $value = null ) {
		static $candidates = array();
		static $autoload   = false;
		static $boot       = null;

		switch ( $op ) {
			case 'candidates-get':
				return $candidates;

			case 'candidate-add':
				$candidates[] = $value;

				return true;

			case 'autoload-get':
				return $autoload;

			case 'autoload-set':
				$autoload = (bool) $value;

				return true;

			case 'boot-get':
				return $boot;

			case 'boot-set':
				$boot = $value;

				return true;
		}

		return null;
	}

	/**
	 * Emits a diagnostic notice, in the WordPress style when available.
	 *
	 * Uses `_doing_it_wrong()` in a WordPress context (displayed under
	 * WP_DEBUG, like core diagnostic notices) and a plain `trigger_error()`
	 * user notice otherwise, so the file works in plain PHP processes too.
	 *
	 * @internal Part of the frozen contract implementation; behavior must remain identical across all package versions.
	 *
	 * @param string $message The diagnostic message (without a function prefix).
	 * @return void
	 */
	function _wordpress_abilities_notice( string $message ): void {
		if ( function_exists( '_doing_it_wrong' ) ) {
			_doing_it_wrong( 'wordpress_abilities_register', $message, '0.1.0' );

			return;
		}

		trigger_error( 'wordpress_abilities_register: ' . $message, E_USER_NOTICE );
	}
}