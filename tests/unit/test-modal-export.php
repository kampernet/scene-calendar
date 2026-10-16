<?php
/**
 * Event modal data and "add to calendar" exports.
 */

// Only runs inside WordPress (wp eval-file); never directly over the web.
defined( 'ABSPATH' ) || exit;

use SceneCalendar\Events;
use SceneCalendar\Frontend\CalendarExport;
use SceneCalendar\Pipeline\Normalize;

function scenecal_export_event( array $overrides = array() ): array {
	$id = Events::upsert_scraped(
		Normalize::event(
			scenecal_raw_event(
				array_merge(
					array(
						'venue_name'     => 'The Handsome Daughter',
						'venue_city'     => 'Winnipeg',
						'venue_province' => 'MB',
						'date'           => '2026-12-04',
						'title'          => 'Winter Show; with, commas',
						'start_time'     => '20:00',
						'description'    => "Doors 7.\nAll ages",
						'lineup'         => array( 'Band A', 'Band B' ),
						'ticket_price'   => '$15',
						'ticket_link'    => 'https://tix.example/w',
					),
					$overrides
				)
			)
		),
		'x'
	)['id'];
	return Events::get( $id );
}

scenecal_test(
	'export: times are converted from the site timezone to UTC (fixed UTC-5 site, December)',
	static function () {
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', -5 );
		$e   = scenecal_export_event();
		$ics = CalendarExport::ics( $e );
		scenecal_assert( str_contains( $ics, "DTSTART:20261205T010000Z\r\n" ), $ics );
		scenecal_assert( str_contains( $ics, "DTEND:20261205T040000Z\r\n" ), 'default 3h length' );
		scenecal_assert( str_contains( CalendarExport::google_url( $e ), 'dates=20261205T010000Z/20261205T040000Z' ), CalendarExport::google_url( $e ) );
	}
);

scenecal_test(
	'export: ics escapes text, folds long lines with CRLF, and handles all-day and past-midnight events',
	static function () {
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', -5 );
		$e   = scenecal_export_event( array( 'description' => str_repeat( 'Long description é ', 20 ) ) );
		$ics = CalendarExport::ics( $e );
		scenecal_assert( str_contains( $ics, 'SUMMARY:Winter Show\; with\\, commas' ), 'TEXT escaping' );
		scenecal_assert( str_contains( $ics, 'LOCATION:The Handsome Daughter\\, Winnipeg\\, MB' ) );
		foreach ( explode( "\r\n", rtrim( $ics ) ) as $line ) {
			scenecal_assert( strlen( $line ) <= 75, 'line too long: ' . $line );
			scenecal_assert( mb_check_encoding( $line, 'UTF-8' ), 'fold split a character' );
		}
		scenecal_assert( ! preg_match( "/[^\r]\n/", $ics ), 'bare LF' );

		$all_day = CalendarExport::ics( scenecal_export_event( array( 'title' => 'All Day', 'start_time' => '' ) ) );
		scenecal_assert( str_contains( $all_day, "DTSTART;VALUE=DATE:20261204\r\nDTEND;VALUE=DATE:20261205" ), $all_day );

		$late = CalendarExport::range( scenecal_export_event( array( 'title' => 'Late', 'start_time' => '22:00', 'end_time' => '02:00' ) ) );
		scenecal_assert_same( '2026-12-05 02:00', $late['end']->format( 'Y-m-d H:i' ) );
	}
);

scenecal_test(
	'modal: shortcode embeds per-event details as safe JSON with export links',
	static function () {
		$e    = scenecal_export_event( array( 'date' => gmdate( 'Y-m-d', strtotime( '+5 days' ) ), 'description' => 'Bring </script><b>friends</b> & <3' ) );
		$html = do_shortcode( '[scene_calendar]' );
		scenecal_assert( preg_match( '#<script type="application/json" class="scenecal-data">(.*?)</script>#s', $html, $m ), 'data block' );
		scenecal_assert( ! str_contains( $m[1], '</script' ), 'no script break-out' );
		$data = json_decode( $m[1], true );
		$d    = $data[ $e['id'] ];
		scenecal_assert_same( 'Winter Show; with, commas', $d['title'] );
		scenecal_assert_same( array( 'Band A', 'Band B' ), $d['lineup'] );
		scenecal_assert( str_contains( $d['when'], ' at ' ), $d['when'] );
		scenecal_assert( str_contains( $d['ics'], 'scene_calendar_ics=' . $e['id'] ) );
		scenecal_assert( isset( $d['cal']['dates'], $d['cal']['details'], $d['cal']['location'] ) );
		scenecal_assert( str_contains( $html, 'data-id="' . $e['id'] . '"' ) );
		scenecal_assert( str_contains( $html, '<dialog class="scenecal-modal"' ) );
	}
);
