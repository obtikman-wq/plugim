<?php

namespace AIFB\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ProviderInterface {

	public function id(): string;

	public function label(): string;

	public function is_configured(): bool;

	public function captions( array $product, array $options ): array|\WP_Error;

	public function rewrite( string $text, string $language ): string|\WP_Error;

	public function analyze_image( string $image_url, string $prompt = '' ): string|\WP_Error;

	public function generate_image( string $prompt ): string|\WP_Error;
}
