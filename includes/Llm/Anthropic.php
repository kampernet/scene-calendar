<?php
namespace SceneCalendar\Llm;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Util\Http;
use SceneCalendar\Util\HttpException;

/**
 * Anthropic Messages API client.
 */
final class Anthropic implements Client {

	const ENDPOINT = 'https://api.anthropic.com/v1/messages';
	const VERSION  = '2023-06-01';

	private string $api_key;
	private string $model;

	public function __construct( string $api_key, string $model ) {
		$this->api_key = $api_key;
		$this->model   = $model;
	}

	public function provider(): string {
		return 'anthropic';
	}

	public function model(): string {
		return $this->model;
	}

	private function call( string $system, array $messages, array $tools, int $max_tokens ): \stdClass {
		$payload = array(
			'model'      => $this->model,
			'max_tokens' => $max_tokens,
			'messages'   => $messages,
		);
		if ( '' !== $system ) {
			$payload['system'] = $system;
		}
		if ( $tools ) {
			$payload['tools'] = $tools;
		}
		try {
			$response = Http::request(
				'POST',
				self::ENDPOINT,
				array(
					'headers' => array(
						'Content-Type'      => 'application/json',
						'x-api-key'         => $this->api_key,
						'anthropic-version' => self::VERSION,
					),
					'body'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
					'timeout' => 180,
					'retries' => 1,
				)
			);
		} catch ( HttpException $e ) {
			throw new LlmException( 'Anthropic request failed: ' . $e->getMessage(), 0, $e );
		}
		$data = json_decode( $response['body'] );
		if ( $data instanceof \stdClass ) {
			Usage::add( (int) ( $data->usage->input_tokens ?? 0 ), (int) ( $data->usage->output_tokens ?? 0 ) );
		}
		if ( ! $data instanceof \stdClass || ! isset( $data->content ) || ! is_array( $data->content ) ) {
			throw new LlmException( 'Anthropic returned an unexpected response: ' . mb_substr( $response['body'], 0, 300 ) );
		}
		return $data;
	}

	/** Concatenate the text blocks of a response (skips thinking/tool blocks). */
	private static function text_of( \stdClass $data ): string {
		$text = '';
		foreach ( $data->content as $block ) {
			if ( 'text' === ( $block->type ?? '' ) ) {
				$text .= $block->text;
			}
		}
		return $text;
	}

	public function complete( string $prompt, int $max_tokens = 8000 ): string {
		return self::text_of(
			$this->call(
				'',
				array(
					array(
						'role'    => 'user',
						'content' => $prompt,
					),
				),
				array(),
				$max_tokens
			)
		);
	}

	public function vision( string $prompt, string $image_base64, string $media_type, int $max_tokens = 8000 ): string {
		return self::text_of(
			$this->call(
				'',
				array(
					array(
						'role'    => 'user',
						'content' => array(
							array(
								'type'   => 'image',
								'source' => array(
									'type'       => 'base64',
									'media_type' => $media_type,
									'data'       => $image_base64,
								),
							),
							array(
								'type' => 'text',
								'text' => $prompt,
							),
						),
					),
				),
				array(),
				$max_tokens
			)
		);
	}

	public function start( string $system, string $user, array $tools ): Conversation {
		$native = array();
		foreach ( $tools as $tool ) {
			$native[] = array(
				'name'         => $tool['name'],
				'description'  => $tool['description'],
				'input_schema' => $tool['parameters'],
			);
		}
		return new Conversation(
			'anthropic',
			$system,
			$native,
			array(
				array(
					'role'    => 'user',
					'content' => $user,
				),
			)
		);
	}

	public function step( Conversation $conv, int $max_tokens = 8000 ): array {
		$data = $this->call( $conv->system, $conv->messages, $conv->tools, $max_tokens );

		// Re-send the content blocks verbatim (thinking blocks must round-trip unchanged).
		$conv->messages[] = array(
			'role'    => 'assistant',
			'content' => $data->content,
		);

		$calls = array();
		foreach ( $data->content as $block ) {
			if ( 'tool_use' === ( $block->type ?? '' ) ) {
				$calls[] = array(
					'id'        => (string) $block->id,
					'name'      => (string) $block->name,
					'arguments' => json_decode( wp_json_encode( $block->input ?? new \stdClass() ), true ) ?: array(),
				);
			}
		}
		return array(
			'text'       => self::text_of( $data ),
			'tool_calls' => $calls,
			'stop'       => ! $calls,
		);
	}

	public function add_tool_results( Conversation $conv, array $results ): void {
		$blocks = array();
		foreach ( $results as $result ) {
			$blocks[] = array(
				'type'        => 'tool_result',
				'tool_use_id' => $result['id'],
				'content'     => '' === $result['content'] ? '(empty)' : $result['content'],
			);
		}
		$conv->messages[] = array(
			'role'    => 'user',
			'content' => $blocks,
		);
	}

	public function add_user_message( Conversation $conv, string $text ): void {
		$last = end( $conv->messages );
		// Anthropic requires alternating roles; merge into a trailing user turn.
		if ( $last && 'user' === ( is_array( $last ) ? $last['role'] : $last->role ) ) {
			$key     = array_key_last( $conv->messages );
			$content = is_array( $last ) ? $last['content'] : $last->content;
			if ( is_string( $content ) ) {
				$content = array(
					array(
						'type' => 'text',
						'text' => $content,
					),
				);
			}
			$content   = (array) $content;
			$content[] = array(
				'type' => 'text',
				'text' => $text,
			);
			$conv->messages[ $key ] = array(
				'role'    => 'user',
				'content' => $content,
			);
			return;
		}
		$conv->messages[] = array(
			'role'    => 'user',
			'content' => $text,
		);
	}
}
