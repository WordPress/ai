<?php
/**
 * Canonical WordPress abilities: opt-in ability registration.
 *
 * @package WordPress\Abilities
 */
declare( strict_types = 1 );

namespace WordPress\Abilities;

use ReflectionMethod;
use WordPress\Abilities\Support\Features;

/**
 * Registers the package's manifest abilities with the Abilities API.
 *
 * The package never ships the Abilities API and never bootstraps itself.
 * A consumer decides whether and when to register, on its own gating and
 * timing. The Registrar is idempotent, timing-aware, version-gated, and
 * stamps provenance meta; every gate skips silently on failure. It is safe
 * for multiple consumers, or a stale bundle copy, to call it again: another
 * consumer's autoloader may have loaded a newer copy than the caller's, so
 * nothing here assumes exact-package-version equality.
 *
 * The one thing the Registrar writes into the core registry besides the
 * manifest abilities are the categories the manifest declares: it registers
 * the `category` of every manifest entry itself (on the categories init
 * action, {@see queue_category_declarations()}), so a pre-7.2 host API —
 * whose only ability categories are `site` and `user` — can accept the
 * package's registrations without the consumer backfilling categories first.
 * That declaration is a guarded no-op wherever the host already provides the
 * category (7.2+ core, a trunk-snapshot API, or the consumer itself), and a
 * consumer filter can decline the package's declarations entirely:
 * {@see CATEGORIES_FILTER}.
 */
final class Registrar {

	/**
	 * Whether a deferred registration hook was already queued.
	 *
	 * @var bool
	 */
	private static $hooked = false;

	/**
	 * Whether the category declaring hook was already queued.
	 *
	 * @var bool
	 */
	private static $categories_hooked = false;

	/**
	 * Opt-out filter for the Registrar's category declarations.
	 *
	 * Applies to the package's declaration of the categories the manifest
	 * requires, in full: returning false means the consumer owns every
	 * category registration itself (core's default categories included), and
	 * the Registrar never calls wp_register_ability_category(). The filter is
	 * consulted when the declaring hook runs, so it may be toggled at any
	 * point before the categories action fires.
	 */
	private const CATEGORIES_FILTER = 'wordpress_abilities_declare_categories';

	/**
	 * Abilities this process has registered, keyed by name.
	 *
	 * @var array<string, true>
	 */
	private static $registered = array();

	/**
	 * Deferred single-ability registrations already queued, keyed by name.
	 *
	 * @var array<string, true>
	 */
	private static $deferred = array();

	/**
	 * Whether the support-feature preload hook was already queued.
	 *
	 * @var bool
	 */
	private static $preload_hooked = false;

	/**
	 * Deferred registrations whose support features to preload: name => selection policy.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static $preloads = array();

	/**
	 * Static-only class.
	 */
	private function __construct() {}

	/**
	 * Registers all eligible manifest abilities.
	 *
	 * Safe to call before or after the `wp_abilities_api_init` action: when
	 * the action has fired, registration happens immediately; otherwise it
	 * is deferred to the action, guarded by a static flag so repeated calls
	 * queue it only once. Double-consumption by two host plugins is safe.
	 *
	 * Also queues the Registrar-owned declarations of the manifest-declared
	 * ability categories at priority 20 on `wp_abilities_api_categories_init`
	 * (after core's own category registrar): the categories must be present
	 * before the abilities register, or Gate 8 rejects them. See
	 * queue_category_declarations() for the timing and the opt-out filter.
	 *
	 * On hosts whose API does not provide the manifest categories
	 * (WordPress < 7.2), that also means the first call must precede the
	 * registry boot: categories can only be declared while the categories
	 * action fires, which happens before `wp_abilities_api_init`.
	 *
	 * @param array<string, mixed> $args {
	 *     Optional. Consumer-side selection policy.
	 *
	 *     @type bool           $public_only Optional. Register only abilities
	 *                                        whose `public` meta is true, for
	 *                                        MCP-tool style exposure. Default false.
	 *     @type callable|null  $filter      Optional. Selection policy as
	 *                                        `fn( string $name, array $entry ): bool`;
	 *                                        return false to skip an ability. Core-provided
	 *                                        names resolve before the filter runs, so the
	 *                                        filter can never veto them.
	 * }
	 * @return void
	 */
	public static function register_all( array $args = array() ): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: %s: package name. */
					__( 'The Abilities API was not found when the %s package attempted to register abilities. The API is provided by WordPress 6.9 or later, or must be loaded by the consuming plugin before registration. The package never ships the API itself.' ),
					'wordpress/abilities'
				),
				'6.9.0'
			);
			return;
		}

		// P8: also queue the manifest-category declarations; see the method's
		// docblock for the both-timings contract (this call is a no-op once the
		// categories window has fired).
		self::queue_category_declarations();

		if ( did_action( 'wp_abilities_api_init' ) ) {
			self::register_eligible( $args );
			return;
		}

		self::queue_feature_preloads( Manifest::names(), $args );

		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;

		add_action(
			'wp_abilities_api_init',
			static function () use ( $args ): void {
				self::register_eligible( $args );
			}
		);
	}

	/**
	 * Registers a single manifest ability by name.
	 *
	 * Also queues the Registrar-owned category declarations for the manifest
	 * (see register_all()), so a single-ability consumer still gets the
	 * category its ability needs.
	 *
	 * Also timing-aware: when the API action has not fired yet, the
	 * registration is deferred to it, guarded per name so repeated calls
	 * queue it only once, and false is returned for the current call. The return value describes availability, not authorship: true
	 * when the ability is in the registry afterwards, including when core
	 * already provides the same name and the package copy self-skips.
	 *
	 * @param string               $name The manifest ability name.
	 * @param array<string, mixed> $args The same selection policy as register_all(). Optional.
	 * @return bool Whether the ability is available in the registry afterwards,
	 *              or will be when the deferred registration runs.
	 */
	public static function register( string $name, array $args = array() ): bool {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: %s: package name. */
					__( 'The Abilities API was not found when the %s package attempted to register abilities. The API is provided by WordPress 6.9 or later, or must be loaded by the consuming plugin before registration. The package never ships the API itself.' ),
					'wordpress/abilities'
				),
				'6.9.0'
			);
			return false;
		}

		$entry = Manifest::get( $name );
		if ( null === $entry ) {
			return false;
		}

		// P8: a manifest ability may be registered on its own, so the
		// categories it needs must be declared from this entry point too. This
		// call is a no-op once the categories window has fired.
		self::queue_category_declarations();

		if ( did_action( 'wp_abilities_api_init' ) ) {
			return self::try_register( $name, $entry, $args );
		}

		self::queue_feature_preloads( array( $name ), $args );

		if ( isset( self::$deferred[ $name ] ) ) {
			return false;
		}
		self::$deferred[ $name ] = true;

		add_action(
			'wp_abilities_api_init',
			static function () use ( $name, $entry, $args ): void {
				self::try_register( $name, $entry, $args );
			}
		);

		return false;
	}

	/**
	 * Reports whether a manifest ability is registered.
	 *
	 * True when this process registered the ability, or when the ability
	 * already exists in the core registry under the same name (core itself
	 * provides the backported trio on 7.2+). Before the API action fires,
	 * only in-process registrations count.
	 *
	 * @param string $name The manifest ability name.
	 * @return bool
	 */
	public static function is_registered( string $name ): bool {
		if ( isset( self::$registered[ $name ] ) ) {
			return true;
		}

		return did_action( 'wp_abilities_api_init' )
			&& function_exists( 'wp_has_ability' )
			&& wp_has_ability( $name );
	}

	/**
	 * Registers every manifest ability that passes the selection policy.
	 *
	 * @param array<string, mixed> $args Consumer-side selection policy, see register_all().
	 * @return void
	 */
	private static function register_eligible( array $args ): void {
		foreach ( Manifest::all() as $name => $entry ) {
			self::try_register( $name, $entry, $args );
		}
	}

	/**
	 * Queues the support-feature preload for deferred registrations.
	 *
	 * Core prepares its support surface at priority 1 of
	 * `wp_abilities_api_init`, before it registers its abilities at the
	 * default priority. The polyfills mirror that timing, so a
	 * site's own changes at priorities 2-9, such as unregistering a core
	 * setting, are not undone when the deferred registration loads them.
	 *
	 * @param list<string>         $names The manifest ability names being deferred.
	 * @param array<string, mixed> $args  Consumer-side selection policy, see register_all().
	 * @return void
	 */
	private static function queue_feature_preloads( array $names, array $args ): void {
		foreach ( $names as $name ) {
			if ( ! isset( self::$preloads[ $name ] ) ) {
				self::$preloads[ $name ] = $args;
			}
		}

		if ( self::$preload_hooked ) {
			return;
		}
		self::$preload_hooked = true;

		add_action(
			'wp_abilities_api_init',
			static function (): void {
				self::preload_features();
			},
			1
		);
	}

	/**
	 * Loads the polyfills of the queued abilities the selection policy keeps.
	 *
	 * @return void
	 */
	private static function preload_features(): void {
		foreach ( self::$preloads as $name => $args ) {
			$entry = Manifest::get( $name );
			if ( null === $entry || ! self::passes_selection( $name, $entry, $args ) ) {
				continue;
			}

			foreach ( (array) $entry['requires'] as $feature ) {
				if ( ! Features::is_available( $feature ) ) {
					Features::load_polyfill( $feature );
				}
			}
		}
	}

	/**
	 * Queues the Registrar-owned declarations of the manifest-declared ability
	 * categories.
	 *
	 * Category declarations are only valid while
	 * doing_action( 'wp_abilities_api_categories_init' ) fires (core's
	 * wp_register_ability_category() says so), and the action fires exactly
	 * once per boot — when the categories registry singleton is first created,
	 * which the abilities registry does first on its own boot, before the
	 * abilities themselves register. So:
	 *
	 * - Called before the categories action fired (the pre-init path both
	 *   entry points take): a callback is hooked at priority 20 — after
	 *   core's own category registration — and declares the manifest
	 *   categories when the action later fires, before the deferred ability
	 *   registrations of the same registry boot run.
	 * - Called after it fired: the declaration is impossible — nothing can be
	 *   registered outside that action — so this is a no-op. Registration
	 *   continues, and Gate 8 makes any resulting missing-category rejection
	 *   loud instead ({@see try_register()}).
	 *
	 * The static flag dedupes the queue per process (mirroring $hooked for
	 * the abilities side); repeated declarations — from multiple consumers,
	 * multiple package copies, or test-harness windows fired again — are kept
	 * idempotent by the declaring callback's own `wp_has_ability_category()`
	 * guarded no-ops, not by this flag.
	 *
	 * @return void
	 */
	private static function queue_category_declarations(): void {
		if ( self::$categories_hooked || did_action( 'wp_abilities_api_categories_init' ) ) {
			return;
		}

		self::$categories_hooked = true;

		add_action(
			'wp_abilities_api_categories_init',
			static function (): void {
				self::declare_categories();
			},
			20
		);
	}

	/**
	 * Declares the manifest-declared ability categories.
	 *
	 * Runs only from the priority-20 callback queued by
	 * queue_category_declarations(), i.e. while the
	 * `wp_abilities_api_categories_init` action fires. For every slug the
	 * manifest requires ({@see Categories::required_slugs()}):
	 *
	 * - The `wp_has_ability_category()` guard means anything that already
	 *   registered the category wins — a 7.2+ host, a trunk-snapshot
	 *   consumer API, the consumer itself, or a second package copy — and
	 *   repeated declarations are silent no-ops rather than the registry's
	 *   already-registered doing-it-wrong.
	 * - A slug with no package definition ({@see Categories::definition()})
	 *   cannot be declared; abilities in it are then rejected loudly at Gate
	 *   8 instead, and ManifestTest pins the manifest/Categories consistency
	 *   at manifest-rule level.
	 * - The opt-out filter (CATEGORIES_FILTER) declines the whole
	 *   declaration, giving the consumer full ownership of category
	 *   registration: the package's first write into the core registry is
	 *   consumer-declinable per decision D5 (registration is opt-in; when
	 *   the package's behaviors run is consumer policy).
	 *
	 * Host API revisions that lack the categories functions entirely declare
	 * nothing (their registry also does not enforce declarations).
	 *
	 * @return void
	 */
	private static function declare_categories(): void {
		if ( ! (bool) apply_filters( self::CATEGORIES_FILTER, true ) ) {
			return;
		}

		if ( ! function_exists( 'wp_register_ability_category' ) || ! function_exists( 'wp_has_ability_category' ) ) {
			return;
		}

		if ( ! doing_action( 'wp_abilities_api_categories_init' ) ) {
			// Cannot happen via the queued callback; guards a stray invocation
			// from tripping the API's own outside-the-action doing-it-wrong.
			return;
		}

		foreach ( Categories::required_slugs() as $category ) {
			// Guarded no-op: the host (or another consumer) already provides it.
			if ( wp_has_ability_category( $category ) ) {
				continue;
			}

			$definition = Categories::definition( $category );
			if ( null === $definition ) {
				// No package definition: the declaration is impossible; Gate 8
				// rejects the dependent abilities loudly instead.
				continue;
			}

			wp_register_ability_category(
				$category,
				array(
					// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- single-sourced in Categories (mirrors core's default registration, text domain default).
					'label'       => __( $definition['label'] ),
					// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- single-sourced in Categories (mirrors core's default registration, text domain default).
					'description' => __( $definition['description'] ),
				)
			);
		}
	}

	/**
	 * Gates and registers one ability.
	 *
	 * The gates run in order and skip silently on failure. The missing API
	 * guard and — since P8 — a Gate-8 rejection whose reason is the missing
	 * ability category log via _doing_it_wrong(); everything else is a
	 * non-event.
	 *
	 * 1. Registered in this process already: idempotence.
	 * 2. `wp_has_ability()`: core already provides the name (the core_since
	 *    mechanism; on 7.2+ the backported trio self-skips). Core-provided
	 *    names resolve as available before loadability and consumer
	 *    selection run, so a consumer `filter` can never veto them.
	 * 3. The ability class must be loadable: a stale bundle copy missing a
	 *    class that the manifest knows simply registers nothing.
	 * 4. Consumer `filter` selection policy.
	 * 5. WordPress version vs. the entry's min_wp_version support floor.
	 * 6. Support-surface `requires`: every feature must be available,
	 *    attempting a polyfill load when one exists (already loaded at
	 *    priority 1 for deferred registrations, see queue_feature_preloads()).
	 * 7. One probe instance, built once those features are in place and only
	 *    when something reads it: `public_only` checks the ability's own
	 *    `public` meta, and an ability that overrides should_register()
	 *    must agree to be registered (core's register() early return, e.g.
	 *    nothing to expose).
	 * 8. Registration via the core `ability_class` mechanism, merging
	 *    provenance meta (stability tier, renamed_from lineage). A null
	 *    result from core when the ability's manifest-declared category is
	 *    not registered — the consumer opted out of the package's category
	 *    declarations, or the categories action fired without declaring it —
	 *    is made loud by a _doing_it_wrong() naming the missing category;
	 *    other nulls (core already logged its own reason) stay silent.
	 *
	 * @param string               $name  The ability name.
	 * @param array<string, mixed> $entry The manifest entry.
	 * @param array<string, mixed> $args  Consumer-side selection policy.
	 * @return bool Whether the ability is available in the registry afterwards.
	 */
	private static function try_register( string $name, array $entry, array $args ): bool {
		// Gate 1: registered in this process already.
		if ( isset( self::$registered[ $name ] ) ) {
			return true;
		}

		// Gate 2: core already provides this ability under the same name.
		// Availability resolves before loadability and consumer selection,
		// so a consumer filter can never veto a core-provided name.
		if ( wp_has_ability( $name ) ) {
			return true;
		}

		// Gates 3-5: loadable class, consumer selection, support floor.
		if ( ! self::passes_selection( $name, $entry, $args ) ) {
			return false;
		}

		// Gate 6: support-surface features, with polyfill attempt.
		foreach ( (array) $entry['requires'] as $feature ) {
			if ( ! Features::is_available( $feature ) && ! Features::load_polyfill( $feature ) ) {
				return false;
			}
		}

		// Gate 7: a single probe, for public-only selection and readiness.
		$public_only = ! empty( $args['public_only'] );
		if ( $public_only || self::overrides_readiness( $entry['class'] ) ) {
			$probe = new $entry['class']();
			if ( $public_only && true !== $probe->get_meta_item( 'public', false ) ) {
				return false;
			}
			if ( ! $probe->should_register() ) {
				return false;
			}
		}

		// Gate 8: register via the core `ability_class` mechanism, merging
		// provenance meta (stability, lineage).
		$meta = array(
			'stability' => $entry['tier'],
		);
		if ( null !== $entry['renamed_from'] ) {
			$meta['renamed_from'] = $entry['renamed_from'];
		}

		$ability = wp_register_ability(
			$name,
			array(
				'ability_class' => $entry['class'],
				'category'      => $entry['category'],
				'meta'          => $meta,
			)
		);

		if ( null === $ability ) {
			/*
			 * Core rejected the registration and logged its own reason (wrong
			 * action, duplicate name, malformed args). The one rejection the
			 * package can diagnose — and must make loud, because its sources are
			 * states the consumer controls — is a missing ability category: the
			 * manifest-declared category never got registered, e.g. the consumer
			 * opted out of the package's declarations via CATEGORIES_FILTER, or
			 * the categories action fired without ever having the Registrar's
			 * declaring hook (see queue_category_declarations()). Name the
			 * missing category instead of returning silently; every other
			 * rejection stays quiet here since core already logged it. That
			 * includes API revisions without the category functions entirely:
			 * their registry does not enforce category declarations, so a null
			 * there is never a category rejection.
			 */
			$category = is_string( $entry['category'] ) ? $entry['category'] : '';
			if ( '' !== $category && function_exists( 'wp_has_ability_category' ) && ! wp_has_ability_category( $category ) ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: %1$s: ability name, %2$s: ability category slug, %3$s: opt-out filter name. */
						__( 'The ability %1$s was not registered because its ability category %2$s is not registered. The package declares its manifest categories itself on the <code>wp_abilities_api_categories_init</code> action at priority 20 — unless the <code>%3$s</code> filter returns false, or the action has already fired (declared categories cannot be added outside of it). Either declare the category yourself during that action, or call the Registrar before the registry boots. The ability was not registered.' ),
						'<code>' . esc_html( $name ) . '</code>',
						'<code>' . esc_html( $category ) . '</code>',
						self::CATEGORIES_FILTER
					),
					'6.9.0'
				);
			}

			return false;
		}

		self::$registered[ $name ] = true;

		return true;
	}

	/**
	 * Gates 3-5: the selection checks that need no ability instance.
	 *
	 * 3. The ability class must be loadable.
	 * 4. Consumer `filter` selection policy.
	 * 5. WordPress version vs. the entry's min_wp_version support floor.
	 *
	 * @param string               $name  The ability name.
	 * @param array<string, mixed> $entry The manifest entry.
	 * @param array<string, mixed> $args  Consumer-side selection policy.
	 * @return bool Whether the ability passes all three.
	 */
	private static function passes_selection( string $name, array $entry, array $args ): bool {
		if ( ! class_exists( $entry['class'] ) ) {
			return false;
		}

		$filter = isset( $args['filter'] ) && is_callable( $args['filter'] ) ? $args['filter'] : null;
		if ( null !== $filter && ! (bool) $filter( $name, $entry ) ) {
			return false;
		}

		return version_compare( get_bloginfo( 'version' ), $entry['min_wp_version'], '>=' );
	}

	/**
	 * Whether an ability class overrides Abstract_Core_Ability::should_register().
	 *
	 * @param class-string $ability_class The ability class.
	 * @return bool True when the class declares its own readiness check.
	 */
	private static function overrides_readiness( string $ability_class ): bool {
		return Abstract_Core_Ability::class !== ( new ReflectionMethod( $ability_class, 'should_register' ) )->class;
	}
}
