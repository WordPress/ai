<?php
/**
 * Configuration for the AI Plugin Builder experiment.
 *
 * @package WordPress\AI\Experiments
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Plugin_Builder;

/**
 * Central configuration for the AI Plugin Builder.
 *
 * @since x.x.x
 */
class Config {

	/**
	 * Maximum number of files the planner may create.
	 *
	 * @return int
	 */
	public static function max_files(): int {
		return 10;
	}

	/**
	 * Max tokens for the planner step.
	 *
	 * @return int
	 */
	public static function planner_max_tokens(): int {
		return 16384;
	}

	/**
	 * Max tokens for the coder step.
	 *
	 * @return int
	 */
	public static function coder_max_tokens(): int {
		return 32768;
	}

	/**
	 * Required capability to generate plugins.
	 *
	 * @return string
	 */
	public static function generate_capability(): string {
		return 'install_plugins';
	}

	/**
	 * Required capability to install generated plugins.
	 *
	 * @return string
	 */
	public static function install_capability(): string {
		return 'install_plugins';
	}

	/**
	 * Regex patterns for dangerous PHP constructs in generated code.
	 *
	 * @return string[]
	 */
	public static function dangerous_patterns(): array {
		return array(
			'/\beval\s*\(/i',
			'/\bexec\s*\(/i',
			'/\bsystem\s*\(/i',
			'/\bpassthru\s*\(/i',
			'/\bshell_exec\s*\(/i',
			'/\bproc_open\s*\(/i',
			'/\bpopen\s*\(/i',
			'/\bfile_put_contents\s*\(\s*\$_(GET|POST|REQUEST)/i',
			'/\b(unlink|rmdir)\s*\(\s*\$_(GET|POST|REQUEST)/i',
			'/\bbase64_decode\s*\(\s*\$_(GET|POST|REQUEST)/i',
			'/\$_GET\b(?!.*\b(sanitize_|esc_|wp_verify_nonce|absint|intval))/i',
			'/\$_POST\b(?!.*\b(sanitize_|esc_|wp_verify_nonce|absint|intval))/i',
		);
	}
}
