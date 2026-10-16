<?php
namespace SceneCalendar\Llm;

defined( 'ABSPATH' ) || exit;

/**
 * A tool-use conversation in the provider's native message format.
 *
 * Messages received from an API are kept as decoded stdClass objects so that
 * JSON objects stay objects (e.g. an empty tool input `{}` must not become
 * `[]`) when the conversation is re-sent or persisted between cron ticks.
 */
final class Conversation {

	public string $provider;
	public string $system;
	/** @var array Provider-native tool definitions. */
	public array $tools;
	/** @var array Provider-native messages (arrays and/or stdClass). */
	public array $messages;

	public function __construct( string $provider, string $system, array $tools, array $messages ) {
		$this->provider = $provider;
		$this->system   = $system;
		$this->tools    = $tools;
		$this->messages = $messages;
	}

	public function to_json(): string {
		return (string) wp_json_encode(
			array(
				'provider' => $this->provider,
				'system'   => $this->system,
				'tools'    => $this->tools,
				'messages' => $this->messages,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
	}

	public static function from_json( string $json ): ?self {
		$data = json_decode( $json );
		if ( ! $data instanceof \stdClass || ! isset( $data->provider, $data->messages ) ) {
			return null;
		}
		return new self( (string) $data->provider, (string) ( $data->system ?? '' ), (array) ( $data->tools ?? array() ), (array) $data->messages );
	}

	/** Approximate size, for logging and guarding against runaway context. */
	public function size(): int {
		return strlen( $this->to_json() );
	}
}
