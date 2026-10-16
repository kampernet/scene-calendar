<?php
namespace SceneCalendar\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over the WordPress HTTP API with retry on transient failures.
 *
 * Requests go through wp_safe_remote_request(), which refuses private,
 * loopback and reserved addresses — important because the AI agent chooses
 * some of the URLs it fetches. Responses are capped at MAX_BYTES.
 */
final class Http {

	const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 SceneCalendar/' . SCENECAL_VERSION;

	const RETRY_STATUSES = array( 429, 500, 502, 503, 504 );

	/** Default response size cap; larger downloads are truncated (a JSON body then fails to parse). */
	const MAX_BYTES = 5 * MB_IN_BYTES;

	/**
	 * Perform a request and return [status, body, headers]. Throws HttpException on failure.
	 *
	 * @param string $method  GET or POST.
	 * @param string $url     Absolute URL.
	 * @param array  $args    wp_remote_request args (headers, body, timeout) plus
	 *                        'query' (array appended to the URL) and 'retries' (int).
	 * @return array{status:int, body:string, headers:array}
	 */
	public static function request( string $method, string $url, array $args = array() ): array {
		$retries = isset( $args['retries'] ) ? (int) $args['retries'] : 2;
		// 'trusted' => true is only for admin-configured endpoints (e.g. a self-hosted
		// LLM on the LAN); everything else, notably agent-chosen URLs, stays SSRF-safe.
		$trusted = ! empty( $args['trusted'] );
		unset( $args['retries'], $args['trusted'] );
		$args['limit_response_size'] = $args['limit_response_size'] ?? self::MAX_BYTES;

		if ( ! empty( $args['query'] ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', $args['query'] ), $url );
		}
		unset( $args['query'] );

		$args['method']  = strtoupper( $method );
		$args['timeout'] = $args['timeout'] ?? 20;
		$args['headers'] = array_merge( array( 'User-Agent' => self::USER_AGENT ), $args['headers'] ?? array() );

		$last_error = '';
		for ( $attempt = 0; $attempt <= $retries; $attempt++ ) {
			$response = $trusted ? wp_remote_request( $url, $args ) : wp_safe_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				$last_error = $response->get_error_message();
				// DNS failures are permanent; retrying only burns the tick's time budget.
				if ( stripos( $last_error, 'resolve host' ) !== false ) {
					break;
				}
			} else {
				$status = (int) wp_remote_retrieve_response_code( $response );
				if ( in_array( $status, self::RETRY_STATUSES, true ) && $attempt < $retries ) {
					$last_error = "HTTP $status";
					sleep( 2 ** $attempt );
					continue;
				}
				if ( $status >= 400 ) {
					$snippet = mb_substr( trim( wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) ) ), 0, 300 );
					throw new HttpException( "HTTP $status from " . self::redact( $url ) . ( '' !== $snippet ? ": $snippet" : '' ), $status );
				}
				$body = (string) wp_remote_retrieve_body( $response );
				if ( strlen( $body ) >= $args['limit_response_size'] ) {
					// Truncated by the size cap: fail loudly rather than parse half a feed.
					throw new HttpException( 'Response from ' . self::redact( $url ) . ' exceeded ' . size_format( $args['limit_response_size'] ) . '; not processed' );
				}
				$headers = wp_remote_retrieve_headers( $response );
				return array(
					'status'  => $status,
					'body'    => $body,
					'headers' => is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers,
				);
			}

			if ( $attempt < $retries ) {
				sleep( 2 ** $attempt );
			}
		}

		throw new HttpException( 'Request to ' . self::redact( $url ) . " failed: $last_error" );
	}

	/** Strip credentials from query strings before a URL is logged. */
	public static function redact( string $url ): string {
		return preg_replace( '/([?&](?:key|token|api_key|apikey|access_token|cx)=)[^&]+/i', '$1***', $url );
	}

	/** GET and return the body string. */
	public static function get( string $url, array $args = array() ): string {
		return self::request( 'GET', $url, $args )['body'];
	}

	/** GET and decode a JSON body. */
	public static function get_json( string $url, array $args = array() ) {
		$args['headers'] = array_merge( array( 'Accept' => 'application/json' ), $args['headers'] ?? array() );
		return self::decode( self::get( $url, $args ), $url );
	}

	/** POST a JSON payload and decode the JSON response. */
	public static function post_json( string $url, $payload, array $args = array() ) {
		$args['headers'] = array_merge(
			array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			$args['headers'] ?? array()
		);
		$args['body'] = wp_json_encode( $payload );
		return self::decode( self::request( 'POST', $url, $args )['body'], $url );
	}

	private static function decode( string $body, string $url ) {
		$data = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new HttpException( "Invalid JSON from $url: " . json_last_error_msg() );
		}
		return $data;
	}
}
