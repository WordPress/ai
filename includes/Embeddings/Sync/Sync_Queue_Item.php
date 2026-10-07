<?php
/**
 * A claimed row of the embedding sync queue.
 *
 * @package WordPress\AI\Embeddings\Sync
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable snapshot of one queue row as the worker read it.
 *
 * @since x.x.x
 */
final class Sync_Queue_Item {

	/**
	 * Row ID.
	 *
	 * @var int
	 */
	private int $id;

	/**
	 * Object type.
	 *
	 * @var string
	 */
	private string $object_type;

	/**
	 * Object ID.
	 *
	 * @var int
	 */
	private int $object_id;

	/**
	 * Row version when read.
	 *
	 * @var int
	 */
	private int $version;

	/**
	 * Attempts already made.
	 *
	 * @var int
	 */
	private int $attempts;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param int    $id          Row ID.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @param int    $version     Row version when read.
	 * @param int    $attempts    Attempts already made.
	 */
	public function __construct( int $id, string $object_type, int $object_id, int $version, int $attempts ) {
		$this->id          = $id;
		$this->object_type = $object_type;
		$this->object_id   = $object_id;
		$this->version     = $version;
		$this->attempts    = $attempts;
	}

	/**
	 * Creates an item from a database row.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $row Row with id, object_type, object_id, version and attempts.
	 * @return self The item.
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) $row['id'],
			(string) $row['object_type'],
			(int) $row['object_id'],
			(int) $row['version'],
			(int) $row['attempts']
		);
	}

	/**
	 * Returns the row ID.
	 *
	 * @since x.x.x
	 *
	 * @return int The row ID.
	 */
	public function get_id(): int {
		return $this->id;
	}

	/**
	 * Returns the object type.
	 *
	 * @since x.x.x
	 *
	 * @return string The object type.
	 */
	public function get_object_type(): string {
		return $this->object_type;
	}

	/**
	 * Returns the object ID.
	 *
	 * @since x.x.x
	 *
	 * @return int The object ID.
	 */
	public function get_object_id(): int {
		return $this->object_id;
	}

	/**
	 * Returns the row version when read.
	 *
	 * @since x.x.x
	 *
	 * @return int The version.
	 */
	public function get_version(): int {
		return $this->version;
	}

	/**
	 * Returns the attempts already made.
	 *
	 * @since x.x.x
	 *
	 * @return int The attempt count.
	 */
	public function get_attempts(): int {
		return $this->attempts;
	}
}
