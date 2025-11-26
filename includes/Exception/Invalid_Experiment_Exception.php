<?php
/**
 * Exception for invalid experiment.
 *
 * @package WordPress\AI\Exception
 */

declare( strict_types=1 );

namespace WordPress\AI\Exception;

use InvalidArgumentException;

/**
 * Exception thrown when an experiment is invalid.
 *
 * @since 0.1.0
 */
class Invalid_Experiment_Exception extends InvalidArgumentException {

}
