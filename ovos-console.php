<?php
declare(strict_types=1);
/**
 * Plugin Name: ovos console
 * Description: Connects this site to an ovos/console error-monitoring instance — PHP and browser JavaScript errors, with grouping, alerting and issue lifecycle handled by the console.
 * Version: 0.3.2
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: ovos media gmbh
 * Author URI: https://www.ovos.at
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ovos-console
 */

// this bootstrap file stays parseable on old PHP on purpose: the
// Requires PHP header stops activation, this guard stops execution
// if the plugin ended up active regardless
defined('ABSPATH') || exit;

if(PHP_VERSION_ID < 80100)
{
	return;
}

spl_autoload_register(static function ($class) {
	if(strpos($class, 'OvosConsole\\') !== 0)
	{
		return;
	}
	
	$file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 12)) . '.php';
	
	if(is_file($file))
	{
		require $file;
	}
});

OvosConsole\Plugin::boot(__FILE__);

if(function_exists('ovos_console') === false)
{
	/**
	 * Manual captures from theme or plugin code:
	 *
	 *   ovos_console()->captureException($e, ['orderId' => 7]);
	 *   ovos_console()->captureMessage('checkout step skipped', 4);
	 *
	 * @return OvosConsole\Sender|null
	 */
	function ovos_console()
	{
		$plugin = OvosConsole\Plugin::instance();
		
		return $plugin !== null ? $plugin->sender : null;
	}
}
