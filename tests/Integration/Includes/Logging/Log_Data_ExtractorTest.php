<?php
/**
 * Integration tests for log data extraction.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Logging
 */

namespace WordPress\AI\Tests\Integration\Includes\Logging;

use WP_UnitTestCase;
use WordPress\AI\Logging\Log_Data_Extractor;

/**
 * @covers \WordPress\AI\Logging\Log_Data_Extractor
 */
class Log_Data_ExtractorTest extends WP_UnitTestCase {
	public function test_detect_request_kind_classifies_models_endpoint_as_metadata(): void {
		$extractor = new Log_Data_Extractor();

		$this->assertSame(
			'metadata',
			$extractor->detect_request_kind( 'anthropic', '/v1/models', null )
		);
	}

	public function test_extract_request_data_marks_model_discovery_requests_as_metadata(): void {
		$extractor = new Log_Data_Extractor();

		$log_data = $extractor->extract_request_data(
			'https://api.anthropic.com/v1/models',
			'GET',
			null
		);

		$this->assertSame( 'anthropic:models', $log_data['operation'] );
		$this->assertSame( 'metadata', $log_data['context']['request_kind'] );
	}
}
