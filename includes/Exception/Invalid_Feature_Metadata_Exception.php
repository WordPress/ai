<?php
/**
 * Exception for invalid feature metadata.
 *
 * @package WordPress\AI\Exception
 */

declare( strict_types=1 );

namespace WordPress\AI\Exception;

use InvalidArgumentException;

/**
 * Exception thrown when feature metadata is invalid.
 *
 * @since 0.1.0
 */
class Invalid_Feature_Metadata_Exception extends InvalidArgumentException {

}
