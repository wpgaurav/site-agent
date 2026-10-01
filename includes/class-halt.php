<?php
/**
 * Signals that executed code called wp_die().
 *
 * @package SiteAgent
 */

namespace SiteAgent;

defined( 'ABSPATH' ) || exit;

/** Thrown in place of wp_die() so PHP execution returns a tool result instead of ending the request. */
final class Halt extends \RuntimeException {
}
