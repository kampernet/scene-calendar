<?php
namespace SceneCalendar\Llm;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Settings;

/**
 * Build the configured LLM client, or null if no usable credentials.
 *
 * Swap in a custom client (tests, other providers) with the
 * `scene_calendar_llm_client` filter.
 */
final class Factory {

	public static function make( ?string $model = null ): ?Client {
		$provider = (string) Settings::get( 'provider' );
		$model    = $model ?? Settings::model();
		$client   = null;

		if ( 'anthropic' === $provider ) {
			$key = (string) Settings::get( 'anthropic_api_key' );
			if ( '' !== $key ) {
				$client = new Anthropic( $key, $model );
			}
		} else {
			$key  = (string) Settings::get( 'openai_api_key' );
			$base = (string) Settings::get( 'openai_base_url' );
			// A key is optional for self-hosted OpenAI-compatible servers (e.g. Ollama).
			if ( '' !== $key || ! str_contains( $base, 'api.openai.com' ) ) {
				$client = new OpenAI( $key, $model, $base );
			}
		}

		return apply_filters( 'scene_calendar_llm_client', $client, $provider, $model );
	}

	public static function make_vision(): ?Client {
		return self::make( Settings::vision_model() );
	}
}
