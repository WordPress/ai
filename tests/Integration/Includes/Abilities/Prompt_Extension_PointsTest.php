<?php
/**
 * Integration tests for the prompt template extension points.
 *
 * Covers the per-ability filter hooks added in the prompt customization feature:
 * - wpai_{slug}_system_instruction
 * - wpai_{slug}_prompt
 * - wpai_{slug}_prompt_builder
 * and the get_ability_slug() helper on Abstract_Ability.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_UnitTestCase;
use WordPress\AI\Abilities\Comment_Moderation\Comment_Analysis;
use WordPress\AI\Abilities\Content_Classification\Content_Classification;
use WordPress\AI\Abilities\Content_Resizing\Content_Resizing;
use WordPress\AI\Abilities\Editorial_Notes\Editorial_Notes;
use WordPress\AI\Abilities\Editorial_Updates\Editorial_Updates;
use WordPress\AI\Abilities\Excerpt_Generation\Excerpt_Generation;
use WordPress\AI\Abilities\Image\Alt_Text_Generation;
use WordPress\AI\Abilities\Image\Generate_Image;
use WordPress\AI\Abilities\Image\Generate_Image_Prompt;
use WordPress\AI\Abilities\Meta_Description\Meta_Description;
use WordPress\AI\Abilities\Suggest_Reply\Suggest_Reply;
use WordPress\AI\Abilities\Summarization\Summarization;
use WordPress\AI\Abilities\Title_Generation\Title_Generation;
use WordPress\AI\Abilities\Type_Ahead\Type_Ahead;

/**
 * Prompt extension points test case.
 *
 * @since 1.3.0
 */
class Prompt_Extension_PointsTest extends WP_UnitTestCase {

	/**
	 * Title_Generation ability instance.
	 *
	 * @var \WordPress\AI\Abilities\Title_Generation\Title_Generation
	 */
	private $title;

	/**
	 * Meta_Description ability instance.
	 *
	 * @var \WordPress\AI\Abilities\Meta_Description\Meta_Description
	 */
	private $meta;

	/**
	 * Set up test case.
	 *
	 * @since 1.3.0
	 */
	public function setUp(): void {
		parent::setUp();

		$this->title = new Title_Generation(
			'ai/title-generation',
			array(
				'label'       => 'Title Generation',
				'description' => 'Generates title suggestions from content',
			)
		);

		$this->meta = new Meta_Description(
			'ai/meta-description',
			array(
				'label'       => 'Meta Description',
				'description' => 'Generates a meta description',
			)
		);
	}

	/**
	 * Tear down test case.
	 *
	 * @since 1.3.0
	 */
	public function tearDown(): void {
		remove_all_filters( 'wpai_system_instruction' );
		remove_all_filters( 'wpai_title_generation_system_instruction' );
		remove_all_filters( 'wpai_title_generation_prompt' );
		remove_all_filters( 'wpai_title_generation_prompt_builder' );
		remove_all_filters( 'wpai_meta_description_prompt' );
		remove_all_filters( 'wpai_summarization_prompt_builder' );
		parent::tearDown();
	}

	/**
	 * Invokes a protected/private method via reflection.
	 *
	 * @param object $target Object instance.
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	private function invoke( $target, string $method, array $args = array() ) {
		$reflection = new \ReflectionClass( $target );
		$ref_method = $reflection->getMethod( $method );
		$ref_method->setAccessible( true );

		return $ref_method->invokeArgs( $target, $args );
	}

	/**
	 * Slug should be derived from the ability name (strip ai/, hyphens to underscores).
	 *
	 * @since 1.3.0
	 */
	public function test_get_ability_slug_derives_from_name(): void {
		$this->assertSame( 'title_generation', $this->invoke( $this->title, 'get_ability_slug' ) );
		$this->assertSame( 'meta_description', $this->invoke( $this->meta, 'get_ability_slug' ) );
	}

	/**
	 * The scoped system-instruction filter should modify the instruction.
	 *
	 * @since 1.3.0
	 */
	public function test_scoped_system_instruction_filter_applies(): void {
		add_filter(
			'wpai_title_generation_system_instruction',
			static function ( $instruction ) {
				return $instruction . ' SENTINEL_SCOPED';
			}
		);

		$this->assertStringContainsString( 'SENTINEL_SCOPED', $this->title->get_system_instruction() );
	}

	/**
	 * The scoped system-instruction filter should run after the global one.
	 *
	 * @since 1.3.0
	 */
	public function test_scoped_system_instruction_runs_after_global(): void {
		$order = array();

		add_filter(
			'wpai_system_instruction',
			static function ( $instruction ) use ( &$order ) {
				$order[] = 'global';
				return $instruction;
			}
		);
		add_filter(
			'wpai_title_generation_system_instruction',
			static function ( $instruction ) use ( &$order ) {
				$order[] = 'scoped';
				return $instruction;
			}
		);

		$this->title->get_system_instruction();

		$this->assertSame( array( 'global', 'scoped' ), $order );
	}

	/**
	 * The scoped system-instruction filter should NOT affect a different ability.
	 *
	 * @since 1.3.0
	 */
	public function test_scoped_system_instruction_is_isolated(): void {
		add_filter(
			'wpai_title_generation_system_instruction',
			static function ( $instruction ) {
				return $instruction . ' TITLE_ONLY';
			}
		);

		$this->assertStringNotContainsString( 'TITLE_ONLY', $this->meta->get_system_instruction() );
	}

	/**
	 * The user-prompt filter should modify the assembled prompt before it is sent.
	 *
	 * @since 1.3.0
	 */
	public function test_prompt_filter_modifies_prompt(): void {
		$captured = null;

		add_filter(
			'wpai_title_generation_prompt',
			static function ( $prompt ) use ( &$captured ) {
				$captured = $prompt . ' SENTINEL_PROMPT';
				return $captured;
			}
		);

		try {
			$this->invoke( $this->title, 'generate_title', array( 'Some post content.', '' ) );
		} catch ( \Throwable $e ) {
			$this->assertInstanceOf( \Throwable::class, $e );
			// Only the prompt assembly matters here, not provider availability.
		}

		$this->assertNotNull( $captured, 'Prompt filter should have been called.' );
		$this->assertStringContainsString( '<content>Some post content.</content>', (string) $captured );
		$this->assertStringContainsString( 'SENTINEL_PROMPT', (string) $captured );
	}

	/**
	 * The prompt-builder filter should fire and receive the builder.
	 *
	 * @since 1.3.0
	 */
	public function test_prompt_builder_filter_fires(): void {
		$called  = false;
		$builder = null;

		add_filter(
			'wpai_title_generation_prompt_builder',
			static function ( $prompt_builder ) use ( &$called, &$builder ) {
				$called  = true;
				$builder = $prompt_builder;
				return $prompt_builder;
			}
		);

		try {
			$this->invoke( $this->title, 'get_prompt_builder', array( '<content>Hi</content>' ) );
		} catch ( \Throwable $e ) {
			$this->assertInstanceOf( \Throwable::class, $e );
			// Builder may fail support checks without a provider; the filter still fires first.
		}

		$this->assertTrue( $called, 'Prompt builder filter should have been called.' );
		$this->assertInstanceOf( \WP_AI_Client_Prompt_Builder::class, $builder );
	}

	/**
	 * Invalid prompt-builder filter return values should be ignored.
	 *
	 * @since 1.3.0
	 */
	public function test_prompt_builder_filter_ignores_invalid_return_value(): void {
		$called = false;

		add_filter(
			'wpai_title_generation_prompt_builder',
			static function () use ( &$called ) {
				$called = true;
				return null;
			}
		);

		$result = $this->invoke( $this->title, 'get_prompt_builder', array( '<content>Hi</content>' ) );

		$this->assertTrue( $called, 'Prompt builder filter should have been called.' );
		$this->assertTrue(
			$result instanceof \WP_AI_Client_Prompt_Builder || is_wp_error( $result ),
			'Invalid filter returns should fall back to the original builder before support validation.'
		);
	}

	/**
	 * Prompt-builder filters should receive ability-specific context arguments.
	 *
	 * @since 1.3.0
	 */
	public function test_prompt_builder_filter_receives_context_arguments(): void {
		$ability       = new Summarization(
			'ai/summarization',
			array(
				'label'       => 'Summarization',
				'description' => 'Generates content summaries.',
			)
		);
		$received_args = array();

		add_filter(
			'wpai_summarization_prompt_builder',
			static function ( $prompt_builder, $prompt = null, $length = null ) use ( &$received_args ) {
				$received_args = array( $prompt_builder, $prompt, $length );
				return $prompt_builder;
			},
			10,
			3
		);

		$this->invoke( $ability, 'get_prompt_builder', array( '<content>Summarize me.</content>', 'short' ) );

		$this->assertCount( 3, $received_args );
		$this->assertInstanceOf( \WP_AI_Client_Prompt_Builder::class, $received_args[0] );
		$this->assertSame( '<content>Summarize me.</content>', $received_args[1] );
		$this->assertSame( 'short', $received_args[2] );
	}

	/**
	 * Backward compatibility: the pre-existing meta description prompt filter
	 * should still receive its richer ($prompt, $content, $title) signature.
	 *
	 * @since 1.3.0
	 */
	public function test_existing_meta_description_prompt_filter_signature_preserved(): void {
		$received_args = array();

		add_filter(
			'wpai_meta_description_prompt',
			static function ( $prompt, $content = null, $title = null ) use ( &$received_args ) {
				$received_args = array( $prompt, $content, $title );
				return $prompt;
			},
			10,
			3
		);

		try {
			$this->invoke( $this->meta, 'generate_description', array( 'Body content.', 'A Title', '' ) );
		} catch ( \Throwable $e ) {
			$this->assertInstanceOf( \Throwable::class, $e );
			// Only the filter invocation matters here.
		}

		$this->assertCount( 3, $received_args );
		$this->assertStringContainsString( '<content>Body content.</content>', (string) $received_args[0] );
		$this->assertSame( 'Body content.', $received_args[1] );
		$this->assertSame( 'A Title', $received_args[2] );
	}

	/**
	 * Both the prompt and prompt-builder filters should fire across every ability.
	 *
	 * Each case drives the ability's generation path far enough to reach the
	 * apply_filters() calls (the actual provider request fails in the test
	 * environment and is caught), proving the hooks are wired up everywhere.
	 *
	 * @since 1.3.0
	 */
	public function test_prompt_and_builder_filters_fire_for_all_abilities(): void {
		// A real 1x1 transparent PNG as a data URI, accepted by the file attachment step.
		$png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

		$cases = array(
			array(
				'slug'     => 'excerpt_generation',
				'ability'  => new Excerpt_Generation(
					'ai/excerpt-generation',
					array(
						'label'       => 'Excerpt Generation',
						'description' => 'Generates post excerpts.',
					)
				),
				'method'   => 'generate_excerpt',
				'args'     => array( 'SENTINEL_EXCERPT body', '' ),
				'sentinel' => 'SENTINEL_EXCERPT',
			),
			array(
				'slug'     => 'summarization',
				'ability'  => new Summarization(
					'ai/summarization',
					array(
						'label'       => 'Summarization',
						'description' => 'Generates content summaries.',
					)
				),
				'method'   => 'generate_summary',
				'args'     => array( 'SENTINEL_SUMMARY body', '', 'short' ),
				'sentinel' => 'SENTINEL_SUMMARY',
			),
			array(
				'slug'     => 'editorial_updates',
				'ability'  => new Editorial_Updates(
					'ai/editorial-updates',
					array(
						'label'       => 'Editorial Updates',
						'description' => 'Applies editorial updates.',
					)
				),
				'method'   => 'generate_refinement',
				'args'     => array( 'core/paragraph', 'SENTINEL_EU body', array( 'Tighten it up' ), '' ),
				'sentinel' => 'SENTINEL_EU',
			),
			array(
				'slug'     => 'editorial_notes',
				'ability'  => new Editorial_Notes(
					'ai/editorial-notes',
					array(
						'label'       => 'Editorial Notes',
						'description' => 'Generates editorial notes.',
					)
				),
				'method'   => 'generate_review',
				'args'     => array( 'core/paragraph', 'SENTINEL_EN body', '', array(), array( 'readability' ) ),
				'sentinel' => 'SENTINEL_EN',
			),
			array(
				'slug'     => 'image_prompt_generation',
				'ability'  => new Generate_Image_Prompt(
					'ai/image-prompt-generation',
					array(
						'label'       => 'Image Prompt Generation',
						'description' => 'Generates image prompts.',
					)
				),
				'method'   => 'generate_prompt',
				'args'     => array( 'SENTINEL_IMGPROMPT body', '', '' ),
				'sentinel' => 'SENTINEL_IMGPROMPT',
			),
			array(
				'slug'     => 'alt_text_generation',
				'ability'  => new Alt_Text_Generation(
					'ai/alt-text-generation',
					array(
						'label'       => 'Alt Text Generation',
						'description' => 'Generates image alt text.',
					)
				),
				'method'   => 'generate_alt_text',
				'args'     => array( array( 'reference' => $png ), 'SENTINEL_ALT', '' ),
				'sentinel' => 'SENTINEL_ALT',
			),
			array(
				'slug'     => 'image_generation',
				'ability'  => new Generate_Image(
					'ai/image-generation',
					array(
						'label'       => 'Image Generation',
						'description' => 'Generates images.',
					)
				),
				'method'   => 'generate_image',
				'args'     => array( 'SENTINEL_IMG prompt', null ),
				'sentinel' => 'SENTINEL_IMG',
			),
			array(
				'slug'     => 'comment_analysis',
				'ability'  => new Comment_Analysis(
					'ai/comment-analysis',
					array(
						'label'       => 'Comment Analysis',
						'description' => 'Analyzes comments.',
					)
				),
				'method'   => 'analyze_comment',
				'args'     => array( 'SENTINEL_COMMENT body', 'Jane Doe' ),
				'sentinel' => 'SENTINEL_COMMENT',
			),
			array(
				'slug'     => 'content_classification',
				'ability'  => new Content_Classification(
					'ai/content-classification',
					array(
						'label'       => 'Content Classification',
						'description' => 'Suggests content classifications.',
					)
				),
				'method'   => 'generate_suggestions',
				'args'     => array( array( 'content' => 'SENTINEL_CLASSIFICATION body' ), 'post_tag', 'allow_new', 5, array() ),
				'sentinel' => 'SENTINEL_CLASSIFICATION',
			),
			array(
				'slug'     => 'content_resizing',
				'ability'  => new Content_Resizing(
					'ai/content-resizing',
					array(
						'label'       => 'Content Resizing',
						'description' => 'Resizes content.',
					)
				),
				'method'   => 'generate_resized_content',
				'args'     => array( '<content>SENTINEL_RESIZE body</content>', 'rephrase' ),
				'sentinel' => 'SENTINEL_RESIZE',
			),
			array(
				'slug'     => 'type_ahead',
				'ability'  => new Type_Ahead(
					'ai/type-ahead',
					array(
						'label'       => 'Type Ahead',
						'description' => 'Generates type-ahead suggestions.',
					)
				),
				'method'   => 'generate_suggestion',
				'args'     => array( array( 'before' => 'SENTINEL_TYPEAHEAD body' ) ),
				'sentinel' => 'SENTINEL_TYPEAHEAD',
			),
		);

		foreach ( $cases as $case ) {
			$prompt_hook  = "wpai_{$case['slug']}_prompt";
			$builder_hook = "wpai_{$case['slug']}_prompt_builder";

			$captured       = null;
			$builder_called = false;

			add_filter(
				$prompt_hook,
				static function ( $prompt ) use ( &$captured ) {
					$captured = $prompt;
					return $prompt;
				}
			);
			add_filter(
				$builder_hook,
				static function ( $builder ) use ( &$builder_called ) {
					$builder_called = true;
					return $builder;
				}
			);

			try {
				$this->invoke( $case['ability'], $case['method'], $case['args'] );
			} catch ( \Throwable $e ) {
				$this->assertInstanceOf( \Throwable::class, $e );
				// The provider/file step may fail in the test env; the filters fire first.
			}

			$this->assertNotNull( $captured, "Prompt filter {$prompt_hook} should have fired." );
			$this->assertStringContainsString(
				$case['sentinel'],
				(string) $captured,
				"Prompt for {$case['slug']} should contain the injected content."
			);
			$this->assertTrue( $builder_called, "Prompt builder filter {$builder_hook} should have fired." );

			remove_all_filters( $prompt_hook );
			remove_all_filters( $builder_hook );
		}
	}

	/**
	 * Suggest Reply assembles its prompt in execute_callback (which needs a real
	 * comment), so it is covered separately from the data-driven loop above.
	 *
	 * @since 1.3.0
	 */
	public function test_suggest_reply_prompt_and_builder_filters_fire(): void {
		$post_id    = self::factory()->post->create( array( 'post_title' => 'A Post' ) );
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'comment_author'  => 'Jane Doe',
				'comment_content' => 'SENTINEL_REPLY body',
			)
		);

		$ability = new Suggest_Reply(
			'ai/suggest-reply',
			array(
				'label'       => 'Suggest Reply',
				'description' => 'Suggests replies to comments.',
			)
		);

		$captured       = null;
		$builder_called = false;

		add_filter(
			'wpai_suggest_reply_prompt',
			static function ( $prompt ) use ( &$captured ) {
				$captured = $prompt;
				return $prompt;
			}
		);
		add_filter(
			'wpai_suggest_reply_prompt_builder',
			static function ( $builder ) use ( &$builder_called ) {
				$builder_called = true;
				return $builder;
			}
		);

		try {
			$this->invoke( $ability, 'execute_callback', array( array( 'comment_id' => $comment_id ) ) );
		} catch ( \Throwable $e ) {
			$this->assertInstanceOf( \Throwable::class, $e );
			// The provider step may fail in the test env; the filters fire first.
		}

		$this->assertNotNull( $captured, 'wpai_suggest_reply_prompt should have fired.' );
		$this->assertStringContainsString( 'SENTINEL_REPLY', (string) $captured );
		$this->assertTrue( $builder_called, 'wpai_suggest_reply_prompt_builder should have fired.' );

		remove_all_filters( 'wpai_suggest_reply_prompt' );
		remove_all_filters( 'wpai_suggest_reply_prompt_builder' );
	}
}
