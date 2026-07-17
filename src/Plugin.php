<?php
declare(strict_types=1);

namespace OvosConsole;

/**
 * Plugin wiring — registers the error handlers at plugin load, before
 * the theme and most other plugins run, so their errors are captured.
 */
final class Plugin
{
	public const VERSION = '0.2.1';
	
	protected static ?self $instance = null;
	
	public readonly Config $config;
	
	public readonly Sender $sender;
	
	protected function __construct(
		public readonly string $file,
	)
	{
		$this->config = new Config();
		$this->sender = new Sender($this->config);
	}
	
	public static function boot(
		string $file,
	): void
	{
		if(self::$instance !== null)
		{
			return;
		}
		
		self::$instance = new self($file);
		self::$instance->register();
	}
	
	public static function instance(): ?self
	{
		return self::$instance;
	}
	
	protected function register(): void
	{
		$this->sender->register();
		
		(new JsClient($this->config, $this->file))->register();
		
		if(is_admin())
		{
			(new Settings($this->config, $this->sender, $this->file))->register();
		}
	}
}
