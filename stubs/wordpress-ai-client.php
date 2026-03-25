<?php
// phpcs:ignoreFile
/**
 * Minimal PHPStan stubs for WordPress AI Client runtime classes.
 *
 * These classes are provided by WordPress core during runtime, but are not
 * available as Composer dependencies in this plugin repository.
 */

namespace WordPress\AiClient;

use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;

class AiClient {
	public static function defaultRegistry(): Registry {}
}

class Registry {
	/**
	 * @return list<string>
	 */
	public function getRegisteredProviderIds(): array {}

	/**
	 * @param string $provider_id Provider identifier.
	 * @return class-string|object
	 */
	public function getProviderClassName( string $provider_id ) {}

	public function getHttpTransporter(): HttpTransporterInterface {}

	public function setHttpTransporter( HttpTransporterInterface $transporter ): void {}
}

namespace WordPress\AiClient\Providers\DTO;

class ProviderMetadata {
	public function getId(): string {}

	public function getName(): string {}

	public function getType(): ProviderType {}
}

class ProviderType {
	/** @var string */
	public $value;
}

namespace WordPress\AiClient\Providers\Models\DTO;

use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

class ModelMetadata {
	public function getId(): string {}

	public function getName(): string {}

	/**
	 * @return list<CapabilityEnum>
	 */
	public function getSupportedCapabilities(): array {}
}

namespace WordPress\AiClient\Providers\Models\Enums;

class CapabilityEnum {
	/** @var string */
	public $value;
}

namespace WordPress\AiClient\Providers\Http\Contracts;

use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;

interface HttpTransporterInterface {
	public function send( Request $request, ?RequestOptions $options = null ): Response;
}

namespace WordPress\AiClient\Providers\Http\DTO;

class Request {
	public function getUri(): string {}

	public function getMethod(): HttpMethod {}

	public function getBody(): ?string {}
}

class RequestOptions {}

class Response {
	public function getBody(): ?string {}
}

class HttpMethod {
	/** @var string */
	public $value;
}
