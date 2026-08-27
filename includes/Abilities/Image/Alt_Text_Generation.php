<?php
/**
 * Alt text generation WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Image;

use WP_Error;
use WP_Http;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI\Experiments\Alt_Text_Generation\Alt_Text_Generation as Alt_Text_Generation_Experiment;

use function WordPress\AI\get_preferred_vision_models;
use function WordPress\AI\normalize_content;

/**
 * Alt text generation WordPress Ability.
 *
 * Uses AI vision models to propose alt text aligned with WCAG-oriented practice.
 *
 * @since 0.3.0
 */
class Alt_Text_Generation extends Abstract_Ability {

	/**
	 * The maximum character length for generated alt text.
	 *
	 * @since 0.3.0
	 *
	 * @var int
	 */
	protected const MAX_ALT_TEXT_LENGTH = 125;

	/**
	 * Model output token that means the correct alternative text is empty (alt="").
	 *
	 * @since 0.7.0
	 *
	 * @var string
	 */
	private const DECORATIVE_ALT_TOKEN = '[[DECORATIVE_ALT]]';

	/**
	 * Allowed image MIME types.
	 *
	 * @since x.x.x
	 *
	 * @var list<string>
	 */
	private const ALLOWED_IMAGE_MIME_TYPES = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is used as an array const.
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
		'image/avif',
	);

	/**
	 * Default timeout, in seconds, when downloading a remote image.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private const DEFAULT_DOWNLOAD_TIMEOUT = 30;

	/**
	 * Default maximum size, in bytes, of a remote image download (20 MB).
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private const DEFAULT_MAX_DOWNLOAD_BYTES = 20 * MB_IN_BYTES;

	/**
	 * Maximum number of redirects followed when downloading a remote image.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private const MAX_DOWNLOAD_REDIRECTS = 3;

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.8.0
	 */
	protected function guideline_categories(): array {
		return array( 'site', 'images' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.3.0
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'attachment_id' => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => esc_html__( 'The attachment ID of the image to generate alt text for.', 'ai' ),
				),
				'image_url'     => array(
					'type'        => 'string',
					'description' => esc_html__( 'URL or data URI of the image to generate alt text for. Used if attachment_id is not provided.', 'ai' ),
				),
				'context'       => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
					'description'       => esc_html__( 'Optional context about the image or surrounding content to improve alt text relevance.', 'ai' ),
				),
				'image_meta'    => array(
					'type'        => 'string',
					'description' => esc_html__( 'Structured metadata about how the image block is used, such as whether it is linked.', 'ai' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.3.0
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'alt_text'      => array(
					'type'        => 'string',
					'description' => esc_html__( 'Generated alternative text for the image; may be empty when alt="" is correct.', 'ai' ),
				),
				'is_decorative' => array(
					'type'        => 'boolean',
					'description' => esc_html__( 'Whether the image was determined to be decorative.', 'ai' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.3.0
	 */
	protected function execute_callback( $input ) {
		// Default arguments.
		$args = wp_parse_args(
			$input,
			array(
				'attachment_id' => null,
				'image_url'     => null,
				'context'       => '',
				'image_meta'    => '',
			),
		);

		if ( isset( $args['image_url'] ) ) {
			$args['image_url'] = $this->sanitize_image_reference_input( $args['image_url'] );
		}

		// Get the image reference.
		$image_reference = $this->get_image_reference( $args );

		if ( is_wp_error( $image_reference ) ) {
			return $image_reference;
		}

		// Generate the alt text.
		$result = $this->generate_alt_text(
			$image_reference,
			normalize_content( $args['context'] ),
			sanitize_textarea_field( $args['image_meta'] )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Detect the decorative token from the AI response.
		if ( 0 === strcasecmp( trim( $result ), self::DECORATIVE_ALT_TOKEN ) ) {
			return array(
				'alt_text'      => '',
				'is_decorative' => true,
			);
		}

		// Return the alt text in the format the Ability expects.
		return array(
			'alt_text'      => sanitize_text_field( $result ),
			'is_decorative' => false,
		);
	}

	/**
	 * Gets the image as a data URI from the input arguments.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, mixed> $args The input arguments.
	 * @return array{reference: string}|\WP_Error The prepared reference payload or WP_Error on failure.
	 */
	protected function get_image_reference( array $args ) {
		// If an attachment ID is provided, get the attachment from the database.
		if ( ! empty( $args['attachment_id'] ) ) {
			return $this->get_attachment_reference( absint( $args['attachment_id'] ) );
		}

		// If an image URL is provided, get the image from the URL.
		if ( ! empty( $args['image_url'] ) ) {
			// Preserve data URIs as-is so the AI client can read the inline bytes.
			if ( str_starts_with( $args['image_url'], 'data:' ) ) {
				$is_supported = $this->validate_image_data_uri( $args['image_url'] );

				if ( is_wp_error( $is_supported ) ) {
					return $is_supported;
				}

				return $this->prepare_reference_result( $args['image_url'] );
			}

			// Try to map the URL to a local path.
			$path = $this->maybe_map_url_to_local_path( $args['image_url'] );

			if ( $path ) {
				$data_uri = $this->file_to_data_uri( $path );
				if ( $data_uri ) {
					return $this->prepare_reference_result( $data_uri );
				}
			}

			return $this->remote_url_to_reference( $args['image_url'] );
		}

		return new WP_Error(
			'no_image_provided',
			esc_html__( 'Either attachment_id or image_url must be provided.', 'ai' )
		);
	}

	/**
	 * Generates alt text for the given image reference.
	 *
	 * @since 0.3.0
	 *
	 * @param array{reference: string} $image_reference Prepared image reference containing a data URI.
	 * @param string                   $context         Optional context to improve alt text relevance.
	 * @param string                   $image_meta      Optional metadata about how the image block is used.
	 * @return string|\WP_Error The generated alt text or WP_Error on failure.
	 */
	protected function generate_alt_text( array $image_reference, string $context = '', string $image_meta = '' ) {
		$prompt = $this->build_prompt( $context, $image_meta );

		$prompt         = $this->filter_prompt( $prompt, $context, $image_meta );
		$prompt_builder = $this->get_prompt_builder( $prompt, $image_reference['reference'] );

		if ( is_wp_error( $prompt_builder ) ) {
			return $prompt_builder;
		}

		$result = $prompt_builder->generate_text();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Clean up the result.
		$alt_text = trim( $result );
		$alt_text = trim( $alt_text, '"\'.' );

		return $alt_text;
	}

	/**
	 * Converts an attachment to a data URI.
	 *
	 * @since 0.3.0
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{reference: string}|\WP_Error Data URI reference array or WP_Error on failure.
	 */
	protected function get_attachment_reference( int $attachment_id ) {
		$attachment = get_post( $attachment_id );

		// Ensure the attachment is valid.
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error(
				'invalid_attachment',
				/* translators: %d: Attachment ID. */
				sprintf( esc_html__( 'Attachment with ID %d not found.', 'ai' ), $attachment_id )
			);
		}

		// Ensure the attachment is an image.
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return new WP_Error(
				'not_an_image',
				esc_html__( 'The specified attachment is not an image.', 'ai' )
			);
		}

		// Try and get the data URI from the file path.
		$file_path = get_attached_file( $attachment_id );
		if ( $file_path && file_exists( $file_path ) ) {
			$data_uri = $this->file_to_data_uri( $file_path );
			if ( $data_uri ) {
				return $this->prepare_reference_result( $data_uri );
			}
		}

		// If the file path doesn't exist, try and use the image src.
		$image_src = wp_get_attachment_image_src( $attachment_id, 'large' );

		if ( ! $image_src ) {
			$image_src = wp_get_attachment_image_src( $attachment_id, 'full' );
		}

		if ( ! $image_src || empty( $image_src[0] ) ) {
			return new WP_Error(
				'image_url_not_found',
				esc_html__( 'Could not retrieve image URL from attachment.', 'ai' )
			);
		}

		// Download remote URL and convert to data URI.
		return $this->remote_url_to_reference( $image_src[0] );
	}

	/**
	 * Downloads a remote image and converts it to a data URI reference.
	 *
	 * @since x.x.x
	 *
	 * @param string $url The image URL to fetch.
	 * @return array{reference: string}|\WP_Error Data URI reference array or WP_Error on failure.
	 */
	protected function remote_url_to_reference( string $url ) {
		$downloaded = $this->download_remote_image_to_temp_file( $url );

		if ( is_wp_error( $downloaded ) ) {
			return $downloaded;
		}

		$data_uri = $this->file_to_data_uri( $downloaded );
		$this->cleanup_temporary_file( $downloaded );

		if ( ! $data_uri ) {
			return $this->remote_image_error();
		}

		return $this->prepare_reference_result( $data_uri );
	}

	/**
	 * Builds the error returned for every remote image failure.
	 *
	 * @since x.x.x
	 *
	 * @return \WP_Error The generic remote image error.
	 */
	protected function remote_image_error(): WP_Error {
		return new WP_Error(
			'invalid_image_url',
			esc_html__( 'The provided image URL could not be retrieved as a supported image.', 'ai' )
		);
	}

	/**
	 * Attempts to map an uploads URL to a local filesystem path.
	 *
	 * @since 0.3.0
	 *
	 * @param string $url The URL to convert.
	 * @return string|null The local path if found, otherwise null.
	 */
	protected function maybe_map_url_to_local_path( string $url ): ?string {
		$uploads = wp_get_upload_dir();

		if (
			empty( $uploads['baseurl'] ) ||
			empty( $uploads['basedir'] )
		) {
			return null;
		}

		$normalized_url     = $this->normalize_upload_url( $url );
		$normalized_baseurl = $this->normalize_upload_url( $uploads['baseurl'] );

		if ( ! str_contains( $normalized_url, $normalized_baseurl ) ) {
			return null;
		}

		$relative_path = ltrim(
			substr( $normalized_url, strlen( $normalized_baseurl ) ),
			'/'
		);

		if ( '' === $relative_path ) {
			return null;
		}

		// Reject path traversal attempts in the relative path.
		if (
			'..' === $relative_path ||
			str_starts_with( $relative_path, '../' ) ||
			str_contains( $relative_path, '/..' )
		) {
			return null;
		}

		$base_dir       = wp_normalize_path(
			trailingslashit( $uploads['basedir'] )
		);
		$full_path      = $base_dir . $relative_path;
		$real_full_path = realpath( $full_path );

		if ( false === $real_full_path ) {
			return null;
		}

		$real_full_path = wp_normalize_path( $real_full_path );

		// Ensure the resolved path is strictly within the uploads base directory.
		if ( ! str_starts_with( $real_full_path, $base_dir ) ) {
			return null;
		}

		if ( file_exists( $real_full_path ) && is_file( $real_full_path ) ) {
			return $real_full_path;
		}

		return null;
	}

	/**
	 * Normalizes an uploads URL for comparison.
	 *
	 * @since 0.3.0
	 *
	 * @param string $url URL to normalize.
	 * @return string Normalized URL without scheme and trailing slashes.
	 */
	protected function normalize_upload_url( string $url ): string {
		$without_scheme = preg_replace( '#^https?://#i', '', $url );

		return rtrim( $without_scheme ?? $url, '/' );
	}

	/**
	 * Gets a prompt builder for generating alt text.
	 *
	 * @since 0.7.0
	 *
	 * @param string $prompt The prompt to generate alt text from.
	 * @param string $reference The reference image.
	 * @return \WP_AI_Client_Prompt_Builder|\WP_Error The prompt builder, or a WP_Error on failure.
	 */
	private function get_prompt_builder( string $prompt, string $reference ) {
		$prompt_builder = wp_ai_client_prompt( $prompt )
			->with_file( $reference )
			->using_system_instruction( $this->get_system_instruction( 'alt-text-system-instruction.php' ) );

		$prompt_builder = $this->filter_prompt_builder( $prompt_builder, Alt_Text_Generation_Experiment::class, get_preferred_vision_models(), $prompt, $reference );

		return $this->ensure_text_generation_supported(
			$prompt_builder,
			esc_html__( 'Alt text generation failed. Please ensure you have a connected provider that supports both text generation and vision capabilities.', 'ai' )
		);
	}

	/**
	 * Builds the prompt for alt text generation.
	 *
	 * @since 0.3.0
	 *
	 * @param string $context    Optional context about the image.
	 * @param string $image_meta Optional metadata about how the image block is used.
	 * @return string The prompt for the AI.
	 */
	protected function build_prompt( string $context = '', string $image_meta = '' ): string {
		$prompt = __( 'Generate alt text for this image.', 'ai' );

		// If we have additional context, add it to the prompt.
		if ( ! empty( $context ) ) {
			$prompt .= ' ' . __( 'Ensure the alt text you return matches the language of the content in the <additional-context> tag.', 'ai' );

			$prompt .= "\n\n<additional-context>" . $context . '</additional-context>';
		} else {
			$prompt .= ' ' . sprintf(
				/* translators: %s: locale code, e.g. pl_PL */
				__( 'Ensure the alt text you return matches the language of this locale: %s.', 'ai' ),
				get_locale()
			);
		}

		// If we have image block usage metadata, add it to the prompt.
		if ( ! empty( $image_meta ) ) {
			$prompt .= "\n\n<image-meta>" . $image_meta . '</image-meta>';
		}

		return $prompt;
	}

	/**
	 * Sanitizes incoming image references while allowing data URIs.
	 *
	 * @since 0.3.0
	 *
	 * @param mixed $value Raw user input.
	 * @return string Sanitized value.
	 */
	protected function sanitize_image_reference_input( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		if ( str_starts_with( $value, 'data:' ) ) {
			return $value;
		}

		return esc_url_raw( $value );
	}

	/**
	 * Returns the allowed image MIME types.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> Allowed image media types.
	 */
	protected function get_allowed_image_mime_types(): array {
		/**
		 * Filters the allowed image MIME types.
		 *
		 * Only add media types that are both supported by the configured provider
		 * and safe to read from untrusted input.
		 *
		 * @since x.x.x
		 *
		 * @param list<string> $mime_types Allowed image media types.
		 */
		$mime_types = apply_filters( 'wpai_alt_text_allowed_image_mime_types', self::ALLOWED_IMAGE_MIME_TYPES );

		return array_values( array_filter( array_map( 'strval', (array) $mime_types ) ) );
	}

	/**
	 * Validates that a data URI carries inline bytes for a supported type.
	 *
	 * @since x.x.x
	 *
	 * @param string $value The data URI to validate.
	 * @return true|\WP_Error True when supported, WP_Error otherwise.
	 */
	protected function validate_image_data_uri( string $value ) {
		$error = new WP_Error(
			'unsupported_image_type',
			esc_html__( 'The provided image data is not a supported image type.', 'ai' )
		);

		if ( ! preg_match( '#^data:([a-z0-9.+-]+/[a-z0-9.+-]+);base64,(.+)$#is', $value, $matches ) ) {
			return $error;
		}

		if ( ! in_array( strtolower( $matches[1] ), $this->get_allowed_image_mime_types(), true ) ) {
			return $error;
		}

		$decoded = base64_decode( (string) preg_replace( '/\s+/', '', $matches[2] ), true );

		if ( false === $decoded || '' === $decoded ) {
			return $error;
		}

		return true;
	}

	/**
	 * Validates that a remote image URL is safe for the server to request.
	 *
	 * Returns the addresses the host resolved to so that the connection can be pinned
	 * to them. Resolving here and connecting to a name resolved a second time would
	 * leave a window in which the two answers differ.
	 *
	 * @since x.x.x
	 *
	 * @param string $url The URL to validate.
	 * @return list<string>|\WP_Error Addresses to pin the connection to, empty when the
	 *                               host is the site's own and is exempt, WP_Error otherwise.
	 */
	protected function validate_remote_image_url( string $url ) {
		$error  = $this->remote_image_error();
		$parsed = wp_parse_url( $url );

		if ( ! is_array( $parsed ) || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return $error;
		}

		if ( ! in_array( strtolower( $parsed['scheme'] ), array( 'http', 'https' ), true ) ) {
			return $error;
		}

		// Credentials are never needed to fetch an image and can be abused against internal services.
		if ( isset( $parsed['user'] ) || isset( $parsed['pass'] ) ) {
			return $error;
		}

		/*
		 * Core rejects non-standard ports, unresolvable hosts, and a list of reserved
		 * ranges that has grown over time. Run it first, then apply the checks below so
		 * that sites on older versions of WordPress are covered too.
		 */
		if ( ! wp_http_validate_url( $url ) ) {
			return $error;
		}

		$host = strtolower( trim( $parsed['host'], '.' ) );

		/*
		 * Core exempts the site's own host from address checks. Preserve that, otherwise
		 * local development installs and single-host setups could not read their own uploads.
		 * Nothing is pinned for it, since the address it resolves to is trusted either way.
		 */
		if ( $this->is_site_host( $host ) ) {
			return array();
		}

		$ips = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : $this->resolve_host( $host );

		if ( ! $this->addresses_are_requestable( $ips, $host, $url ) ) {
			return $error;
		}

		return $ips;
	}

	/**
	 * Checks whether a host belongs to this site.
	 *
	 * @since x.x.x
	 *
	 * @param string $host Lowercased host name to check.
	 * @return bool True if the host is the site's own host.
	 */
	protected function is_site_host( string $host ): bool {
		foreach ( array( home_url(), site_url() ) as $site_url ) {
			$site_host = wp_parse_url( $site_url, PHP_URL_HOST );

			if ( is_string( $site_host ) && strtolower( trim( $site_host, '.' ) ) === $host ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks whether every address a host resolved to may be requested.
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $ips  Addresses the host resolved to.
	 * @param string       $host Host name or IP literal.
	 * @param string       $url  The full URL, passed to the host allowance filter.
	 * @return bool True when every address is public or explicitly allowed.
	 */
	protected function addresses_are_requestable( array $ips, string $host, string $url ): bool {
		// An unresolvable host cannot be verified, so it is not requested.
		if ( array() === $ips ) {
			return false;
		}

		foreach ( $ips as $ip ) {
			if ( $this->is_public_ip( $ip ) ) {
				continue;
			}

			/** This filter is documented in wp-includes/http.php */
			if ( apply_filters( 'http_request_host_is_external', false, $host, $url ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, reused so that sites allowing an internal host keep a single escape hatch.
				continue;
			}

			return false;
		}

		return true;
	}

	/**
	 * Resolves a host name to its IPv4 addresses.
	 *
	 * @since x.x.x
	 *
	 * @param string $host Host name to resolve.
	 * @return list<string> Resolved addresses, empty when the host cannot be resolved.
	 */
	protected function resolve_host( string $host ): array {
		$ips = gethostbynamel( $host );

		return is_array( $ips ) ? $ips : array();
	}

	/**
	 * Checks whether an IP address is publicly routable.
	 *
	 * @since x.x.x
	 *
	 * @param string $ip The IPv4 or IPv6 address to check.
	 * @return bool True when the address is public.
	 */
	protected function is_public_ip( string $ip ): bool {
		/*
		 * Rejects loopback, link-local, private, and reserved ranges
		 * for both IPv4 and IPv6.
		 */
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return ! $this->embeds_ipv4_address( $ip );
		}

		$parts = array_map( 'intval', explode( '.', $ip ) );

		/*
		 * Ranges that PHP's reserved range filter does not cover: 100.64.0.0/10 (CGNAT),
		 * 192.0.0.0/24 (IETF protocol assignments), 198.18.0.0/15 (benchmarking), and
		 * 224.0.0.0/4 (multicast) upwards.
		 */
		return ! (
			( 100 === $parts[0] && 64 <= $parts[1] && 127 >= $parts[1] ) ||
			( 192 === $parts[0] && 0 === $parts[1] && 0 === $parts[2] ) ||
			( 198 === $parts[0] && 18 <= $parts[1] && 19 >= $parts[1] ) ||
			224 <= $parts[0]
		);
	}

	/**
	 * Checks whether an IPv6 address embeds an IPv4 address.
	 *
	 * @since x.x.x
	 *
	 * @param string $ip The IPv6 address to check.
	 * @return bool True when the address embeds an IPv4 address.
	 */
	protected function embeds_ipv4_address( string $ip ): bool {
		$packed = inet_pton( $ip );

		if ( false === $packed ) {
			return false;
		}

		$prefix = substr( $packed, 0, 12 );

		return str_repeat( "\0", 12 ) === $prefix || str_repeat( "\0", 10 ) . "\xff\xff" === $prefix;
	}

	/**
	 * Downloads a remote image to a temporary file for processing.
	 *
	 * @since 0.3.0
	 * @since x.x.x Requests are bounded by an explicit timeout and response size limit,
	 *              failures no longer expose the upstream response, and each hop is
	 *              validated and pinned to the address that was checked.
	 *
	 * @param string $url Remote image URL.
	 * @return string|\WP_Error Path to the temporary file or WP_Error on failure.
	 */
	protected function download_remote_image_to_temp_file( string $url ) {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$download_error = $this->remote_image_error();

		$url_path      = wp_parse_url( $url, PHP_URL_PATH );
		$url_file_name = is_string( $url_path ) && '' !== $url_path ? basename( $url_path ) : '';
		$temp_file     = wp_tempnam( $url_file_name );

		if ( ! $temp_file ) {
			return $download_error;
		}

		/**
		 * Filters the timeout, in seconds, for downloading a remote image for alt text generation.
		 *
		 * @since x.x.x
		 *
		 * @param int    $timeout Timeout in seconds.
		 * @param string $url     The image URL being downloaded.
		 */
		$timeout = (int) apply_filters( 'wpai_alt_text_image_download_timeout', self::DEFAULT_DOWNLOAD_TIMEOUT, $url );

		/**
		 * Filters the maximum size, in bytes, of a remote image downloaded for alt text generation.
		 *
		 * @since x.x.x
		 *
		 * @param int    $max_bytes Maximum response size in bytes.
		 * @param string $url       The image URL being downloaded.
		 */
		$max_bytes = (int) apply_filters( 'wpai_alt_text_image_max_download_bytes', self::DEFAULT_MAX_DOWNLOAD_BYTES, $url );

		if ( ! $this->fetch_image_to_file( $url, $temp_file, $timeout, $max_bytes ) ) {
			$this->cleanup_temporary_file( $temp_file );

			return $download_error;
		}

		return $temp_file;
	}

	/**
	 * Requests a URL into a file, following redirects a hop at a time.
	 *
	 * @since x.x.x
	 *
	 * @param string $url       The URL to request.
	 * @param string $temp_file Path the response body is written to.
	 * @param int    $timeout   Timeout in seconds, applied per hop.
	 * @param int    $max_bytes Maximum response size in bytes, applied per hop.
	 * @return bool True when the file holds the body of a successful response.
	 */
	protected function fetch_image_to_file( string $url, string $temp_file, int $timeout, int $max_bytes ): bool {
		$current_url = $url;

		for ( $hop = 0; $hop <= self::MAX_DOWNLOAD_REDIRECTS; $hop++ ) {
			$validated = $this->validate_remote_image_url( $current_url );

			if ( is_wp_error( $validated ) ) {
				return false;
			}

			$response = $this->request_pinned_url(
				$current_url,
				$validated,
				array(
					'timeout'             => $timeout,
					'redirection'         => 0,
					'limit_response_size' => $max_bytes,
					'stream'              => true,
					'filename'            => $temp_file,
				)
			);

			/*
			 * The upstream status code and body never reach the caller, since they would
			 * describe hosts the caller cannot otherwise see. Only success or failure does.
			 */
			if ( is_wp_error( $response ) ) {
				return false;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );

			if ( 200 === $status ) {
				return true;
			}

			$redirect_url = $this->get_redirect_target( $response, $current_url, $status );

			if ( null === $redirect_url ) {
				return false;
			}

			$current_url = $redirect_url;
		}

		return false;
	}

	/**
	 * Reads the next URL to request from a redirect response.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed>|\WP_Error $response    The response to inspect.
	 * @param string                         $current_url The URL that produced the response.
	 * @param int                            $status      The response status code.
	 * @return string|null The absolute redirect target, or null when there is not one.
	 */
	protected function get_redirect_target( $response, string $current_url, int $status ): ?string {
		if ( ! in_array( $status, array( 301, 302, 303, 307, 308 ), true ) ) {
			return null;
		}

		$location = wp_remote_retrieve_header( $response, 'location' );

		if ( ! is_string( $location ) || '' === $location ) {
			return null;
		}

		$absolute = WP_Http::make_absolute_url( $location, $current_url );

		return '' === $absolute ? null : $absolute;
	}

	/**
	 * Performs a GET request with the connection pinned to already validated addresses.
	 *
	 * @since x.x.x
	 *
	 * @param string               $url  The URL to request.
	 * @param list<string>         $ips  Validated addresses, empty when the host is exempt.
	 * @param array<string, mixed> $args Request arguments passed to wp_safe_remote_get().
	 * @return array<string, mixed>|\WP_Error The response, or WP_Error on failure.
	 */
	protected function request_pinned_url( string $url, array $ips, array $args ) {
		$pin = $this->build_pin_entry( $url, $ips );

		// The site's own host is exempt from address checks, so there is nothing to pin to.
		if ( null === $pin ) {
			return wp_safe_remote_get( $url, $args );
		}

		/*
		 * Only the cURL transport can pin. These are the same functions the HTTP API's
		 * transport check requires, so failing here means the request would have gone out
		 * unpinned. Checked up front so no request is made at all in that case.
		 */
		if ( ! $this->can_pin_requests() ) {
			return $this->remote_image_error();
		}

		$pinned = false;

		$apply_pin = static function ( &$handle ) use ( $pin, &$pinned ) {
			curl_setopt( $handle, CURLOPT_RESOLVE, array( $pin ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Pinning the connection requires the handle itself.

			$pinned = true;
		};

		add_action( 'http_api_curl', $apply_pin );

		try {
			$response = wp_safe_remote_get( $url, $args );
		} finally {
			remove_action( 'http_api_curl', $apply_pin );
		}

		if ( ! $pinned ) {
			return $this->remote_image_error();
		}

		return $response;
	}

	/**
	 * Checks whether this site can pin a request to a validated address.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when the cURL transport is available to pin with.
	 */
	protected function can_pin_requests(): bool {
		return function_exists( 'curl_init' )
			&& function_exists( 'curl_exec' )
			&& function_exists( 'curl_setopt' )
			&& defined( 'CURLOPT_RESOLVE' );
	}

	/**
	 * Builds the CURLOPT_RESOLVE entry that pins a URL's host to a validated address.
	 *
	 * @since x.x.x
	 *
	 * @param string       $url The URL being requested.
	 * @param list<string> $ips Validated addresses for the URL's host.
	 * @return string|null The `host:port:address` entry, or null when there is nothing to pin.
	 */
	protected function build_pin_entry( string $url, array $ips ): ?string {
		$parsed  = wp_parse_url( $url );
		$address = reset( $ips );

		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) || ! is_string( $address ) ) {
			return null;
		}

		$port = isset( $parsed['port'] )
			? (int) $parsed['port']
			: ( 'https' === strtolower( (string) ( $parsed['scheme'] ?? '' ) ) ? 443 : 80 );

		return sprintf( '%s:%d:%s', strtolower( trim( $parsed['host'], '.' ) ), $port, $address );
	}

	/**
	 * Cleans up a temporary file if it exists.
	 *
	 * @since 0.3.0
	 *
	 * @param string $file_path The file path to clean up.
	 * @return void
	 */
	protected function cleanup_temporary_file( string $file_path ): void {
		if ( ! file_exists( $file_path ) ) {
			return;
		}

		wp_delete_file( $file_path );
	}

	/**
	 * Helper to standardize the reference result.
	 *
	 * @since 0.3.0
	 *
	 * @param string $reference The data URI reference.
	 * @return array{reference: string} Standardized reference array.
	 */
	protected function prepare_reference_result( string $reference ): array {
		return array(
			'reference' => $reference,
		);
	}

	/**
	 * Converts a file to a data URI.
	 *
	 * @since 0.3.0
	 * @since x.x.x The media type is read from the file's contents rather than its
	 *              name, and must be a supported image type.
	 *
	 * @param string $file_path Path to the file.
	 * @return string|null Data URI or null on failure.
	 */
	protected function file_to_data_uri( string $file_path ): ?string {
		$mime_type = wp_get_image_mime( $file_path );

		if ( ! $mime_type || ! in_array( $mime_type, $this->get_allowed_image_mime_types(), true ) ) {
			return null;
		}

		$contents = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
		if ( false === $contents ) {
			return null;
		}

		return 'data:' . $mime_type . ';base64,' . base64_encode( $contents );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.3.0
	 */
	protected function permission_callback( $args ) {
		$attachment_id = isset( $args['attachment_id'] ) ? absint( $args['attachment_id'] ) : null;

		if ( $attachment_id ) {
			$attachment = get_post( $attachment_id );

			if ( ! $attachment ) {
				return new WP_Error(
					'attachment_not_found',
					/* translators: %d: Attachment ID. */
					sprintf( esc_html__( 'Attachment with ID %d not found.', 'ai' ), $attachment_id )
				);
			}

			// Check if user can edit this attachment.
			if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
				return new WP_Error(
					'insufficient_capabilities',
					esc_html__( 'You do not have permission to edit this attachment.', 'ai' )
				);
			}
		} elseif ( ! current_user_can( 'upload_files' ) ) {
			// For URL-based generation, require upload_files capability.
			return new WP_Error(
				'insufficient_capabilities',
				esc_html__( 'You do not have permission to generate alt text.', 'ai' )
			);
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.3.0
	 */
	protected function meta(): array {
		return array(
			'show_in_rest' => true,
			'mcp'          => array(
				'public'   => true,
				'type'     => 'tool',
				'category' => 'media',
			),
		);
	}
}
