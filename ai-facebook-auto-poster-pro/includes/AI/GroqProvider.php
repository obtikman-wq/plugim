<?php

namespace AIFB\AI;

use AIFB\Utils\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GroqProvider extends OpenAIProvider {

	public function __construct() {
		$s = Settings::all();

		parent::__construct(
			'https://api.groq.com/openai/v1',
			(string) $s['groq_key'],
			'' !== (string) $s['groq_model'] ? (string) $s['groq_model'] : 'llama-3.3-70b-versatile'
		);
	}

	public function id(): string {
		return 'groq';
	}

	public function label(): string {
		return __( 'Groq (free tier available)', 'aifbp' );
	}

	public function generate_image( string $prompt ): string|\WP_Error {
		return new \WP_Error( 'aifbp_ai', __( 'Image generation is not available for the Groq provider.', 'aifbp' ) );
	}
}
