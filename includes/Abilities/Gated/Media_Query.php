<?php
/**
 * Gated ability: media query, upload, update, and delete.
 *
 * @package WordPress\AI\Abilities\Gated
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Gated;

use WordPress\AI\Abilities\Media\Media as Media_Ability;
use WordPress\AI\Abstracts\Abstract_Gated_Ability;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Gates the media abilities: core/media-query, core/media-upload, core/media-update, and
 * core/media-delete.
 *
 * @since x.x.x
 */
final class Media_Query extends Abstract_Gated_Ability {
	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		( new Media_Ability() )->init();
	}
}
