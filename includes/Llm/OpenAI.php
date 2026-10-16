<?php
namespace SceneCalendar\Llm;

defined( 'ABSPATH' ) || exit;

use SceneCalendar\Util\Http;
use SceneCalendar\Util\HttpException;

/**
 * OpenAI Chat Completions client. Also works with OpenAI-compatible servers
 * (Ollama, OpenRouter, LM Studio, ...) via a custom base URL.
 */
final class OpenAI implements Client {

	private string $api_key;
	private string $model;
	private string $base_url;

	public function __construct( string $api_key, string $model, string $base_url = 'https://api.openai.com/v1' ) {
		$this->api_key  = $api_key;
		$this->model    = $model;
		$this->base_url = untrailingslashit( $base_url );
	}

	public function provider(): string {
		return 'openai';
	}

	public function model(): string {
		return $this->model;
	}

	private function call( array $messages, array $tools, int $max_tokens ): \stdClass {
		$payload = array(
			'model'                 => $this->model,
			'messages'              => $messages,
			'max_completion_tokens' => $max_tokens,
		);
		if ( $tools ) {
			$payload['tools'] = $tools;
		}
		$headers = array( 'Content-Type' => 'application/json' );
		if ( '' !== $this->api_key ) {
			$headers['Authorization'] = 'Bearer ' . $this->api_key;
		}
		try {
			$response = Http::request(
				'POST',
				$this->base_url . '/chat/completions',
				array(
					'headers' => $headers,
					'body'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
					'timeout' => 180,
					'retries' => 1,
					// The base URL is set by an admin and may be a LAN/localhost server (Ollama).
					'trusted' => true,
				)
			);
		} catch ( HttpException $e ) {
			throw new LlmException( 'OpenAI request failed: ' . $e->getMessage(), 0, $e );
		}
		$data = json_decode( $response['body'] );
		if ( $data instanceof \stdClass ) {
			Usage::add( (int) ( $data->usage->prompt_tokens ?? 0 ), (int) ( $data->usage->completion_tokens ?? 0 ) );
		}
		if ( ! $data instanceof \stdClass || empty( $data->choices[0]->message ) ) {
			throw new LlmException( 'OpenAI returned an unexpected response: ' . mb_substr( $response['body'], 0, 300 ) );
		}
		return $data->choices[0];
	}

	public function complete( string $prompt, int $max_tokens = 8000 ): string {
		$choice = $this->call( array( array( 'role' => 'user', 'content' => $prompt ) ), array(), $max_tokens );
		return (string) ( $choice->message->content ?? '' );
	}

	public function vision( string $prompt, string $image_base64, string $media_type, int $max_tokens = 8000 ): string {
		$choice = $this->call(
			array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'      => 'image_url',
							'image_url' => array( 'url' => "data:$media_type;base64,$image_base64" ),
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
		);
		return (string) ( $choice->message->content ?? '' );
	}

	public function start( string $system, string $user, array $tools ): Conversation {
		$native = array();
		foreach ( $tools as $tool ) {
			$native[] = array(
				'type'     => 'function',
				'function' => array(
					'name'        => $tool['name'],
					'description' => $tool['description'],
					'parameters'  => $tool['parameters'],
				),
			);
		}
		return new Conversation(
			'openai',
			$system,
			$native,
			array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => $user,
				),
			)
		);
	}

	public function step( Conversation $conv, int $max_tokens = 8000 ): array {
		$choice  = $this->call( $conv->messages, $conv->tools, $max_tokens );
		$message = $choice->message;

		// Keep only fields the API accepts back as an assistant message.
		$assistant = array(
			'role'    => 'assistant',
			'content' => $message->content ?? null,
		);
		if ( ! empty( $message->tool_calls ) ) {
			$assistant['tool_calls'] = $message->tool_calls;
		}
		$conv->messages[] = $assistant;

		$calls = array();
		foreach ( $message->tool_calls ?? array() as $call ) {
			$args    = json_decode( (string) ( $call->function->arguments ?? '' ), true );
			$calls[] = array(
				'id'        => (string) $call->id,
				'name'      => (string) ( $call->function->name ?? '' ),
				'arguments' => is_array( $args ) ? $args : array(),
			);
		}
		return array(
			'text'       => (string) ( $message->content ?? '' ),
			'tool_calls' => $calls,
			'stop'       => ! $calls,
		);
	}

	public function add_tool_results( Conversation $conv, array $results ): void {
		foreach ( $results as $result ) {
			$conv->messages[] = array(
				'role'         => 'tool',
				'tool_call_id' => $result['id'],
				'content'      => $result['content'],
			);
		}
	}

	public function add_user_message( Conversation $conv, string $text ): void {
		$conv->messages[] = array(
			'role'    => 'user',
			'content' => $text,
		);
	}
}
