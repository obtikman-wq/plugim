<?php

namespace AIFB\AI;

use AIFB\Utils\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OpenRouterProvider extends OpenAIProvider {

	public function __construct() {
		$s = Settings::all();

		parent::__construct(
			'https://openrouter.ai/api/v1',
			(string) $s['openrouter_key'],
			'' !== (string) $s['openrouter_model'] ? (string) $s['openrouter_model'] : 'meta-llama/llama-3.3-70b-instruct:free'
		);
	}

	public function id(): string {
		return 'openrouter';
	}

	public function label(): string {
		return __( 'OpenRouter (free models available)', 'aifbp' );
	}

	public function generate_image( string $prompt ): string|\WP_Error {
		return new \WP_Error( 'aifbp_ai', __( 'Image generation is not available for the OpenRouter provider.', 'aifbp' ) );
	}
}
