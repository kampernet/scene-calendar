<?php
namespace SceneCalendar\Parsers;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Util\Html;
use SceneCalendar\Util\Http;
use SceneCalendar\Util\Text;

/**
 * Venues that publish their monthly lineup only as a poster image.
 *
 * The poster is either configured directly ('poster_image_url') or picked from
 * the venue page as the tallest portrait-oriented image (dimensions read from
 * Squarespace's data-image-dimensions or from width/height attributes). The
 * image is sent to the configured AI model's vision capability, which returns
 * the events as JSON.
 */
class PosterVision extends Parser {

	/** Largest image we will send to the model (bytes). */
	const MAX_IMAGE_BYTES = 8000000;

	public static function slug(): string {
		return 'poster_vision';
	}

	public static function label(): string {
		return __( 'Poster image (AI vision)', 'scene-calendar' );
	}

	public static function description(): string {
		return __( 'For venues that post their monthly lineup as a poster image. The tallest portrait image on the venue URL is read by the AI model (uses API credits). Set a direct image URL if auto-detection picks the wrong image.', 'scene-calendar' );
	}

	public static function config_fields(): array {
		return array(
			'poster_image_url' => array(
				'label' => __( 'Poster image URL', 'scene-calendar' ),
				'help'  => __( 'Optional. A direct link to the lineup poster; otherwise the tallest portrait image on the venue page is used.', 'scene-calendar' ),
			),
		);
	}

	public function parse( array $venue, Context $ctx ): array {
		if ( ! $ctx->llm ) {
			$ctx->log( 'Poster parser needs an AI provider with an API key configured; skipping' );
			return array();
		}

		$page_url  = $venue['url'] ?? '';
		$image_url = trim( (string) ( $venue['poster_image_url'] ?? '' ) );
		if ( '' === $image_url ) {
			$image_url = self::find_poster_url( Http::get( $page_url ), $page_url );
		}
		if ( '' === $image_url ) {
			$ctx->log( 'No portrait poster image found on ' . $page_url );
			return array();
		}
		$ctx->log( 'Using poster image ' . $image_url );

		$response = Http::request( 'GET', $image_url, array( 'timeout' => 30, 'limit_response_size' => self::MAX_IMAGE_BYTES + 1 ) );
		$bytes    = $response['body'];
		if ( '' === $bytes || strlen( $bytes ) > self::MAX_IMAGE_BYTES ) {
			$ctx->log( 'Poster image empty or too large (' . strlen( $bytes ) . ' bytes)' );
			return array();
		}
		$media_type = self::media_type( $response['headers'], $image_url, $bytes );

		$text = $ctx->llm->vision( self::prompt( $venue, $ctx ), base64_encode( $bytes ), $media_type );
		$raw  = Text::decode_llm_json( $text );
		if ( is_array( $raw ) && isset( $raw['events'] ) && is_array( $raw['events'] ) ) {
			$raw = $raw['events'];
		}
		if ( ! is_array( $raw ) || ! array_is_list( $raw ) ) {
			$ctx->log( 'Vision response was not a JSON array: ' . mb_substr( $text, 0, 200 ) );
			return array();
		}

		$events = array();
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$title = trim( (string) ( $item['title'] ?? '' ) );
			$date  = Text::parse_date( (string) ( $item['date'] ?? '' ) );
			if ( '' === $title || ! $ctx->in_range( $date ) ) {
				continue;
			}
			$lineup = $item['lineup'] ?? array();
			if ( is_string( $lineup ) ) {
				$lineup = array_map( 'trim', explode( ',', $lineup ) );
			}
			$lineup = is_array( $lineup ) ? array_values( array_filter( array_map( 'strval', $lineup ), 'strlen' ) ) : array();
			if ( ! $lineup ) {
				$lineup = array( $title );
			}

			$event                 = $this->base_event( $venue );
			$event['date']         = $date;
			$event['title']        = $title;
			$event['description']  = Text::truncate( trim( (string) ( $item['description'] ?? '' ) ) );
			$event['lineup']       = $lineup;
			$event['ticket_price'] = trim( (string) ( $item['ticket_price'] ?? '' ) );
			$event['ticket_link']  = $page_url;
			$event['start_time']   = Text::parse_time( (string) ( $item['start_time'] ?? '' ) );
			$event['source_urls']  = array( '' !== $page_url ? $page_url : $image_url );
			$events[]              = $event;
		}
		return $events;
	}

	/** Pick the tallest portrait-oriented image on a page; '' if none. */
	public static function find_poster_url( string $html, string $page_url ): string {
		$xp     = Html::load( $html );
		$best   = '';
		$height = 0;
		foreach ( Html::all( $xp, '//img' ) as $img ) {
			$src = Html::attr( $img, 'data-src' );
			if ( '' === $src ) {
				$src = Html::attr( $img, 'src' );
			}
			if ( '' === $src || str_starts_with( $src, 'data:' ) ) {
				continue;
			}
			$w = 0;
			$h = 0;
			if ( preg_match( '/^(\d+)x(\d+)$/', Html::attr( $img, 'data-image-dimensions' ), $m ) ) {
				$w = (int) $m[1];
				$h = (int) $m[2];
			} else {
				$w = (int) Html::attr( $img, 'width' );
				$h = (int) Html::attr( $img, 'height' );
			}
			if ( $h > $w && $h > $height ) {
				$height = $h;
				$best   = $src;
			}
		}
		if ( '' === $best ) {
			return '';
		}
		$best = Html::absolute_url( $best, $page_url );
		// Squarespace CDN images are served at a small default size without a format hint.
		if ( str_contains( $best, 'squarespace-cdn.com' ) && ! str_contains( $best, '?' ) ) {
			$best .= '?format=1500w';
		}
		return $best;
	}

	private static function media_type( array $headers, string $url, string $bytes ): string {
		$type = strtolower( (string) ( $headers['content-type'] ?? '' ) );
		if ( is_array( $headers['content-type'] ?? null ) ) {
			$type = strtolower( (string) reset( $headers['content-type'] ) );
		}
		foreach ( array( 'png', 'jpeg', 'webp', 'gif' ) as $kind ) {
			if ( str_contains( $type, $kind ) ) {
				return "image/$kind";
			}
		}
		if ( str_contains( $type, 'jpg' ) ) {
			return 'image/jpeg';
		}
		// Sniff magic bytes when the server sends a generic type.
		if ( str_starts_with( $bytes, "\x89PNG" ) ) {
			return 'image/png';
		}
		if ( str_starts_with( $bytes, "\xFF\xD8" ) ) {
			return 'image/jpeg';
		}
		if ( str_starts_with( $bytes, 'RIFF' ) && 'WEBP' === substr( $bytes, 8, 4 ) ) {
			return 'image/webp';
		}
		$ext = strtolower( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		return in_array( $ext, array( 'jpg', 'jpeg' ), true ) ? 'image/jpeg' : 'image/png';
	}

	private static function prompt( array $venue, Context $ctx ): string {
		$year       = (int) substr( $ctx->start_date, 0, 4 );
		$month_name = ( new \DateTimeImmutable( $ctx->start_date ) )->format( 'F' );
		$where      = trim( ( $venue['name'] ?? 'the venue' ) . ', ' . ( $venue['city'] ?? '' ), ', ' );
		$next       = $year + 1;

		return <<<PROMPT
Extract all live music events from this venue poster image.

The venue is {$where}. Today is {$ctx->start_date}.

For each event, extract:
- date: the full date in YYYY-MM-DD format (use year {$year}, or {$next} if the month is earlier than {$month_name})
- title: the event title or artist/band name
- lineup: a JSON array of individual artist/band names (split multi-artist bills into separate strings)
- start_time: in HH:MM 24-hour format if visible (e.g. "21:00" for 9pm), or "" if not shown
- ticket_price: price if shown (e.g. "\$10", "Free", "PWYC"), or "" if not shown
- description: any extra details (genre, special notes, etc.), or "" if none

Return ONLY a JSON array of objects. If no events are found, return [].
Do not include any text outside the JSON array.
PROMPT;
	}
}
