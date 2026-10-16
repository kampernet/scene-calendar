<?php
namespace SceneCalendar;

defined( 'ABSPATH' ) || exit;

/** Thrown when a tick discovers another process has taken over the run lock. */
class LockLost extends \RuntimeException {
}
