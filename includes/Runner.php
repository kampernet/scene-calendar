<?php
namespace SceneCalendar;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Agent\ShowsAgent;
use SceneCalendar\Agent\Tools;
use SceneCalendar\Llm\Factory;
use SceneCalendar\Parsers\Context;
use SceneCalendar\Parsers\Registry;
use SceneCalendar\Pipeline\Classify;
use SceneCalendar\Pipeline\Dedup;
use SceneCalendar\Pipeline\Processor;

/**
 * Runs a scrape as a sequence of small, resumable steps so it works on shared
 * hosting with short PHP time limits.
 *
 * A run row holds the whole state (queue position, the agent conversation, ...).
 * Each tick takes a lock, performs steps until its time budget is spent, saves
 * state, and kicks off the next tick with a non-blocking loopback request. A
 * cron healthcheck every 5 minutes restarts a stalled run, and a step that
 * keeps dying (fatal error, timeout) is skipped after MAX_ATTEMPTS.
 *
 * Phases: venues -> lineups -> dedup -> genres -> cleanup -> done.
 */
final class Runner {

	const LOCK_OPTION  = 'scenecal_lock';
	const TOKEN_OPTION = 'scenecal_tick_token';
	const MAX_ATTEMPTS = 2;
	const LOG_MAX      = 300000;

	/** @var array|null the run being processed in this request */
	private static ?array $run = null;
	private static array $log_buffer = array();

	// -- Public API -----------------------------------------------------------

	public static function active_run(): ?array {
		global $wpdb;
		$table = Db::runs();
		$row   = $wpdb->get_row( "SELECT * FROM $table WHERE status IN ('queued','running') ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::hydrate( $row ) : null;
	}

	public static function get_run( int $id ): ?array {
		global $wpdb;
		$table = Db::runs();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::hydrate( $row ) : null;
	}

	public static function recent_runs( int $limit = 20 ): array {
		global $wpdb;
		$table = Db::runs();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, status, run_trigger, phase, started_at, finished_at, heartbeat_at, stats FROM $table ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map(
			static function ( $row ) {
				$row['stats'] = json_decode( $row['stats'] ?: '{}', true ) ?: array();
				return $row;
			},
			$rows ?: array()
		);
	}

	/**
	 * Queue a new run. Returns the run id, or \WP_Error if one is already active.
	 *
	 * @param string     $trigger   cron | manual | cli
	 * @param int[]|null $venue_ids Limit to these venues (null = all enabled).
	 * @param array      $options   'skip_postprocess' => bool
	 * @return int|\WP_Error
	 */
	public static function start( string $trigger, ?array $venue_ids = null, array $options = array() ) {
		global $wpdb;
		if ( self::active_run() ) {
			return new \WP_Error( 'scenecal_busy', __( 'A run is already in progress.', 'scene-calendar' ) );
		}
		$venues = Venues::all( true );
		if ( null !== $venue_ids ) {
			$venues = array_filter( $venues, static fn( $v ) => in_array( $v['id'], $venue_ids, true ) );
		}
		$today = wp_date( 'Y-m-d' );
		$days  = (int) Settings::get( 'days_ahead' );
		$state = array(
			'start'            => $today,
			'end'              => $days > 0 ? wp_date( 'Y-m-d', strtotime( "+$days days" ) - DAY_IN_SECONDS ) : '',
			'venue_ids'        => array_values( array_map( static fn( $v ) => $v['id'], $venues ) ),
			'venue_index'      => 0,
			'stage'            => 'parser',
			'parser_found'     => 0,
			'instagram'        => '',
			'agent'            => null,
			'attempts'         => 0,
			'dedup_dates'      => null,
			'dedup_index'      => 0,
			'attempted'        => array(),
			'failures'         => 0,
			'skip_postprocess' => ! empty( $options['skip_postprocess'] ),
		);
		$now   = Db::now();
		$wpdb->insert(
			Db::runs(),
			array(
				'status'       => 'queued',
				'run_trigger'  => $trigger,
				'phase'        => 'venues',
				'started_at'   => $now,
				'heartbeat_at' => $now,
				'state'        => wp_json_encode( $state ),
				'stats'        => wp_json_encode( self::empty_stats() ),
				'log'          => '',
			)
		);
		$id = (int) $wpdb->insert_id;
		self::append_log_direct( $id, sprintf( 'Run queued (%s): %d venues, %s %s', $trigger, count( $state['venue_ids'] ), $state['start'], $state['end'] ? "to {$state['end']}" : 'onward' ) );
		if ( 'cli' !== $trigger ) {
			self::dispatch();
		}
		return $id;
	}

	public static function cancel( int $id ): void {
		global $wpdb;
		$wpdb->update(
			Db::runs(),
			array(
				'status'      => 'cancelled',
				'finished_at' => Db::now(),
			),
			array( 'id' => $id )
		);
		self::append_log_direct( $id, 'Run cancelled by ' . ( wp_get_current_user()->user_login ?: 'system' ) );
	}

	/**
	 * Process the active run for up to $budget seconds (0 = until finished).
	 *
	 * @return bool|null true = work remains, false = no active run / finished,
	 *                   null = another process holds the lock (callers must NOT
	 *                   re-dispatch on null, or ticks would loop).
	 */
	public static function tick( int $budget = 45 ): ?bool {
		if ( ! self::lock() ) {
			return null;
		}
		// A fatal error (memory, time limit) skips `finally`; release the lock on shutdown too.
		register_shutdown_function( array( self::class, 'unlock' ) );

		$more = false;
		try {
			$run = self::active_run();
			if ( ! $run ) {
				return false;
			}
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 0 === $budget ? 0 : $budget + 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			ignore_user_abort( true );

			self::$run        = $run;
			self::$log_buffer = array();
			if ( 'queued' === $run['status'] ) {
				self::$run['status'] = 'running';
			}

			$started = microtime( true );
			do {
				// Persist the attempt counter BEFORE working so a fatal error is noticed next tick.
				++self::$run['state']['attempts'];
				if ( self::$run['state']['attempts'] > self::MAX_ATTEMPTS ) {
					self::log( 'Step failed repeatedly (fatal error or timeout?); skipping it.' );
					self::skip_step();
				}
				self::save();

				self::step();
				self::$run['state']['attempts'] = 0;
				self::save();

				$done = in_array( self::$run['status'], array( 'done', 'failed', 'cancelled' ), true ) || self::cancelled_elsewhere();
			} while ( ! $done && ( 0 === $budget || microtime( true ) - $started < $budget ) );

			$more = ! $done;
		} catch ( LockLost $e ) {
			// Another process owns the run now; leave the state to it.
			self::$lock_owner = '';
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				\WP_CLI::warning( $e->getMessage() );
			}
			$more = null;
		} catch ( \Throwable $e ) {
			self::log( 'Unexpected error: ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
			if ( self::$run ) {
				self::$run['state']['attempts'] = max( 1, self::$run['state']['attempts'] );
				self::save();
			}
			$more = true;
		} finally {
			self::$run = null;
			self::unlock();
		}
		return $more;
	}

	// -- Steps ------------------------------------------------------------------

	private static function step(): void {
		switch ( self::$run['phase'] ) {
			case 'venues':
				self::step_venue();
				break;
			case 'dedup':
				self::step_dedup();
				break;
			case 'lineups':
				self::step_lineups();
				break;
			case 'genres':
				self::step_genres();
				break;
			case 'cleanup':
				self::step_cleanup();
				break;
			default:
				self::finish( 'done' );
		}
	}

	/** Advance past the current step after repeated failures. */
	private static function skip_step(): void {
		$s = &self::$run['state'];
		++self::$run['stats']['errors'];
		$s['attempts'] = 0;
		switch ( self::$run['phase'] ) {
			case 'venues':
				if ( in_array( $s['stage'], array( 'parser', 'instagram' ), true ) ) {
					$s['stage'] = 'parser' === $s['stage'] ? self::after_parser_stage( self::current_venue(), null, 0 ) : 'agent_begin';
				} else {
					$s['stage'] = 'next';
				}
				break;
			case 'dedup':
				++$s['dedup_index'];
				break;
			default:
				self::next_phase();
		}
	}

	private static function current_venue(): ?array {
		$s  = self::$run['state'];
		$id = $s['venue_ids'][ $s['venue_index'] ] ?? null;
		return $id ? Venues::get( (int) $id ) : null;
	}

	private static function context(): Context {
		$s = self::$run['state'];
		return new Context(
			$s['start'],
			'' !== $s['end'] ? $s['end'] : '9999-12-31',
			wp_timezone(),
			array( self::class, 'log' ),
			Factory::make_vision()
		);
	}

	private static function step_venue(): void {
		$s = &self::$run['state'];
		if ( $s['venue_index'] >= count( $s['venue_ids'] ) ) {
			self::next_phase();
			return;
		}
		$venue = self::current_venue();
		if ( ! $venue ) {
			$s['stage'] = 'next';
		}

		switch ( $s['stage'] ) {
			case 'parser':
				self::log( sprintf( '== [%d/%d] %s', $s['venue_index'] + 1, count( $s['venue_ids'] ), $venue['name'] ) );
				$parser = Registry::for_venue( $venue );
				$found  = 0;
				if ( $parser ) {
					try {
						$raw   = $parser->parse( $venue, self::context() );
						$found = count( $raw );
						self::log( sprintf( '  parser %s: %d events', $parser::slug(), $found ) );
						if ( $raw ) {
							self::process( $raw, $venue, $venue['name'] . ' (' . $parser::slug() . ')' );
						}
					} catch ( \Throwable $e ) {
						self::log( '  parser ' . $parser::slug() . ' failed: ' . $e->getMessage() );
						++self::$run['stats']['errors'];
					}
				}
				$s['parser_found'] = $found;
				$s['stage']        = self::after_parser_stage( $venue, $parser, $found );
				break;

			case 'instagram':
				$s['instagram'] = '';
				if ( Settings::get( 'instagram_enabled' ) && '' !== $venue['instagram'] && Settings::get( 'apify_api_key' ) ) {
					try {
						$s['instagram'] = Tools::instagram_digest( $venue['instagram'], (int) Settings::get( 'instagram_posts' ) );
						self::log( '  instagram @' . $venue['instagram'] . ': ' . ( '' === $s['instagram'] ? 'no posts' : substr_count( $s['instagram'], '--- Post' ) . ' posts' ) );
					} catch ( \Throwable $e ) {
						self::log( '  instagram failed: ' . $e->getMessage() );
					}
				}
				$s['stage'] = 'agent_begin';
				break;

			case 'agent_begin':
				// "Always" exists to catch shows announced only on Instagram; without
				// posts to read, a parser that already found events is enough.
				if ( 'always' === $venue['agent_mode'] && $s['parser_found'] > 0 && '' === $s['instagram'] ) {
					self::log( '  agent skipped: parser found events and no Instagram posts were available' );
					$s['stage'] = 'next';
					break;
				}
				$llm = Factory::make();
				if ( ! $llm ) {
					self::log( '  agent skipped: no LLM configured' );
					$s['stage'] = 'next';
					break;
				}
				if ( '' === $venue['url'] && '' === $s['instagram'] && ! Tools::has_search() ) {
					self::log( '  agent skipped: no URL, no Instagram posts and web search not configured' );
					$s['stage'] = 'next';
					break;
				}
				$s['agent']     = ShowsAgent::begin( $llm, $venue, $s['start'], $s['end'], $s['instagram'], (int) Settings::get( 'agent_max_steps' ) );
				$s['instagram'] = '';
				$s['stage']     = 'agent';
				self::log( '  agent started (' . $llm->provider() . '/' . $llm->model() . ')' );
				break;

			case 'agent':
				$llm = Factory::make();
				if ( ! $llm || empty( $s['agent'] ) ) {
					$s['stage'] = 'next';
					break;
				}
				try {
					$s['agent'] = ShowsAgent::advance( $llm, $s['agent'] );
				} catch ( \Throwable $e ) {
					self::log( '  agent error: ' . $e->getMessage() );
					++self::$run['stats']['errors'];
					$s['agent'] = null;
					$s['stage'] = 'next';
					break;
				}
				foreach ( $s['agent']['log'] as $line ) {
					self::log( '  ' . $line );
				}
				if ( $s['agent']['done'] ) {
					$events           = $s['agent']['events'];
					$s['agent']       = null;
					$s['agent_found'] = count( $events );
					if ( $events ) {
						self::process( $events, $venue, $venue['name'] . ' (agent)', Settings::get( 'review_agent_events' ) ? 'pending' : 'publish' );
					}
					$s['stage'] = 'next';
				}
				break;

			case 'next':
			default:
				if ( $venue ) {
					Venues::record_run( $venue['id'], sprintf( 'run #%d: parser found %d, agent found %d', self::$run['id'], $s['parser_found'], $s['agent_found'] ?? 0 ) );
				}
				++self::$run['stats']['venues_done'];
				++$s['venue_index'];
				$s['stage']        = 'parser';
				$s['parser_found'] = 0;
				$s['agent_found']  = 0;
				$s['agent']        = null;
				$s['instagram']    = '';
				break;
		}
	}

	/** Decide what follows the parser stage for a venue. */
	private static function after_parser_stage( ?array $venue, $parser, int $found ): string {
		if ( ! $venue ) {
			return 'next';
		}
		$mode = $venue['agent_mode'];
		if ( 'never' === $mode ) {
			return 'next';
		}
		if ( 'fallback' === $mode && $parser && $found > 0 ) {
			return 'next';
		}
		return '' !== $venue['instagram'] ? 'instagram' : 'agent_begin';
	}

	private static function process( array $raw, array $venue, string $found_by, string $status = 'publish' ): void {
		$s     = self::$run['state'];
		$llm   = Settings::get( 'dedup_enabled' ) ? Factory::make() : null;
		$stats = Processor::run( $raw, $venue, $s['start'], $s['end'], $llm, $found_by, array( self::class, 'log' ), $status );
		foreach ( $stats as $key => $n ) {
			self::$run['stats'][ $key ] = ( self::$run['stats'][ $key ] ?? 0 ) + $n;
		}
		self::log( sprintf( '  stored: %d new%s, %d updated, %d unchanged, %d skipped (admin-edited/removed), %d invalid, %d fuzzy duplicates', $stats['inserted'], 'pending' === $status ? ' (pending review)' : '', $stats['enriched'], $stats['seen'], $stats['skipped'], $stats['invalid'], $stats['duplicates'] ) );
		if ( $stats['failed'] ) {
			self::log( sprintf( '  %d events could not be stored (see above)', $stats['failed'] ) );
			self::$run['stats']['errors'] += $stats['failed'];
		}
	}

	private static function step_dedup(): void {
		$s = &self::$run['state'];
		if ( ! Settings::get( 'dedup_enabled' ) ) {
			self::next_phase();
			return;
		}
		if ( null === $s['dedup_dates'] ) {
			global $wpdb;
			$table            = Db::events();
			$s['dedup_dates'] = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT event_date FROM $table WHERE status = 'publish' AND event_date >= %s ORDER BY event_date", $s['start'] ) ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$s['dedup_index'] = 0;
			self::log( sprintf( '== Dedup: %d dates with upcoming events', count( $s['dedup_dates'] ) ) );
		}
		if ( $s['dedup_index'] >= count( $s['dedup_dates'] ) ) {
			self::next_phase();
			return;
		}
		$date = $s['dedup_dates'][ $s['dedup_index'] ];
		$next = gmdate( 'Y-m-d', strtotime( $date . ' 12:00:00 UTC' ) + DAY_IN_SECONDS );
		$win  = Events::query( array( 'dates' => array( $date, $next ) ) );
		if ( count( $win ) >= 2 ) {
			$groups = Dedup::window_groups( Factory::make(), $win, array( self::class, 'log' ) );
			if ( $groups ) {
				self::$run['stats']['marked_duplicate'] += Dedup::resolve( $groups, array( self::class, 'log' ) );
			}
		}
		++$s['dedup_index'];
	}

	private static function step_lineups(): void {
		$s   = &self::$run['state'];
		$llm = Settings::get( 'classify_enabled' ) ? Factory::make() : null;
		if ( ! $llm ) {
			self::next_phase();
			return;
		}
		$todo = array_values(
			array_filter(
				Events::query(
					array(
						'from'           => $s['start'],
						'lineup_missing' => true,
						'unlocked'       => true, // Never rewrite an editor's lineup.
						'limit'          => 500,
					)
				),
				static fn( $e ) => ! in_array( $e['id'], $s['attempted'], true )
			)
		);
		if ( ! $todo ) {
			self::next_phase();
			return;
		}
		$batch          = array_slice( $todo, 0, Classify::BATCH );
		$s['attempted'] = array_merge( $s['attempted'], array_column( $batch, 'id' ) );
		// Classify::lineups() marks events the model answered as checked, so titles
		// without artists aren't re-sent weekly; failed calls leave them for next run.
		$n              = Classify::lineups( $llm, $batch, array( self::class, 'log' ) );
		self::log( sprintf( '  lineups: extracted for %d of %d events', $n, count( $batch ) ) );
	}

	private static function step_genres(): void {
		$s   = &self::$run['state'];
		$llm = Settings::get( 'classify_enabled' ) ? Factory::make() : null;
		if ( ! $llm ) {
			self::next_phase();
			return;
		}
		$batch = Events::query(
			array(
				'from'          => $s['start'],
				'genre_missing' => true,
				'limit'         => Classify::BATCH,
			)
		);
		if ( ! $batch || $s['failures'] >= 2 ) {
			self::next_phase();
			return;
		}
		$n = Classify::genres( $llm, $batch, array( self::class, 'log' ) );
		if ( 0 === $n ) {
			++$s['failures'];
		}
		self::$run['stats']['classified'] += $n;
		self::log( sprintf( '  genres: classified %d events', $n ) );
	}

	private static function step_cleanup(): void {
		$purged = Events::purge_past( (int) Settings::get( 'purge_past_days' ), self::$run['state']['start'] );
		if ( $purged ) {
			self::log( "  purged $purged old events" );
		}
		self::finish( 'done' );
	}

	private static function next_phase(): void {
		// Lineups first: the dedup pre-filter compares lineups.
		$order = array( 'venues', 'lineups', 'dedup', 'genres', 'cleanup' );
		$i     = array_search( self::$run['phase'], $order, true );
		$next  = $order[ $i + 1 ] ?? 'cleanup';
		if ( self::$run['state']['skip_postprocess'] && 'venues' === self::$run['phase'] ) {
			$next = 'cleanup';
		}
		self::$run['phase']                 = $next;
		self::$run['state']['attempted']    = array();
		self::$run['state']['failures']     = 0;
		self::$run['state']['attempts']     = 0;
		if ( in_array( $next, array( 'lineups', 'genres' ), true ) && Settings::get( 'classify_enabled' ) ) {
			self::log( '== ' . ucfirst( $next ) );
		}
	}

	private static function finish( string $status ): void {
		self::$run['status']      = $status;
		self::$run['finished_at'] = Db::now();
		$st                       = self::$run['stats'];
		self::log( sprintf( 'Run %s: %d venues, %d new events, %d updated, %d duplicates marked, %d classified, %d errors; %d AI calls, %s input + %s output tokens', $status, $st['venues_done'], $st['inserted'], $st['enriched'], $st['marked_duplicate'], $st['classified'], $st['errors'], $st['llm_calls'], number_format_i18n( $st['tokens_in'] ), number_format_i18n( $st['tokens_out'] ) ) );
		do_action( 'scene_calendar_run_finished', self::$run['id'], $status, $st );
	}

	// -- Persistence & logging --------------------------------------------------

	private static function empty_stats(): array {
		return array_fill_keys( array( 'venues_done', 'inserted', 'enriched', 'seen', 'skipped', 'invalid', 'duplicates', 'marked_duplicate', 'classified', 'errors', 'llm_calls', 'tokens_in', 'tokens_out' ), 0 );
	}

	private static function hydrate( array $row ): array {
		$row['id']    = (int) $row['id'];
		$row['state'] = json_decode( $row['state'] ?: '{}', true ) ?: array();
		$row['stats'] = array_merge( self::empty_stats(), json_decode( $row['stats'] ?: '{}', true ) ?: array() );
		return $row;
	}

	/** Did someone cancel this run from the admin while we were working? */
	private static function cancelled_elsewhere(): bool {
		global $wpdb;
		$table  = Db::runs();
		$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $table WHERE id = %d", self::$run['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 'cancelled' === $status ) {
			self::$run['status'] = 'cancelled';
			return true;
		}
		return false;
	}

	private static function save(): void {
		global $wpdb;
		if ( ! self::$run ) {
			return;
		}
		$table = Db::runs();
		// If another process took over our (stale) lock, stop before writing anything.
		if ( ! self::refresh_lock() ) {
			throw new LockLost( 'Run lock was taken over by another process; this tick stops.' );
		}
		foreach ( \SceneCalendar\Llm\Usage::take() as $key => $n ) {
			self::$run['stats'][ $key ] = ( self::$run['stats'][ $key ] ?? 0 ) + $n;
		}
		$data  = array(
			'phase'        => self::$run['phase'],
			'heartbeat_at' => Db::now(),
			'state'        => wp_json_encode( self::$run['state'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'stats'        => wp_json_encode( self::$run['stats'] ),
		);
		// Never overwrite a cancellation made from the admin screen.
		if ( ! self::cancelled_elsewhere() ) {
			$data['status']      = self::$run['status'];
			$data['finished_at'] = self::$run['finished_at'] ?? null;
		}
		$wpdb->update( $table, $data, array( 'id' => self::$run['id'] ) );
		if ( self::$log_buffer ) {
			self::append_log_direct( self::$run['id'], implode( "\n", self::$log_buffer ), false );
			self::$log_buffer = array();
		}
	}

	/** Log a line for the current run (buffered until the next save). */
	public static function log( string $message ): void {
		$line = '[' . wp_date( 'H:i:s' ) . '] ' . $message;
		if ( self::$run ) {
			self::$log_buffer[] = $line;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::log( $line );
		}
	}

	private static function append_log_direct( int $id, string $text, bool $stamp = true ): void {
		global $wpdb;
		$table = Db::runs();
		$text  = ( $stamp ? '[' . wp_date( 'H:i:s' ) . '] ' : '' ) . $text . "\n";
		if ( $stamp && defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::log( rtrim( $text ) );
		}
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET log = CONCAT(log, %s) WHERE id = %d", $text, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET log = CONCAT('[log truncated]\n', RIGHT(log, %d)) WHERE id = %d AND CHAR_LENGTH(log) > %d", (int) ( self::LOG_MAX * 0.8 ), $id, self::LOG_MAX ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// -- Locking & dispatch ------------------------------------------------------

	/**
	 * The run lock lives in wp_options as "<owner>|<unix time>". The holder
	 * refreshes the time on every save(); a lock not refreshed for LOCK_TTL
	 * seconds belongs to a dead process and may be taken over. LOCK_TTL must
	 * exceed the longest single step (an LLM call with retries plus tool fetches).
	 */
	const LOCK_TTL = 900;

	private static string $lock_owner = '';

	private static function lock(): bool {
		global $wpdb;
		$owner = wp_generate_password( 12, false );
		$value = $owner . '|' . time();
		$got   = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK_OPTION, $value ) );
		if ( ! $got ) {
			$held = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) );
			if ( '' === $held || ! self::lock_is_stale( $held ) ) {
				return false;
			}
			$got = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, self::LOCK_OPTION, $held ) );
		}
		if ( $got ) {
			self::$lock_owner = $owner;
			return true;
		}
		return false;
	}

	private static function lock_is_stale( string $value ): bool {
		$time = (int) substr( strrchr( $value, '|' ) ?: '|0', 1 );
		return time() - $time > self::LOCK_TTL;
	}

	/** Refresh our lock's timestamp. False if we no longer hold it. */
	private static function refresh_lock(): bool {
		global $wpdb;
		if ( '' === self::$lock_owner ) {
			return false;
		}
		$like = $wpdb->esc_like( self::$lock_owner . '|' ) . '%';
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value LIKE %s", self::$lock_owner . '|' . time(), self::LOCK_OPTION, $like ) );
		// rows_affected is 0 when the value is unchanged within the same second; check ownership instead.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", self::LOCK_OPTION, $like ) );
	}

	/** Release the lock if this process holds it (also runs at shutdown after a fatal error). */
	public static function unlock(): void {
		global $wpdb;
		if ( '' === self::$lock_owner ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", self::LOCK_OPTION, $wpdb->esc_like( self::$lock_owner . '|' ) . '%' ) );
		self::$lock_owner = '';
	}

	/** True if a live (non-stale) process holds the run lock. */
	public static function is_locked(): bool {
		global $wpdb;
		$held = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) );
		return '' !== $held && ! self::lock_is_stale( $held );
	}

	public static function token(): string {
		$token = get_option( self::TOKEN_OPTION );
		if ( ! $token ) {
			$token = wp_generate_password( 32, false );
			update_option( self::TOKEN_OPTION, $token, false );
		}
		return $token;
	}

	/**
	 * Start the next tick in a fresh PHP request (non-blocking loopback to
	 * admin-ajax). If loopbacks are blocked, the 5-minute healthcheck cron
	 * picks the run up instead.
	 */
	public static function dispatch(): void {
		$url = add_query_arg(
			array(
				'action' => 'scenecal_tick',
				'token'  => self::token(),
			),
			admin_url( 'admin-ajax.php' )
		);
		wp_remote_post(
			$url,
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
	}

	/** admin-ajax handler for the loopback tick (no login; protected by a secret token). */
	public static function ajax_tick(): void {
		$token = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! hash_equals( self::token(), $token ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			// Nobody is waiting on this response.
			fastcgi_finish_request();
		}
		$budget = (int) apply_filters( 'scene_calendar_tick_budget', 45 );
		if ( true === self::tick( $budget ) ) {
			self::dispatch();
		}
		wp_die();
	}

	/**
	 * Cron healthcheck: resume a run whose heartbeat is stale. tick() itself
	 * refuses while a live process holds the lock and takes over a stale one.
	 */
	public static function healthcheck(): void {
		$run = self::active_run();
		if ( ! $run ) {
			return;
		}
		$age = time() - strtotime( $run['heartbeat_at'] . ' UTC' );
		if ( $age > 90 ) {
			$budget = (int) apply_filters( 'scene_calendar_tick_budget', 45 );
			if ( true === self::tick( $budget ) ) {
				self::dispatch();
			}
		}
	}

	/** Weekly cron: queue a run and schedule the next one. */
	public static function weekly(): void {
		if ( Settings::get( 'schedule_enabled' ) ) {
			$result = self::start( 'cron' );
			if ( is_wp_error( $result ) ) {
				$active = self::active_run();
				if ( $active ) {
					self::append_log_direct( $active['id'], 'Weekly run skipped: this run was still in progress.' );
				}
			}
		}
		Plugin::schedule_weekly( true );
	}
}
