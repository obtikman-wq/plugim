<?php

namespace AIFB\AI;

use AIFB\Utils\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CustomProvider extends OpenAIProvider {

	public function __construct() {
		$s = Settings::all();

		parent::__construct(
			'' !== $s['custom_base'] ? $s['custom_base'] : 'https://api.openai.com/v1',
			$s['custom_key'],
			'' !== $s['custom_model'] ? $s['custom_model'] : 'gpt-4o-mini'
		);
	}

	public function id(): string {
		return 'custom';
	}

	public function label(): string {
		return __( 'Custom OpenAI-compatible endpoint', 'aifbp' );
	}

	public function generate_image( string $prompt ): string|\WP_Error {
		return new \WP_Error( 'aifbp_ai', __( 'Image generation is not available for the custom provider.', 'aifbp' ) );
	}
}
