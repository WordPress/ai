<?php
/**
 * Basic dangerous-pattern scanner for generated PHP code.
 *
 * @package WordPress\AI\Experiments
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Plugin_Builder\Ai;

use WordPress\AI\Experiments\Plugin_Builder\Config;

/**
 * Basic dangerous-pattern regex scanner for generated PHP code.
 *
 * @since x.x.x
 */
class SecurityScanner {

	/**
	 * Scan generated files for dangerous patterns.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, array<string, mixed>> $files The array of generated files.
	 * @return array{passed: bool, issues: array<int, array<string, mixed>>} Scan results.
	 */
	public static function scan( array $files ): array {
		$issues   = array();
		$patterns = Config::dangerous_patterns();

		foreach ( $files as $file ) {
			// Only scan PHP files.
			if ( 'php' !== ( $file['type'] ?? '' ) ) {
				continue;
			}

			$lines = explode( "\n", $file['content'] ?? '' );

			foreach ( $lines as $line_num => $line ) {
				foreach ( $patterns as $pattern ) {
					if ( ! preg_match( $pattern, $line ) ) {
						continue;
					}

					$issues[] = array(
						'file_path'    => $file['path'],
						'line'         => $line_num + 1,
						'pattern'      => $pattern,
						'line_content' => trim( $line ),
					);
				}
			}
		}

		return array(
			'passed' => empty( $issues ),
			'issues' => array_slice( $issues, 0, 10 ), // Cap at 10 issues.
		);
	}
}
