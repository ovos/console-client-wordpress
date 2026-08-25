<?php
declare(strict_types=1);

namespace OvosConsole;

use function constant;
use function defined;
use function max;
use function mb_substr;
use function min;
use function rtrim;
use function strtoupper;
use function trim;

/**
 * Plugin configuration: the ovos_console option, with every key
 * overridable by an OVOS_CONSOLE_<KEY> constant in wp-config.php —
 * deploy-time configuration for sites we manage; a defined constant
 * locks the field in the settings UI.
 */
class Config
{
	public const OPTION = 'ovos_console';
	
	public const DEFAULTS = [
		'enabled' => false,
		'url' => '',
		'api_key' => '',
		'log_level' => 4,
		'report_404' => false,
		'rollups' => false,
		'security_events' => false,
		'release' => '',
		'js_enabled' => true,
		'js_key' => '',
		'js_trace' => true,
		'snapshot' => false,
		'snapshot_styles' => false,
		'js_admin' => false,
	];
	
	protected ?array $settings = null;
	
	public function get(
		string $key,
	): mixed
	{
		if($this->isConstant($key))
		{
			return constant($this->constantName($key));
		}
		
		$this->settings ??= (array)get_option(self::OPTION, []);
		
		return $this->settings[$key] ?? self::DEFAULTS[$key] ?? null;
	}
	
	public function isConstant(
		string $key,
	): bool
	{
		return defined($this->constantName($key));
	}
	
	public function constantName(
		string $key,
	): string
	{
		return 'OVOS_CONSOLE_' . strtoupper($key);
	}
	
	/**
	 * Drops the option cache — used after the settings page saved
	 */
	public function refresh(): void
	{
		$this->settings = null;
	}
	
	public function enabled(): bool
	{
		return (bool)$this->get('enabled');
	}
	
	public function url(): string
	{
		return rtrim(trim((string)$this->get('url')), '/');
	}
	
	public function apiKey(): string
	{
		return trim((string)$this->get('api_key'));
	}
	
	public function logLevel(): int
	{
		return max(0, min(7, (int)$this->get('log_level')));
	}
	
	public function report404(): bool
	{
		return (bool)$this->get('report_404');
	}
	
	public function rollups(): bool
	{
		return (bool)$this->get('rollups');
	}
	
	public function securityEvents(): bool
	{
		return (bool)$this->get('security_events');
	}
	
	public function release(): string
	{
		return mb_substr(trim((string)$this->get('release')), 0, 64);
	}
	
	public function jsEnabled(): bool
	{
		return (bool)$this->get('js_enabled');
	}
	
	public function jsKey(): string
	{
		return trim((string)$this->get('js_key'));
	}
	
	public function jsTrace(): bool
	{
		return (bool)$this->get('js_trace');
	}
	
	public function snapshot(): bool
	{
		return (bool)$this->get('snapshot');
	}
	
	public function snapshotStyles(): bool
	{
		return (bool)$this->get('snapshot_styles');
	}
	
	public function jsAdmin(): bool
	{
		return (bool)$this->get('js_admin');
	}
}
