<?php
namespace SceneCalendar\Llm;

defined( 'ABSPATH' ) || exit;

/**
 * Provider-neutral LLM client.
 *
 * Conversations are kept in the provider's native message format inside a
 * Conversation object, so tool-use turns (and any provider-specific blocks such
 * as reasoning/thinking) round-trip losslessly. Conversations are plain arrays
 * and can be serialized to the DB between cron ticks.
 */
interface Client {

	/** Provider slug, e.g. "openai" or "anthropic". */
	public function provider(): string;

	/** Model id in use. */
	public function model(): string;

	/** Single-turn text completion. Returns the assistant's text. */
	public function complete( string $prompt, int $max_tokens = 8000 ): string;

	/** Single-turn completion with one image (base64) plus a text prompt. */
	public function vision( string $prompt, string $image_base64, string $media_type, int $max_tokens = 8000 ): string;

	/**
	 * Start a tool-using conversation.
	 *
	 * @param string $system System instructions.
	 * @param string $user   First user message.
	 * @param array  $tools  List of ['name'=>..., 'description'=>..., 'parameters'=>JSON-Schema object].
	 */
	public function start( string $system, string $user, array $tools ): Conversation;

	/**
	 * Run one model turn on the conversation (appends the assistant message).
	 *
	 * @return array{text:string, tool_calls:array<int, array{id:string, name:string, arguments:array}>, stop:bool}
	 *         stop=true when the model ended its turn without calling tools.
	 */
	public function step( Conversation $conv, int $max_tokens = 8000 ): array;

	/**
	 * Append tool results for the tool calls returned by the last step().
	 *
	 * @param array<int, array{id:string, content:string}> $results
	 */
	public function add_tool_results( Conversation $conv, array $results ): void;

	/** Append a plain user message (e.g. a nudge to finish). */
	public function add_user_message( Conversation $conv, string $text ): void;
}
