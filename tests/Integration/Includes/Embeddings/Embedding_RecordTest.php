<?php
/**
 * Tests for the embedding record value object.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embeddings
 */

namespace WordPress\AI\Tests\Integration\Includes\Embeddings;

use InvalidArgumentException;
use WP_UnitTestCase;
use WordPress\AI\Embeddings\Embedding_Record;

/**
 * Embedding_Record test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Embeddings\Embedding_Record
 */
class Embedding_RecordTest extends WP_UnitTestCase {

	/**
	 * Tests that a record exposes its fields, normalizing identity strings and vector values.
	 *
	 * @since x.x.x
	 */
	public function test_getters(): void {
		$record = new Embedding_Record( ' post ', 7, ' ollama ', ' nomic-embed-text:latest ', array( 1, 0.5 ), 2, 'abc123', 9 );

		$this->assertSame( 9, $record->get_id() );
		$this->assertSame( 'post', $record->get_object_type() );
		$this->assertSame( 7, $record->get_object_id() );
		$this->assertSame( 2, $record->get_chunk_index() );
		$this->assertSame( 'ollama', $record->get_provider() );
		$this->assertSame( 'nomic-embed-text:latest', $record->get_model() );
		$this->assertSame( array( 1.0, 0.5 ), $record->get_vector() );
		$this->assertSame( 2, $record->get_dimensions() );
		$this->assertSame( 'abc123', $record->get_content_hash() );
	}

	/**
	 * Tests the defaults for optional fields.
	 *
	 * @since x.x.x
	 */
	public function test_defaults(): void {
		$record = new Embedding_Record( 'post', 1, 'google', 'gemini-embedding-001', array( 0.1 ) );

		$this->assertSame( 0, $record->get_id() );
		$this->assertSame( 0, $record->get_chunk_index() );
		$this->assertSame( '', $record->get_content_hash() );
	}

	/**
	 * Tests the model identity check.
	 *
	 * @since x.x.x
	 */
	public function test_is_from_model(): void {
		$record = new Embedding_Record( 'post', 1, 'ollama', 'nomic-embed-text:latest', array( 0.1 ) );

		$this->assertTrue( $record->is_from_model( 'ollama', 'nomic-embed-text:latest' ) );
		$this->assertFalse( $record->is_from_model( 'ollama', 'mxbai-embed-large' ) );
		$this->assertFalse( $record->is_from_model( 'google', 'nomic-embed-text:latest' ) );
	}

	/**
	 * Tests that with_id() returns a copy and leaves the original untouched.
	 *
	 * @since x.x.x
	 */
	public function test_with_id_returns_copy(): void {
		$record = new Embedding_Record( 'post', 1, 'ollama', 'nomic-embed-text:latest', array( 0.1 ) );
		$copy   = $record->with_id( 42 );

		$this->assertNotSame( $record, $copy );
		$this->assertSame( 0, $record->get_id() );
		$this->assertSame( 42, $copy->get_id() );
		$this->assertSame( $record->get_vector(), $copy->get_vector() );
	}

	/**
	 * Tests that invalid identity fields and vectors are rejected.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_invalid_records
	 *
	 * @param array<int, mixed> $args Constructor arguments.
	 */
	public function test_rejects_invalid_input( array $args ): void {
		$this->expectException( InvalidArgumentException::class );

		new Embedding_Record( ...$args );
	}

	/**
	 * Provides invalid constructor arguments.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: array<int, mixed>}> Test cases.
	 */
	public function data_invalid_records(): array {
		$vector = array( 0.1, 0.2 );

		return array(
			'empty object type'    => array( array( '', 1, 'ollama', 'model', $vector ) ),
			'long object type'     => array( array( str_repeat( 'a', 33 ), 1, 'ollama', 'model', $vector ) ),
			'zero object id'       => array( array( 'post', 0, 'ollama', 'model', $vector ) ),
			'negative object id'   => array( array( 'post', -5, 'ollama', 'model', $vector ) ),
			'empty provider'       => array( array( 'post', 1, ' ', 'model', $vector ) ),
			'long provider'        => array( array( 'post', 1, str_repeat( 'p', 65 ), 'model', $vector ) ),
			'empty model'          => array( array( 'post', 1, 'ollama', '', $vector ) ),
			'long model'           => array( array( 'post', 1, 'ollama', str_repeat( 'm', 129 ), $vector ) ),
			'negative chunk index' => array( array( 'post', 1, 'ollama', 'model', $vector, -1 ) ),
			'long content hash'    => array( array( 'post', 1, 'ollama', 'model', $vector, 0, str_repeat( 'h', 65 ) ) ),
			'empty vector'         => array( array( 'post', 1, 'ollama', 'model', array() ) ),
			'non-numeric vector'   => array( array( 'post', 1, 'ollama', 'model', array( 'a' ) ) ),
		);
	}
}
