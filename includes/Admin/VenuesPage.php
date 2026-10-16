<?php
namespace SceneCalendar\Admin;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Llm\Factory;
use SceneCalendar\Parsers\Context;
use SceneCalendar\Parsers\Registry;
use SceneCalendar\Runner;
use SceneCalendar\Settings;
use SceneCalendar\Venues;

/**
 * Venues/promoters: list, add/edit (with parser selection and per-parser
 * settings), parser test, import/export.
 */
final class VenuesPage {

	public static function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['edit'] ) ) {
			self::render_form( absint( $_GET['edit'] ) );
			return;
		}
		$test = isset( $_GET['tested'] ) ? get_transient( 'scenecal_parser_test_' . get_current_user_id() ) : null;
		// phpcs:enable
		$venues  = Venues::all();
		$options = Registry::options();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Venues & Promoters', 'scene-calendar' ); ?></h1>
			<a href="<?php echo esc_url( Menu::url( 'venues', array( 'edit' => 0 ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add Venue', 'scene-calendar' ); ?></a>
			<hr class="wp-header-end">

			<?php if ( is_array( $test ) ) : ?>
				<?php self::render_test_result( $test ); ?>
			<?php endif; ?>

			<?php if ( ! $venues ) : ?>
				<div class="scenecal-empty">
					<p><?php esc_html_e( 'No venues yet. Add the venues and promoters whose shows you want to collect, or import a list.', 'scene-calendar' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width:24%"><?php esc_html_e( 'Name', 'scene-calendar' ); ?></th>
							<th><?php esc_html_e( 'Parser', 'scene-calendar' ); ?></th>
							<th><?php esc_html_e( 'AI agent', 'scene-calendar' ); ?></th>
							<th><?php esc_html_e( 'Sources', 'scene-calendar' ); ?></th>
							<th><?php esc_html_e( 'Last run', 'scene-calendar' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $venues as $v ) : ?>
						<?php
						$resolved = Registry::for_venue( $v );
						$parser   = $options[ $v['parser'] ] ?? $v['parser'];
						if ( Registry::AUTO === $v['parser'] ) {
							$parser .= ' → ' . ( $resolved ? $resolved::label() : __( 'none matched', 'scene-calendar' ) );
						}
						$actions = array(
							'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( Menu::url( 'venues', array( 'edit' => $v['id'] ) ) ), esc_html__( 'Edit', 'scene-calendar' ) ),
							'test'   => $resolved ? sprintf( '<a href="%s">%s</a>', esc_url( self::action_url( 'test', $v['id'] ) ), esc_html__( 'Test parser', 'scene-calendar' ) ) : '',
							'run'    => sprintf( '<a href="%s">%s</a>', esc_url( self::action_url( 'run', $v['id'] ) ), esc_html__( 'Run now', 'scene-calendar' ) ),
							'delete' => sprintf( '<a href="%s" class="scenecal-confirm-delete submitdelete">%s</a>', esc_url( self::action_url( 'delete', $v['id'] ) ), esc_html__( 'Delete', 'scene-calendar' ) ),
						);
						?>
						<tr class="<?php echo $v['enabled'] ? '' : 'scenecal-disabled'; ?>">
							<td>
								<strong><a href="<?php echo esc_url( Menu::url( 'venues', array( 'edit' => $v['id'] ) ) ); ?>"><?php echo esc_html( $v['name'] ); ?></a></strong>
								<?php if ( 'promoter' === $v['type'] ) : ?>
									<span class="scenecal-badge"><?php esc_html_e( 'promoter', 'scene-calendar' ); ?></span>
								<?php endif; ?>
								<?php if ( ! $v['enabled'] ) : ?>
									<span class="scenecal-badge"><?php esc_html_e( 'disabled', 'scene-calendar' ); ?></span>
								<?php endif; ?>
								<div class="row-actions"><?php echo implode( ' | ', array_filter( $actions ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></div>
							</td>
							<td><?php echo esc_html( $parser ); ?></td>
							<td><?php echo esc_html( $v['agent_mode'] ); ?></td>
							<td class="scenecal-sources">
								<?php if ( $v['url'] ) : ?>
									<a href="<?php echo esc_url( $v['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( preg_replace( '#^https?://(www\.)?#', '', $v['url'] ) ); ?></a><br>
								<?php endif; ?>
								<?php if ( $v['instagram'] ) : ?>
									<span>@<?php echo esc_html( $v['instagram'] ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $v['last_run_at'] ) : ?>
									<?php echo esc_html( get_date_from_gmt( $v['last_run_at'], 'M j, H:i' ) ); ?><br>
									<span class="scenecal-muted"><?php echo esc_html( $v['last_run_status'] ); ?></span>
								<?php else : ?>
									<span class="scenecal-muted">—</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Import / export', 'scene-calendar' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="scenecal-inline-form">
				<input type="hidden" name="action" value="scenecal_import_venues">
				<?php wp_nonce_field( 'scenecal_import_venues' ); ?>
				<input type="file" name="venues_file" accept=".json,application/json">
				<?php submit_button( __( 'Import JSON', 'scene-calendar' ), 'secondary', 'submit', false ); ?>
			</form>
			<p>
				<?php foreach ( glob( SCENECAL_DIR . 'data/example-venues-*.json' ) ?: array() as $file ) : ?>
					<?php $slug = basename( $file, '.json' ); ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'scenecal_import_venues', 'example' => $slug ), admin_url( 'admin-post.php' ) ), 'scenecal_import_venues' ) ); ?>">
						<?php
						/* translators: %s: example list name, e.g. "winnipeg" */
						printf( esc_html__( 'Import example list: %s', 'scene-calendar' ), esc_html( ucwords( str_replace( array( 'example-venues-', '-' ), array( '', ' ' ), $slug ) ) ) );
						?>
					</a>
				<?php endforeach; ?>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'scenecal_export_venues', admin_url( 'admin-post.php' ) ), 'scenecal_export_venues' ) ); ?>"><?php esc_html_e( 'Export venues as JSON', 'scene-calendar' ); ?></a>
			</p>
		</div>
		<?php
	}

	private static function action_url( string $do, int $id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'scenecal_venue_action',
					'do'     => $do,
					'id'     => $id,
				),
				admin_url( 'admin-post.php' )
			),
			'scenecal_venue_action'
		);
	}

	private static function render_test_result( array $test ): void {
		?>
		<div class="scenecal-test-result">
			<h2>
				<?php
				/* translators: 1: venue name, 2: parser label, 3: event count */
				printf( esc_html__( 'Parser test: %1$s via %2$s — %3$d events', 'scene-calendar' ), esc_html( $test['venue'] ), esc_html( $test['parser'] ), count( $test['events'] ) );
				?>
			</h2>
			<p class="description"><?php esc_html_e( 'Nothing was saved. These are the raw events the parser extracted before normalization and dedup.', 'scene-calendar' ); ?></p>
			<?php if ( $test['error'] ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $test['error'] ); ?></p></div>
			<?php endif; ?>
			<?php if ( $test['log'] ) : ?>
				<pre class="scenecal-log scenecal-log-short"><?php echo esc_html( implode( "\n", $test['log'] ) ); ?></pre>
			<?php endif; ?>
			<?php if ( $test['events'] ) : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Date', 'scene-calendar' ); ?></th><th><?php esc_html_e( 'Time', 'scene-calendar' ); ?></th><th><?php esc_html_e( 'Title', 'scene-calendar' ); ?></th><th><?php esc_html_e( 'Venue', 'scene-calendar' ); ?></th><th><?php esc_html_e( 'Price', 'scene-calendar' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( $test['events'], 0, 100 ) as $e ) : ?>
						<tr>
							<td><?php echo esc_html( $e['date'] ?? '' ); ?></td>
							<td><?php echo esc_html( $e['start_time'] ?? '' ); ?></td>
							<td><?php echo esc_html( $e['title'] ?? '' ); ?></td>
							<td><?php echo esc_html( $e['venue_name'] ?? '' ); ?></td>
							<td><?php echo esc_html( $e['ticket_price'] ?? '' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_form( int $id ): void {
		$venue = $id ? Venues::get( $id ) : null;
		$v     = $venue ?? array(
			'name'       => '',
			'type'       => 'venue',
			'city'       => Settings::get( 'default_city' ),
			'province'   => Settings::get( 'default_province' ),
			'country'    => Settings::get( 'default_country' ),
			'url'        => '',
			'instagram'  => '',
			'aliases'    => array(),
			'parser'     => Registry::AUTO,
			'agent_mode' => 'fallback',
			'meta'       => array(),
			'enabled'    => true,
			'sort_order' => Venues::count(),
		);
		$parsers = Registry::all();
		$fields  = Registry::config_fields();
		?>
		<div class="wrap scenecal-form">
			<h1><?php echo $venue ? esc_html__( 'Edit Venue', 'scene-calendar' ) : esc_html__( 'Add Venue', 'scene-calendar' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="scenecal_save_venue">
				<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
				<?php wp_nonce_field( 'scenecal_save_venue' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sv-name"><?php esc_html_e( 'Name', 'scene-calendar' ); ?> *</label></th>
						<td><input name="name" id="sv-name" type="text" class="regular-text" required value="<?php echo esc_attr( $v['name'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Type', 'scene-calendar' ); ?></th>
						<td>
							<label><input type="radio" name="type" value="venue" <?php checked( $v['type'], 'venue' ); ?>> <?php esc_html_e( 'Venue (a physical place)', 'scene-calendar' ); ?></label><br>
							<label><input type="radio" name="type" value="promoter" <?php checked( $v['type'], 'promoter' ); ?>> <?php esc_html_e( 'Promoter / listing source (events happen at other venues)', 'scene-calendar' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Location', 'scene-calendar' ); ?></th>
						<td>
							<input name="city" type="text" placeholder="<?php esc_attr_e( 'City', 'scene-calendar' ); ?>" value="<?php echo esc_attr( $v['city'] ); ?>">
							<input name="province" type="text" size="6" placeholder="<?php esc_attr_e( 'Prov./State', 'scene-calendar' ); ?>" value="<?php echo esc_attr( $v['province'] ); ?>">
							<input name="country" type="text" placeholder="<?php esc_attr_e( 'Country', 'scene-calendar' ); ?>" value="<?php echo esc_attr( $v['country'] ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sv-url"><?php esc_html_e( 'Events URL', 'scene-calendar' ); ?></label></th>
						<td>
							<input name="url" id="sv-url" type="url" class="large-text" placeholder="https://" value="<?php echo esc_attr( $v['url'] ); ?>">
							<p class="description"><?php esc_html_e( 'The page, feed or ticketing profile that lists this venue\'s shows.', 'scene-calendar' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sv-ig"><?php esc_html_e( 'Instagram', 'scene-calendar' ); ?></label></th>
						<td><input name="instagram" id="sv-ig" type="text" class="regular-text" placeholder="handle" value="<?php echo esc_attr( $v['instagram'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sv-aliases"><?php esc_html_e( 'Also known as', 'scene-calendar' ); ?></label></th>
						<td>
							<textarea name="aliases" id="sv-aliases" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'One name per line', 'scene-calendar' ); ?>"><?php echo esc_textarea( implode( "\n", $v['aliases'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Other spellings used by ticketing sites and promoters. Events listed under these names are filed under this venue and deduplicated.', 'scene-calendar' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sv-parser"><?php esc_html_e( 'Parser', 'scene-calendar' ); ?></label></th>
						<td>
							<select name="parser" id="sv-parser">
								<?php foreach ( Registry::options() as $slug => $label ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $v['parser'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<div class="scenecal-parser-help">
								<p data-parser="auto" class="description"><?php esc_html_e( 'Pick a parser by the URL (3common, Showpass, Eventbrite, FestApp, RSS feeds, .ics calendars). Choose one explicitly for other platforms.', 'scene-calendar' ); ?></p>
								<p data-parser="none" class="description"><?php esc_html_e( 'No direct parser: the AI agent reads the URL, Instagram and web search results.', 'scene-calendar' ); ?></p>
								<?php foreach ( $parsers as $slug => $class ) : ?>
									<p data-parser="<?php echo esc_attr( $slug ); ?>" class="description"><?php echo esc_html( $class::description() ); ?></p>
								<?php endforeach; ?>
							</div>
						</td>
					</tr>
					<?php foreach ( $fields as $key => $def ) : ?>
						<tr class="scenecal-parser-field" data-parsers="<?php echo esc_attr( implode( ' ', $def['parsers'] ) ); ?>">
							<th scope="row"><label for="sv-meta-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $def['label'] ); ?><?php echo ! empty( $def['required'] ) ? ' *' : ''; ?></label></th>
							<td>
								<input name="meta[<?php echo esc_attr( $key ); ?>]" id="sv-meta-<?php echo esc_attr( $key ); ?>" type="text" class="large-text" value="<?php echo esc_attr( $v['meta'][ $key ] ?? '' ); ?>">
								<?php if ( ! empty( $def['help'] ) ) : ?>
									<p class="description"><?php echo esc_html( $def['help'] ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr class="scenecal-parser-test-row">
						<th scope="row"></th>
						<td>
							<button type="button" class="button" id="scenecal-test-parser"><?php esc_html_e( 'Test parser', 'scene-calendar' ); ?></button>
							<span class="description"><?php esc_html_e( 'Fetches events with the settings above (unsaved) and shows a few. Nothing is stored.', 'scene-calendar' ); ?></span>
							<div id="scenecal-test-result" aria-live="polite"></div>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sv-agent"><?php esc_html_e( 'AI agent', 'scene-calendar' ); ?></label></th>
						<td>
							<select name="agent_mode" id="sv-agent">
								<option value="fallback" <?php selected( $v['agent_mode'], 'fallback' ); ?>><?php esc_html_e( 'Only if the parser finds nothing (or there is no parser)', 'scene-calendar' ); ?></option>
								<option value="always" <?php selected( $v['agent_mode'], 'always' ); ?>><?php esc_html_e( 'Always — also read Instagram posts (needs Apify) for shows the parser misses', 'scene-calendar' ); ?></option>
								<option value="never" <?php selected( $v['agent_mode'], 'never' ); ?>><?php esc_html_e( 'Never', 'scene-calendar' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'The agent uses your LLM API key and costs a few cents per venue per run.', 'scene-calendar' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Options', 'scene-calendar' ); ?></th>
						<td>
							<label><input type="checkbox" name="enabled" value="1" <?php checked( $v['enabled'] ); ?>> <?php esc_html_e( 'Include in scheduled runs', 'scene-calendar' ); ?></label><br>
							<label><?php esc_html_e( 'Order', 'scene-calendar' ); ?> <input name="sort_order" type="number" class="small-text" value="<?php echo (int) $v['sort_order']; ?>"></label>
						</td>
					</tr>
				</table>
				<?php submit_button( $venue ? __( 'Update Venue', 'scene-calendar' ) : __( 'Add Venue', 'scene-calendar' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Venue fields from the submitted form (sanitized), in Venues::save() format.
	 * Callers must have verified capability and nonce.
	 */
	private static function venue_from_request( int $id ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by callers.
		$parser = sanitize_key( wp_unslash( $_POST['parser'] ?? Registry::AUTO ) );
		if ( ! array_key_exists( $parser, Registry::options() ) ) {
			$parser = Registry::AUTO;
		}
		$meta = array();
		foreach ( (array) ( $_POST['meta'] ?? array() ) as $key => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per item.
			$meta[ sanitize_key( $key ) ] = Venues::sanitize_meta( (string) wp_unslash( $value ) );
		}
		// Keep meta keys that no current parser declares (e.g. "website" from imports).
		if ( $id ) {
			$existing = Venues::get( $id );
			if ( $existing ) {
				$meta = array_merge( array_diff_key( $existing['meta'], Registry::config_fields() ), $meta );
			}
		}
		$data = array(
			'name'       => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'type'       => sanitize_key( wp_unslash( $_POST['type'] ?? 'venue' ) ),
			'city'       => sanitize_text_field( wp_unslash( $_POST['city'] ?? '' ) ),
			'province'   => sanitize_text_field( wp_unslash( $_POST['province'] ?? '' ) ),
			'country'    => sanitize_text_field( wp_unslash( $_POST['country'] ?? '' ) ),
			'url'        => esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) ),
			'instagram'  => sanitize_text_field( wp_unslash( $_POST['instagram'] ?? '' ) ),
			'aliases'    => array_values( array_filter( array_map( 'sanitize_text_field', preg_split( '/\r?\n/', (string) wp_unslash( $_POST['aliases'] ?? '' ) ) ), 'strlen' ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'parser'     => $parser,
			'agent_mode' => sanitize_key( wp_unslash( $_POST['agent_mode'] ?? 'fallback' ) ),
			'meta'       => $meta,
			'enabled'    => ! empty( $_POST['enabled'] ),
			'sort_order' => (int) ( $_POST['sort_order'] ?? 0 ),
		);
		// phpcs:enable
		return $data;
	}

	/**
	 * Run a venue's parser without saving anything.
	 *
	 * @return array{venue:string, parser:string, events:array, log:string[], error:string}
	 */
	public static function run_parser_test( array $venue ): array {
		$parser = Registry::for_venue( $venue );
		$log    = array();
		$events = array();
		$error  = '';
		if ( ! $parser ) {
			$error = Registry::NONE === ( $venue['parser'] ?? '' )
				? __( 'No parser selected: this venue uses the AI agent only.', 'scene-calendar' )
				: __( 'No parser matched this URL. Choose a parser explicitly.', 'scene-calendar' );
		} else {
			$class = get_class( $parser );
			foreach ( $class::config_fields() as $key => $def ) {
				if ( ! empty( $def['required'] ) && '' === (string) ( $venue[ $key ] ?? '' ) ) {
					/* translators: %s: field label */
					$log[] = sprintf( __( 'Missing required setting: %s', 'scene-calendar' ), $def['label'] );
				}
			}
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			try {
				$ctx    = new Context(
					wp_date( 'Y-m-d' ),
					'9999-12-31',
					wp_timezone(),
					static function ( $line ) use ( &$log ) {
						$log[] = $line;
					},
					Factory::make_vision()
				);
				$events = $parser->parse( $venue, $ctx );
				usort( $events, static fn( $a, $b ) => strcmp( ( $a['date'] ?? '' ) . ( $a['start_time'] ?? '' ), ( $b['date'] ?? '' ) . ( $b['start_time'] ?? '' ) ) );
			} catch ( \Throwable $e ) {
				$error = $e->getMessage();
			}
		}
		return array(
			'venue'  => (string) ( $venue['name'] ?? '' ),
			'parser' => $parser ? $parser::label() : '—',
			'events' => $events,
			'log'    => $log,
			'error'  => $error,
		);
	}

	/** AJAX: test the parser with the venue form's current (unsaved) values. */
	public static function ajax_test_parser(): void {
		check_ajax_referer( 'scenecal_test_parser' );
		if ( ! current_user_can( \SceneCalendar\Plugin::CAP ) ) {
			wp_send_json_error( array( 'error' => __( 'You do not have permission to do that.', 'scene-calendar' ) ), 403 );
		}
		$id   = absint( $_POST['id'] ?? 0 );
		$data = self::venue_from_request( $id );
		// Same shape parsers get from Venues::hydrate(): parser settings flattened onto the venue.
		$venue = array_merge( $data['meta'], $data, array( 'id' => $id ) );
		$test  = self::run_parser_test( $venue );

		$preview = array();
		foreach ( array_slice( $test['events'], 0, 8 ) as $e ) {
			$preview[] = array(
				'date'  => (string) ( $e['date'] ?? '' ),
				'time'  => \SceneCalendar\Util\Text::parse_time( (string) ( $e['start_time'] ?? '' ) ) ?: (string) ( $e['start_time'] ?? '' ),
				'title' => (string) ( $e['title'] ?? '' ),
				'venue' => (string) ( $e['venue_name'] ?? '' ),
				'price' => (string) ( $e['ticket_price'] ?? '' ),
				'link'  => esc_url_raw( (string) ( $e['ticket_link'] ?? '' ) ),
			);
		}
		wp_send_json_success(
			array(
				'parser' => $test['parser'],
				'count'  => count( $test['events'] ),
				'events' => $preview,
				'log'    => array_slice( $test['log'], -20 ),
				'error'  => $test['error'],
			)
		);
	}

	public static function handle_save(): void {
		Menu::guard( 'scenecal_save_venue' );
		$id   = absint( $_POST['id'] ?? 0 );
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		if ( '' === $name ) {
			Menu::flash( __( 'Name is required.', 'scene-calendar' ), 'error' );
			Menu::redirect( 'venues', array( 'edit' => $id ) );
		}
		$saved = Venues::save( self::venue_from_request( $id ), $id ?: null );

		// Warn about required parser settings left blank.
		$venue   = Venues::get( $saved );
		$missing = array();
		$class   = Registry::all()[ $venue['parser'] ] ?? null;
		if ( $class ) {
			foreach ( $class::config_fields() as $key => $def ) {
				if ( ! empty( $def['required'] ) && empty( $venue['meta'][ $key ] ) ) {
					$missing[] = $def['label'];
				}
			}
		}
		if ( $missing ) {
			/* translators: %s: list of field labels */
			Menu::flash( sprintf( __( 'Venue saved, but the parser needs: %s', 'scene-calendar' ), esc_html( implode( ', ', $missing ) ) ), 'warning' );
		} else {
			Menu::flash( __( 'Venue saved.', 'scene-calendar' ) );
		}
		Menu::redirect( 'venues' );
	}

	public static function handle_action(): void {
		Menu::guard( 'scenecal_venue_action' );
		$do    = sanitize_key( $_GET['do'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id    = absint( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$venue = Venues::get( $id );
		if ( ! $venue ) {
			Menu::redirect( 'venues' );
		}

		if ( 'delete' === $do ) {
			Venues::delete( $id );
			Menu::flash( __( 'Venue deleted. Its events were kept.', 'scene-calendar' ) );
			Menu::redirect( 'venues' );
		}

		if ( 'run' === $do ) {
			$run = Runner::start( 'manual', array( $id ), array( 'skip_postprocess' => true ) );
			if ( is_wp_error( $run ) ) {
				Menu::flash( esc_html( $run->get_error_message() ), 'error' );
				Menu::redirect( 'venues' );
			}
			Menu::redirect( 'runs', array( 'run' => $run ) );
		}

		if ( 'test' === $do ) {
			set_transient( 'scenecal_parser_test_' . get_current_user_id(), self::run_parser_test( $venue ), 300 );
			Menu::redirect( 'venues', array( 'tested' => 1 ) );
		}
		Menu::redirect( 'venues' );
	}

	public static function handle_import(): void {
		Menu::guard( 'scenecal_import_venues' );
		$json = '';
		// phpcs:disable WordPress.Security.NonceVerification -- verified in guard().
		if ( ! empty( $_GET['example'] ) ) {
			$slug = sanitize_file_name( wp_unslash( $_GET['example'] ) );
			$path = SCENECAL_DIR . 'data/' . $slug . '.json';
			if ( str_starts_with( $slug, 'example-venues-' ) && is_readable( $path ) ) {
				$json = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
		} elseif ( ! empty( $_FILES['venues_file']['tmp_name'] ) && is_uploaded_file( $_FILES['venues_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$json = (string) file_get_contents( $_FILES['venues_file']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		// phpcs:enable
		$count = '' !== $json ? Venues::import_json( $json ) : 0;
		/* translators: %d: number of venues */
		Menu::flash( sprintf( _n( 'Imported %d venue (existing names are skipped).', 'Imported %d venues (existing names are skipped).', $count, 'scene-calendar' ), $count ), $count ? 'success' : 'warning' );
		Menu::redirect( 'venues' );
	}

	public static function handle_export(): void {
		Menu::guard( 'scenecal_export_venues' );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="scene-calendar-venues-' . wp_date( 'Y-m-d' ) . '.json"' );
		echo Venues::export_json(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
		exit;
	}
}
