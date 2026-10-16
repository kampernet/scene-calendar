<?php
namespace SceneCalendar\Llm;

defined( 'ABSPATH' ) || exit;

/**
 * Per-request tally of LLM calls and tokens, collected by the clients and
 * added to each run's stats so admins can see what a run cost.
 */
final class Usage {

	private static array $totals = array(
		'llm_calls'  => 0,
		'tokens_in'  => 0,
		'tokens_out' => 0,
	);

	public static function add( int $in, int $out ): void {
		++self::$totals['llm_calls'];
		self::$totals['tokens_in']  += $in;
		self::$totals['tokens_out'] += $out;
	}

	/** Return the totals since the last take() and reset them. */
	public static function take(): array {
		$totals       = self::$totals;
		self::$totals = array_fill_keys( array_keys( $totals ), 0 );
		return $totals;
	}
}
