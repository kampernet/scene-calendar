<?php
namespace SceneCalendar;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings stored in the `scenecal_settings` option.
 *
 * API keys can be supplied three ways, highest priority first:
 *   1. a constant in wp-config.php, e.g. define( 'SCENECAL_OPENAI_API_KEY', 'sk-...' );
 *   2. an environment variable of the same name
 *   3. the Settings screen (stored in wp_options)
 */
final class Settings {

	const OPTION = 'scenecal_settings';

	/** Secret settings and the constant/env name that overrides each. */
	const SECRETS = array(
		'openai_api_key'    => 'SCENECAL_OPENAI_API_KEY',
		'anthropic_api_key' => 'SCENECAL_ANTHROPIC_API_KEY',
		'google_api_key'    => 'SCENECAL_GOOGLE_API_KEY',
		'google_cse_id'     => 'SCENECAL_GOOGLE_CSE_ID',
		'apify_api_key'     => 'SCENECAL_APIFY_API_KEY',
		'brave_api_key'     => 'SCENECAL_BRAVE_API_KEY',
		'tavily_api_key'    => 'SCENECAL_TAVILY_API_KEY',
	);

	const SEARCH_PROVIDERS = array( 'brave', 'tavily', 'searxng', 'google', 'none' );

	const DEFAULT_MODELS = array(
		'openai'    => 'gpt-5-mini',
		'anthropic' => 'claude-haiku-4-5',
	);

	public static function defaults(): array {
		return array(
			'provider'                 => 'openai',
			'model'                    => '',
			'vision_model'             => '',
			'openai_base_url'          => 'https://api.openai.com/v1',
			'openai_api_key'           => '',
			'anthropic_api_key'        => '',
			'google_api_key'           => '',
			'google_cse_id'            => '',
			'apify_api_key'            => '',
			'search_provider'          => 'brave',
			'brave_api_key'            => '',
			'tavily_api_key'           => '',
			'searxng_url'              => '',
			'schedule_enabled'         => true,
			'schedule_day'             => 1, // Monday.
			'schedule_hour'            => 3,
			'days_ahead'               => 0, // 0 = all announced shows.
			'instagram_enabled'        => true,
			'instagram_posts'          => 30,
			'agent_max_steps'          => 10,
			'review_agent_events'      => false,
			'dedup_enabled'            => true,
			'classify_enabled'         => true,
			'default_city'             => '',
			'default_province'         => '',
			'default_country'          => '',
			'purge_past_days'          => 365,
			'delete_data_on_uninstall' => false,
		);
	}

	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		// Installs from before the search-provider setting keep using Google if they had keys.
		if ( ! isset( $stored['search_provider'] ) && ! empty( $stored['google_api_key'] ) && ! empty( $stored['google_cse_id'] ) ) {
			$stored['search_provider'] = 'google';
		}
		return array_merge( self::defaults(), $stored );
	}

	/** Get one setting; secrets resolve constant -> env -> option. */
	public static function get( string $key ) {
		if ( isset( self::SECRETS[ $key ] ) ) {
			$override = self::secret_override( $key );
			if ( null !== $override ) {
				return $override;
			}
		}
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/** The constant/env value for a secret, or null if not overridden. */
	public static function secret_override( string $key ): ?string {
		$name = self::SECRETS[ $key ] ?? null;
		if ( ! $name ) {
			return null;
		}
		if ( defined( $name ) && '' !== (string) constant( $name ) ) {
			return (string) constant( $name );
		}
		$env = getenv( $name );
		return ( false !== $env && '' !== $env ) ? $env : null;
	}

	/** Model id to use, falling back to the provider default. */
	public static function model(): string {
		$model = trim( (string) self::get( 'model' ) );
		return '' !== $model ? $model : ( self::DEFAULT_MODELS[ self::get( 'provider' ) ] ?? 'gpt-5-mini' );
	}

	public static function vision_model(): string {
		$model = trim( (string) self::get( 'vision_model' ) );
		return '' !== $model ? $model : self::model();
	}

	/**
	 * Sanitize a submitted settings array. Blank secret fields keep the stored
	 * value (the form never echoes keys back); a "clear" checkbox removes them.
	 */
	public static function sanitize( array $input ): array {
		$current = self::all();
		$out     = $current;

		$out['provider']        = in_array( $input['provider'] ?? '', array( 'openai', 'anthropic' ), true ) ? $input['provider'] : 'openai';
		$out['model']           = sanitize_text_field( $input['model'] ?? '' );
		$out['vision_model']    = sanitize_text_field( $input['vision_model'] ?? '' );
		$base                   = esc_url_raw( trim( $input['openai_base_url'] ?? '' ) );
		$out['openai_base_url'] = '' !== $base ? untrailingslashit( $base ) : 'https://api.openai.com/v1';

		$out['search_provider'] = in_array( $input['search_provider'] ?? '', self::SEARCH_PROVIDERS, true ) ? $input['search_provider'] : 'brave';
		$searx                  = esc_url_raw( trim( $input['searxng_url'] ?? '' ) );
		$out['searxng_url']     = '' !== $searx ? preg_replace( '#/search$#', '', untrailingslashit( $searx ) ) : '';

		foreach ( array_keys( self::SECRETS ) as $key ) {
			if ( ! empty( $input[ "clear_$key" ] ) ) {
				$out[ $key ] = '';
			} elseif ( isset( $input[ $key ] ) && '' !== trim( $input[ $key ] ) ) {
				$out[ $key ] = trim( sanitize_text_field( $input[ $key ] ) );
			}
		}

		foreach ( array( 'schedule_enabled', 'instagram_enabled', 'review_agent_events', 'dedup_enabled', 'classify_enabled', 'delete_data_on_uninstall' ) as $key ) {
			$out[ $key ] = ! empty( $input[ $key ] );
		}

		$out['schedule_day']    = min( 6, max( 0, (int) ( $input['schedule_day'] ?? 1 ) ) );
		$out['schedule_hour']   = min( 23, max( 0, (int) ( $input['schedule_hour'] ?? 3 ) ) );
		$out['days_ahead']      = max( 0, (int) ( $input['days_ahead'] ?? 0 ) );
		$out['instagram_posts'] = min( 100, max( 1, (int) ( $input['instagram_posts'] ?? 30 ) ) );
		$out['agent_max_steps'] = min( 30, max( 2, (int) ( $input['agent_max_steps'] ?? 10 ) ) );
		$out['purge_past_days'] = max( 0, (int) ( $input['purge_past_days'] ?? 365 ) );

		foreach ( array( 'default_city', 'default_province', 'default_country' ) as $key ) {
			$out[ $key ] = sanitize_text_field( $input[ $key ] ?? '' );
		}

		return $out;
	}

	public static function save( array $values ): void {
		// Autoloaded: read on every request by the cron schedule check.
		update_option( self::OPTION, $values, true );
	}

	/** Human-readable list of what's missing for a scrape run to work well. */
	public static function readiness(): array {
		$issues   = array();
		$provider = self::get( 'provider' );
		if ( 'openai' === $provider && ! self::get( 'openai_api_key' ) && str_contains( (string) self::get( 'openai_base_url' ), 'api.openai.com' ) ) {
			$issues[] = __( 'No OpenAI API key: the AI agent, dedup and genre classification will be skipped.', 'scene-calendar' );
		}
		if ( 'anthropic' === $provider && ! self::get( 'anthropic_api_key' ) ) {
			$issues[] = __( 'No Anthropic API key: the AI agent, dedup and genre classification will be skipped.', 'scene-calendar' );
		}
		if ( 'none' !== self::get( 'search_provider' ) && ! \SceneCalendar\Agent\Search::configured() ) {
			$issues[] = __( 'Web search is not configured (Settings → Web search): the agent cannot look up venues that have no events URL.', 'scene-calendar' );
		}
		if ( self::get( 'instagram_enabled' ) && ! self::get( 'apify_api_key' ) ) {
			$issues[] = __( 'No Apify API key: Instagram posts will not be read.', 'scene-calendar' );
		}
		return $issues;
	}
}
