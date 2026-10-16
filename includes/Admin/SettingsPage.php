<?php
namespace SceneCalendar\Admin;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Llm\Factory;
use SceneCalendar\Plugin;
use SceneCalendar\Settings;

/**
 * Settings: AI provider and model, API keys, schedule, scraping and data options.
 */
final class SettingsPage {

	public static function render(): void {
		$s    = Settings::all();
		$days = array( __( 'Sunday' ), __( 'Monday' ), __( 'Tuesday' ), __( 'Wednesday' ), __( 'Thursday' ), __( 'Friday' ), __( 'Saturday' ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- core strings.
		?>
		<div class="wrap scenecal-form">
			<h1><?php esc_html_e( 'Scene Calendar Settings', 'scene-calendar' ); ?></h1>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
				<input type="hidden" name="action" value="scenecal_save_settings">
				<?php wp_nonce_field( 'scenecal_save_settings' ); ?>

				<h2><?php esc_html_e( 'AI model', 'scene-calendar' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Used by the event-finding agent, duplicate detection, genre classification and poster reading. Direct parsers work without it.', 'scene-calendar' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ss-provider"><?php esc_html_e( 'Provider', 'scene-calendar' ); ?></label></th>
						<td>
							<select name="s[provider]" id="ss-provider">
								<option value="openai" <?php selected( $s['provider'], 'openai' ); ?>><?php esc_html_e( 'OpenAI (or OpenAI-compatible)', 'scene-calendar' ); ?></option>
								<option value="anthropic" <?php selected( $s['provider'], 'anthropic' ); ?>><?php esc_html_e( 'Anthropic (Claude)', 'scene-calendar' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ss-model"><?php esc_html_e( 'Model', 'scene-calendar' ); ?></label></th>
						<td>
							<input name="s[model]" id="ss-model" type="text" class="regular-text" value="<?php echo esc_attr( $s['model'] ); ?>" placeholder="<?php echo esc_attr( Settings::DEFAULT_MODELS[ $s['provider'] ] ?? '' ); ?>">
							<p class="description">
								<?php
								/* translators: 1: default OpenAI model, 2: default Anthropic model */
								printf( esc_html__( 'Leave blank for the default (%1$s for OpenAI, %2$s for Anthropic).', 'scene-calendar' ), esc_html( Settings::DEFAULT_MODELS['openai'] ), esc_html( Settings::DEFAULT_MODELS['anthropic'] ) );
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ss-vision"><?php esc_html_e( 'Vision model', 'scene-calendar' ); ?></label></th>
						<td>
							<input name="s[vision_model]" id="ss-vision" type="text" class="regular-text" value="<?php echo esc_attr( $s['vision_model'] ); ?>">
							<p class="description"><?php esc_html_e( 'For the poster-image parser. Leave blank to use the model above (it must accept images).', 'scene-calendar' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ss-base"><?php esc_html_e( 'OpenAI base URL', 'scene-calendar' ); ?></label></th>
						<td>
							<input name="s[openai_base_url]" id="ss-base" type="url" class="regular-text" value="<?php echo esc_attr( $s['openai_base_url'] ); ?>">
							<p class="description"><?php esc_html_e( 'Change to use an OpenAI-compatible server such as OpenRouter or a self-hosted Ollama (http://host:11434/v1).', 'scene-calendar' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'API keys', 'scene-calendar' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Keys are stored in the WordPress database and never shown again after saving. For better security, define them in wp-config.php instead, for example:', 'scene-calendar' ); ?>
					<code>define( 'SCENECAL_OPENAI_API_KEY', 'sk-…' );</code>
				</p>
				<table class="form-table" role="presentation">
					<?php
					self::secret_row( 'openai_api_key', __( 'OpenAI API key', 'scene-calendar' ), __( 'platform.openai.com → API keys.', 'scene-calendar' ) );
					self::secret_row( 'anthropic_api_key', __( 'Anthropic API key', 'scene-calendar' ), __( 'console.anthropic.com → API keys.', 'scene-calendar' ) );
					self::secret_row( 'apify_api_key', __( 'Apify API token', 'scene-calendar' ), __( 'Optional: reads venues\' recent Instagram posts with the apify/instagram-scraper actor (paid per result).', 'scene-calendar' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Web search', 'scene-calendar' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Optional. Lets the agent look up shows for venues without an events URL. A weekly run typically uses a few hundred searches a month.', 'scene-calendar' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ss-search"><?php esc_html_e( 'Provider', 'scene-calendar' ); ?></label></th>
						<td>
							<select name="s[search_provider]" id="ss-search">
								<?php
								$providers = array(
									'brave'   => __( 'Brave Search ($5 free credit/month ≈ 1,000 searches; card required)', 'scene-calendar' ),
									'tavily'  => __( 'Tavily (1,000 free searches/month, no card)', 'scene-calendar' ),
									'searxng' => __( 'SearXNG (self-hosted, free)', 'scene-calendar' ),
									'google'  => __( 'Google Custom Search (shuts down 1 Jan 2027)', 'scene-calendar' ),
									'none'    => __( 'None', 'scene-calendar' ),
								);
								foreach ( $providers as $value => $label ) :
									?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['search_provider'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<?php
					self::secret_row( 'brave_api_key', __( 'Brave Search API key', 'scene-calendar' ), __( 'api-dashboard.search.brave.com → API keys (Search plan).', 'scene-calendar' ) );
					self::secret_row( 'tavily_api_key', __( 'Tavily API key', 'scene-calendar' ), __( 'app.tavily.com → API keys.', 'scene-calendar' ) );
					?>
					<tr>
						<th scope="row"><label for="ss-searx"><?php esc_html_e( 'SearXNG URL', 'scene-calendar' ); ?></label></th>
						<td>
							<input name="s[searxng_url]" id="ss-searx" type="url" class="regular-text" placeholder="https://search.example.org" value="<?php echo esc_attr( $s['searxng_url'] ); ?>">
							<p class="description"><?php esc_html_e( 'Your instance must allow the JSON format (search.formats in settings.yml).', 'scene-calendar' ); ?></p>
						</td>
					</tr>
					<?php
					self::secret_row( 'google_api_key', __( 'Google API key', 'scene-calendar' ), __( 'Custom Search JSON API (existing customers only).', 'scene-calendar' ) );
					self::secret_row( 'google_cse_id', __( 'Google search engine ID', 'scene-calendar' ), __( 'The "cx" ID of a Programmable Search Engine set to search the entire web.', 'scene-calendar' ) );
					?>
				</table>
				<p>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'scenecal_test_llm', admin_url( 'admin-post.php' ) ), 'scenecal_test_llm' ) ); ?>"><?php esc_html_e( 'Test AI connection', 'scene-calendar' ); ?></a>
				</p>

				<h2><?php esc_html_e( 'Schedule', 'scene-calendar' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Weekly run', 'scene-calendar' ); ?></th>
						<td>
							<label><input type="checkbox" name="s[schedule_enabled]" value="1" <?php checked( $s['schedule_enabled'] ); ?>> <?php esc_html_e( 'Collect events automatically every', 'scene-calendar' ); ?></label>
							<select name="s[schedule_day]">
								<?php foreach ( $days as $i => $label ) : ?>
									<option value="<?php echo (int) $i; ?>" <?php selected( (int) $s['schedule_day'], $i ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php esc_html_e( 'at', 'scene-calendar' ); ?>
							<select name="s[schedule_hour]">
								<?php for ( $h = 0; $h < 24; $h++ ) : ?>
									<option value="<?php echo (int) $h; ?>" <?php selected( (int) $s['schedule_hour'], $h ); ?>><?php echo esc_html( sprintf( '%02d:00', $h ) ); ?></option>
								<?php endfor; ?>
							</select>
							<p class="description">
								<?php
								/* translators: %s: timezone name */
								printf( esc_html__( 'Site timezone: %s (Settings → General).', 'scene-calendar' ), esc_html( wp_timezone_string() ) );
								?>
							</p>
							<?php self::render_timezone_check(); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Reliable scheduling', 'scene-calendar' ); ?></th>
						<td>
							<p class="description"><?php esc_html_e( 'WP-Cron only fires when someone visits the site. On low-traffic sites, add a server cron job (e.g. in your host\'s control panel) that runs every 5 minutes:', 'scene-calendar' ); ?></p>
							<code>*/5 * * * * curl -s <?php echo esc_html( site_url( 'wp-cron.php?doing_wp_cron' ) ); ?> &gt;/dev/null</code>
							<p class="description"><?php esc_html_e( 'With SSH and WP-CLI you can also run a whole scrape in one go: wp scene-calendar run', 'scene-calendar' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Collection', 'scene-calendar' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ss-days"><?php esc_html_e( 'Look ahead', 'scene-calendar' ); ?></label></th>
						<td>
							<input name="s[days_ahead]" id="ss-days" type="number" min="0" class="small-text" value="<?php echo (int) $s['days_ahead']; ?>"> <?php esc_html_e( 'days (0 = every announced show)', 'scene-calendar' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Instagram', 'scene-calendar' ); ?></th>
						<td>
							<label><input type="checkbox" name="s[instagram_enabled]" value="1" <?php checked( $s['instagram_enabled'] ); ?>> <?php esc_html_e( 'Read recent posts for venues with an Instagram handle', 'scene-calendar' ); ?></label>
							<br><label><?php esc_html_e( 'Posts per venue', 'scene-calendar' ); ?> <input name="s[instagram_posts]" type="number" min="1" max="100" class="small-text" value="<?php echo (int) $s['instagram_posts']; ?>"></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Review', 'scene-calendar' ); ?></th>
						<td>
							<label><input type="checkbox" name="s[review_agent_events]" value="1" <?php checked( $s['review_agent_events'] ); ?>> <?php esc_html_e( 'Hold events found by the AI agent as "Pending review" instead of publishing them', 'scene-calendar' ); ?></label>
							<p class="description"><?php esc_html_e( 'The agent reads pages and Instagram posts written by others; review catches mistakes and anything planted to mislead it. Events from direct parsers are always published.', 'scene-calendar' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ss-steps"><?php esc_html_e( 'Agent step limit', 'scene-calendar' ); ?></label></th>
						<td>
							<input name="s[agent_max_steps]" id="ss-steps" type="number" min="2" max="30" class="small-text" value="<?php echo (int) $s['agent_max_steps']; ?>">
							<p class="description"><?php esc_html_e( 'Maximum model turns per venue. Higher finds more on complex sites but costs more.', 'scene-calendar' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Post-processing', 'scene-calendar' ); ?></th>
						<td>
							<label><input type="checkbox" name="s[dedup_enabled]" value="1" <?php checked( $s['dedup_enabled'] ); ?>> <?php esc_html_e( 'Detect duplicate listings with AI (e.g. a promoter and a venue listing the same show)', 'scene-calendar' ); ?></label><br>
							<label><input type="checkbox" name="s[classify_enabled]" value="1" <?php checked( $s['classify_enabled'] ); ?>> <?php esc_html_e( 'Extract lineups and classify genres with AI', 'scene-calendar' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Default location', 'scene-calendar' ); ?></th>
						<td>
							<input name="s[default_city]" type="text" placeholder="<?php esc_attr_e( 'City', 'scene-calendar' ); ?>" value="<?php echo esc_attr( $s['default_city'] ); ?>">
							<input name="s[default_province]" type="text" size="6" placeholder="<?php esc_attr_e( 'Prov./State', 'scene-calendar' ); ?>" value="<?php echo esc_attr( $s['default_province'] ); ?>">
							<input name="s[default_country]" type="text" placeholder="<?php esc_attr_e( 'Country', 'scene-calendar' ); ?>" value="<?php echo esc_attr( $s['default_country'] ); ?>">
							<p class="description"><?php esc_html_e( 'Pre-fills new venues and events.', 'scene-calendar' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Data', 'scene-calendar' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ss-purge"><?php esc_html_e( 'Keep past events', 'scene-calendar' ); ?></label></th>
						<td><input name="s[purge_past_days]" id="ss-purge" type="number" min="0" class="small-text" value="<?php echo (int) $s['purge_past_days']; ?>"> <?php esc_html_e( 'days (0 = forever)', 'scene-calendar' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Uninstall', 'scene-calendar' ); ?></th>
						<td><label><input type="checkbox" name="s[delete_data_on_uninstall]" value="1" <?php checked( $s['delete_data_on_uninstall'] ); ?>> <?php esc_html_e( 'Delete all events, venues and settings when the plugin is deleted', 'scene-calendar' ); ?></label></td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Displaying the calendar', 'scene-calendar' ); ?></h2>
			<p><?php esc_html_e( 'Add this shortcode to any page or post (or a Shortcode block):', 'scene-calendar' ); ?></p>
			<p><code>[scene_calendar]</code></p>
			<p class="description"><?php esc_html_e( 'Options: view="list|month", days="60", venue="Venue Name", genre="rock", filters="yes|no", limit="200", align="wide|full". Example: [scene_calendar view="month" align="wide"]', 'scene-calendar' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Show upcoming UTC-offset changes according to PHP's timezone database, so
	 * admins can spot stale data (e.g. Manitoba dropped standard time in 2026,
	 * but older PHP builds still switch America/Winnipeg to UTC-6).
	 */
	private static function render_timezone_check(): void {
		$tz          = wp_timezone();
		$now         = time();
		$transitions = $tz->getTransitions( $now, $now + YEAR_IN_SECONDS );
		$changes     = array();
		foreach ( array_slice( (array) $transitions, 1 ) as $t ) {
			$hours     = $t['offset'] / HOUR_IN_SECONDS;
			$changes[] = sprintf( '%s → UTC%s%s', wp_date( 'M j, Y', $t['ts'] ), $hours >= 0 ? '+' : '−', abs( $hours ) );
		}
		$current = $tz->getOffset( new \DateTime( 'now', $tz ) ) / HOUR_IN_SECONDS;
		?>
		<div class="scenecal-tzcheck">
			<p class="description">
				<?php
				printf(
					/* translators: 1: UTC offset, 2: PHP timezone database version */
					esc_html__( 'Times from ticketing APIs are converted using PHP\'s timezone data (version %2$s). Current offset: UTC%1$s.', 'scene-calendar' ),
					esc_html( ( $current >= 0 ? '+' : '−' ) . abs( $current ) ),
					esc_html( timezone_version_get() )
				);
				echo ' ';
				if ( $changes ) {
					/* translators: %s: list of dates and offsets */
					printf( esc_html__( 'Upcoming clock changes it expects: %s.', 'scene-calendar' ), esc_html( implode( ', ', $changes ) ) );
					echo ' ';
					esc_html_e( 'If your region no longer changes clocks (e.g. Manitoba since November 2026), these are wrong: set Settings → General → Timezone to the fixed offset (e.g. UTC−5) until your host updates PHP.', 'scene-calendar' );
				} else {
					esc_html_e( 'No clock changes expected in the next 12 months.', 'scene-calendar' );
				}
				?>
			</p>
		</div>
		<?php
	}

	private static function secret_row( string $key, string $label, string $help ): void {
		$override = Settings::secret_override( $key );
		$stored   = (string) ( Settings::all()[ $key ] ?? '' );
		?>
		<tr>
			<th scope="row"><label for="ss-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<?php if ( null !== $override ) : ?>
					<input type="text" class="regular-text" disabled value="<?php esc_attr_e( 'Set in wp-config.php / environment', 'scene-calendar' ); ?>">
				<?php else : ?>
					<input name="s[<?php echo esc_attr( $key ); ?>]" id="ss-<?php echo esc_attr( $key ); ?>" type="password" class="regular-text" autocomplete="new-password"
						placeholder="<?php echo '' !== $stored ? esc_attr( sprintf( /* translators: %s: last 4 characters */ __( 'Saved (…%s). Leave blank to keep.', 'scene-calendar' ), substr( $stored, -4 ) ) ) : ''; ?>">
					<?php if ( '' !== $stored ) : ?>
						<label class="scenecal-inline"><input type="checkbox" name="s[clear_<?php echo esc_attr( $key ); ?>]" value="1"> <?php esc_html_e( 'Remove', 'scene-calendar' ); ?></label>
					<?php endif; ?>
				<?php endif; ?>
				<p class="description"><?php echo esc_html( $help ); ?> <code><?php echo esc_html( Settings::SECRETS[ $key ] ); ?></code></p>
			</td>
		</tr>
		<?php
	}

	public static function handle_save(): void {
		Menu::guard( 'scenecal_save_settings' );
		$input = isset( $_POST['s'] ) && is_array( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in Settings::sanitize().
		Settings::save( Settings::sanitize( $input ) );
		Plugin::schedule_weekly( true );
		Menu::flash( __( 'Settings saved.', 'scene-calendar' ) );
		Menu::redirect( 'settings' );
	}

	public static function handle_test_llm(): void {
		Menu::guard( 'scenecal_test_llm' );
		$client = Factory::make();
		if ( ! $client ) {
			Menu::flash( __( 'No API key configured for the selected provider.', 'scene-calendar' ), 'error' );
			Menu::redirect( 'settings' );
		}
		try {
			$reply = trim( $client->complete( 'Reply with exactly: OK', 2000 ) );
			/* translators: 1: provider, 2: model, 3: reply text */
			Menu::flash( sprintf( esc_html__( 'Connected to %1$s / %2$s. Reply: %3$s', 'scene-calendar' ), esc_html( $client->provider() ), esc_html( $client->model() ), esc_html( mb_substr( $reply, 0, 100 ) ) ) );
		} catch ( \Throwable $e ) {
			Menu::flash( esc_html( $e->getMessage() ), 'error' );
		}
		Menu::redirect( 'settings' );
	}
}
