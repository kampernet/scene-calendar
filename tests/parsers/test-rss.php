<?php

// Only runs inside WordPress (wp eval-file); never directly over the web.
defined( 'ABSPATH' ) || exit;
use SceneCalendar\Parsers\Rss;

scenecal_test(
	'rss: Manitoba Music feed — venue from <h2>Venue</h2>, date from "16 Oct 26"',
	static function () {
		scenecal_mock_http( array( 'manitobamusic.com' => scenecal_fixture( 'rss-manitobamusic.rss' ) ) );
		$venue  = scenecal_test_venue(
			array(
				'name' => 'Manitoba Music',
				'type' => 'promoter',
				'url'  => 'https://www.manitobamusic.com/livemusic.rss',
			)
		);
		$events = ( new Rss() )->parse( $venue, scenecal_test_context() );

		scenecal_assert_same( 4, count( $events ) );
		// pubDate is 2026-10-14 / 10-08; the show date in the description must win.
		scenecal_assert_same( array( '2026-10-16', '2026-10-16', '2026-10-16', '2026-10-16' ), array_column( $events, 'date' ) );
		scenecal_assert_same(
			array( "Belle's Kitchen + Music Hall", 'Sheraton Hamilton Hotel, Ballroom, 2nd Floor', 'Video Pool Media Arts Centre', 'Cornerstone' ),
			array_column( $events, 'venue_name' )
		);
		scenecal_assert_same( array( '11:45', '13:30', '', '19:00' ), array_column( $events, 'start_time' ) );
		scenecal_assert_same( 'https://www.manitobamusic.com/livemusic/display,event/113339/live-at-belle-s', $events[0]['ticket_link'] );
		scenecal_assert_same( array( 'https://www.manitobamusic.com/livemusic/display,event/113339/live-at-belle-s' ), $events[0]['source_urls'] );
		scenecal_assert( ! str_contains( $events[0]['description'], '<' ), 'description is plain text' );
		scenecal_assert( str_contains( $events[0]['description'], '1212 Nairn Avenue Winnipeg' ), 'block elements keep word boundaries' );
	}
);

scenecal_test(
	'rss: Atom feed entries',
	static function () {
		$atom = '<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>Shows</title>
  <entry>
    <title>Band Night - November 20, 2026</title>
    <link href="https://example.com/shows/band-night"/>
    <updated>2026-10-01T12:00:00Z</updated>
    <content type="html">&lt;h2&gt;Venue&lt;/h2&gt;&lt;p&gt;&lt;b&gt;The Good Room&lt;/b&gt;&lt;/p&gt;</content>
  </entry>
  <entry>
    <title>No date in title</title>
    <link href="https://example.com/shows/fallback"/>
    <updated>2026-11-02T03:00:00Z</updated>
    <summary>Just some words</summary>
  </entry>
</feed>';
		scenecal_mock_http( array( 'example.com/feed' => $atom ) );
		$venue  = scenecal_test_venue( array( 'url' => 'https://example.com/feed/' ) );
		$events = ( new Rss() )->parse( $venue, scenecal_test_context() );

		scenecal_assert_same( 2, count( $events ) );
		scenecal_assert_same( '2026-11-20', $events[0]['date'] );
		scenecal_assert_same( 'The Good Room', $events[0]['venue_name'] );
		scenecal_assert_same( 'https://example.com/shows/band-night', $events[0]['ticket_link'] );
		// Fallback to <updated>, converted to local time (03:00Z = Nov 1 evening in Winnipeg).
		scenecal_assert_same( '2026-11-01', $events[1]['date'] );
		scenecal_assert_same( 'Test Venue', $events[1]['venue_name'] );
	}
);

scenecal_test(
	'rss: invalid XML returns nothing',
	static function () {
		scenecal_mock_http( array( 'example.com' => '<html>not a feed' ) );
		scenecal_assert_same( array(), ( new Rss() )->parse( scenecal_test_venue( array( 'url' => 'https://example.com/x.rss' ) ), scenecal_test_context() ) );
	}
);

scenecal_test(
	'rss: extract_date picks the earliest date in the text',
	static function () {
		scenecal_assert_same( '2026-10-16', Rss::extract_date( '16 Oct 26 Venue Friday, October 16', '2026-10-01' ) );
		scenecal_assert_same( '2026-11-03', Rss::extract_date( 'Show 2026-11-03, album out October 2, 2025', '2026-10-01' ) );
		scenecal_assert_same( '2025-10-02', Rss::extract_date( 'Released October 2nd, 2025', '2026-10-01' ) );
		scenecal_assert_same( '2027-02-12', Rss::extract_date( 'Join us Friday, Feb 12 at 8pm', '2026-10-01' ) );
		scenecal_assert_same( '2025-10-16', Rss::extract_date( 'Friday, October 16, 2025', '2026-10-01' ), 'explicit year beats inference' );
		scenecal_assert_same( '', Rss::extract_date( 'No date here at all', '2026-10-01' ) );
	}
);

scenecal_test(
	'rss: venue and time helpers',
	static function () {
		scenecal_assert_same( 'The Handsome Daughter', Rss::venue_from_text( 'Big Show at The Handsome Daughter, Winnipeg' ) );
		scenecal_assert_same( 'Park Theatre', Rss::venue_from_text( 'Band @ Park Theatre on Friday' ) );
		scenecal_assert_same( '', Rss::venue_from_text( 'nothing to see' ) );
		scenecal_assert_same( '21:30', Rss::extract_time( 'Doors 9:30 PM' ) );
	}
);

scenecal_test(
	'rss: matches_url',
	static function () {
		scenecal_assert( Rss::matches_url( 'https://www.manitobamusic.com/livemusic.rss' ) );
		scenecal_assert( Rss::matches_url( 'https://example.com/events.xml' ) );
		scenecal_assert( Rss::matches_url( 'https://example.com/events.atom' ) );
		// WordPress /feed/ is the blog-post feed, not events: pick "RSS / Atom" explicitly if wanted.
		scenecal_assert( ! Rss::matches_url( 'https://example.com/feed/' ) );
		scenecal_assert( ! Rss::matches_url( 'https://example.com/feedback' ) );
		scenecal_assert( ! Rss::matches_url( 'https://example.com/events' ) );
	}
);
