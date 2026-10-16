<?php
namespace SceneCalendar;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI commands. CLI runs have no time limit, so this is the most reliable
 * way to run a full scrape on hosts with SSH (e.g. from a system cron job).
 *
 * ## EXAMPLES
 *
 *     wp scene-calendar run
 *     wp scene-calendar run --venues="Sidestage,The Park Theatre" --no-postprocess
 *     wp scene-calendar import-venues data/example-venues-winnipeg.json
 *     wp scene-calendar import-events /path/to/events.json
 *     wp scene-calendar test-parser 3
 */
final class Cli {

	/**
	 * Run a scrape to completion in this process.
	 *
	 * ## OPTIONS
	 *
	 * [--venues=<list>]
	 * : Comma-separated venue ids or names. Default: all enabled venues.
	 *
	 * [--[no-]postprocess]
	 * : Run the dedup and genre classification phases (default). --no-postprocess skips them.
	 *
	 * [--resume]
	 * : Continue the active run instead of starting a new one.
	 */
	public function run( $args, $assoc ) {
		if ( empty( $assoc['resume'] ) ) {
			$ids = null;
			if ( ! empty( $assoc['venues'] ) ) {
				$ids = $this->resolve_venues( (string) $assoc['venues'] );
			}
			$id = Runner::start( 'cli', $ids, array( 'skip_postprocess' => isset( $assoc['postprocess'] ) && false === $assoc['postprocess'] ) );
			if ( is_wp_error( $id ) ) {
				\WP_CLI::error( $id->get_error_message() . ' Use --resume to continue it, or cancel it in the admin.' );
			}
		}
		while ( false !== ( $result = Runner::tick( 0 ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			if ( null === $result ) {
				\WP_CLI::log( 'Another process is working on this run; waiting...' );
				sleep( 15 );
			}
		}
		\WP_CLI::success( 'Run finished.' );
	}

	/**
	 * Cancel the active run.
	 */
	public function cancel() {
		$run = Runner::active_run();
		if ( ! $run ) {
			\WP_CLI::warning( 'No active run.' );
			return;
		}
		Runner::cancel( $run['id'] );
		\WP_CLI::success( "Run #{$run['id']} cancelled." );
	}

	/**
	 * Run one venue's direct parser and print what it extracts (nothing is stored).
	 *
	 * ## OPTIONS
	 *
	 * <venue>
	 * : Venue id or name.
	 *
	 * [--format=<format>]
	 * : table or json. Default table.
	 *
	 * @subcommand test-parser
	 */
	public function test_parser( $args, $assoc ) {
		$ids   = $this->resolve_venues( $args[0] );
		$venue = Venues::get( $ids[0] );
		$parser = Parsers\Registry::for_venue( $venue );
		if ( ! $parser ) {
			\WP_CLI::error( "No parser configured or auto-detected for {$venue['name']}." );
		}
		$ctx    = new Parsers\Context( wp_date( 'Y-m-d' ), '9999-12-31', wp_timezone(), array( \WP_CLI::class, 'log' ), Llm\Factory::make_vision() );
		$events = $parser->parse( $venue, $ctx );
		\WP_CLI::log( sprintf( '%s: %d events', $parser::slug(), count( $events ) ) );
		if ( 'json' === ( $assoc['format'] ?? 'table' ) ) {
			\WP_CLI::line( wp_json_encode( $events, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		$rows = array_map(
			static fn( $e ) => array(
				'date'  => $e['date'],
				'time'  => $e['start_time'],
				'venue' => $e['venue_name'],
				'title' => mb_substr( $e['title'], 0, 60 ),
				'price' => $e['ticket_price'],
			),
			$events
		);
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'date', 'time', 'venue', 'title', 'price' ) );
	}

	/**
	 * Import venues from a JSON file.
	 *
	 * <file>
	 * : Path to a venues JSON file.
	 *
	 * @subcommand import-venues
	 */
	public function import_venues( $args ) {
		$path = $this->path( $args[0] );
		\WP_CLI::success( sprintf( 'Imported %d venues.', Venues::import_json( (string) file_get_contents( $path ) ) ) );
	}

	/**
	 * Import events from a JSON file (the Python scraper's events.json format).
	 *
	 * <file>
	 * : Path to an events JSON file.
	 *
	 * @subcommand import-events
	 */
	public function import_events( $args ) {
		$path                     = $this->path( $args[0] );
		list( $inserted, $skipped ) = Events::import_json( (string) file_get_contents( $path ) );
		\WP_CLI::success( "Imported $inserted events ($skipped skipped as invalid or already present)." );
	}

	/**
	 * Export venues as JSON to stdout.
	 *
	 * @subcommand export-venues
	 */
	public function export_venues() {
		\WP_CLI::line( Venues::export_json() );
	}

	private function path( string $file ): string {
		foreach ( array( $file, SCENECAL_DIR . ltrim( $file, '/' ) ) as $candidate ) {
			if ( is_readable( $candidate ) ) {
				return $candidate;
			}
		}
		\WP_CLI::error( "Cannot read $file" );
	}

	/** @return int[] */
	private function resolve_venues( string $list ): array {
		$all = Venues::all();
		$ids = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', $list ) ) ) as $token ) {
			$match = null;
			foreach ( $all as $venue ) {
				if ( ( ctype_digit( $token ) && (int) $token === $venue['id'] )
					|| Venues::alias_key( $token ) === Venues::alias_key( $venue['name'] )
					|| in_array( Venues::alias_key( $token ), array_map( array( Venues::class, 'alias_key' ), $venue['aliases'] ), true ) ) {
					$match = $venue['id'];
					break;
				}
			}
			if ( null === $match ) {
				\WP_CLI::error( "Unknown venue: $token" );
			}
			$ids[] = $match;
		}
		return $ids;
	}
}
