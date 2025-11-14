<?php
/**
 * Exception for invalid experiment metadata.
 *
 * @package WordPress\AI\Exception
 */

declare( strict_types=1 );

namespace WordPress\AI\Exception;

use InvalidArgumentException;

/**
 * Exception thrown when experiment metadata is invalid.
 *
 * @since 0.1.0
 */
class Invalid_Experiment_Metadata_Exception extends InvalidArgumentException {

}
