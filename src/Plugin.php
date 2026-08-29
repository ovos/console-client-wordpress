<?php
declare(strict_types=1);

namespace OvosConsole;

use function preg_match;
use function strtok;

/**
 * Plugin wiring — registers the error handlers at plugin load, before
 * the theme and most other plugins run, so their errors are captured.
 */
final class Plugin
{
	public const VERSION = '0.5.0';
	
	/**
	 * Fixed 60-second cap on 404 access-event reports, so a hard scan cannot
	 * turn this reporter into the flood it is meant to surface
	 */
	protected const MAX_404_PER_MINUTE = 30;
	
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
		
		// per-minute traffic rollups (opt-in, APCu-gated) — registered after
		// the Sender so its shutdown handler runs once error flushing is done
		if($this->config->rollups())
		{
			(new Rollup($this->config))->register();
		}
		
		// software inventory for the console's CVE matching (opt-in twice:
		// here AND cve_enabled on the console project) — its shutdown
		// handler registers last, inventory being the least urgent send
		if($this->config->inventory())
		{
			(new Inventory($this->config))->register();
		}
		
		(new JsClient($this->config, $this->file))->register();
		
		// self-update from the plugin's GitHub releases, through core's
		// own Update URI flow — no update server, no updater plugin
		(new Updater($this->file))->register();
		
		// report front-end 404s as access events (opt-in) so scanner and
		// broken-link traffic reaches the console apart from real errors
		if($this->config->report404())
		{
			add_action('template_redirect', [$this, 'reportNotFound']);
		}
		
		// report refused actions (failed logins, rejected nonces, forbidden
		// REST calls, sensitive admin changes) as security events (opt-in)
		if($this->config->securityEvents())
		{
			(new Security($this->sender))->register();
		}
		
		if(is_admin())
		{
			(new Settings($this->config, $this->sender, $this->file))->register();
		}
	}
	
	/**
	 * template_redirect: a resolved front-end 404 is an access event. Skips
	 * static-asset paths (broken images and the like are noise) and rate-limits
	 * so a scan cannot flood; the queued report ships from the shutdown handler.
	 */
	public function reportNotFound(): void
	{
		if(is_404() === false || $this->isStaticAsset() || $this->throttled())
		{
			return;
		}
		
		$this->sender->capture404();
	}
	
	/**
	 * Whether the current request targets a static asset (a broken image,
	 * stylesheet or script) rather than a page — those 404s are noise, not
	 * scanner signal
	 */
	protected function isStaticAsset(): bool
	{
		$uri = isset($_SERVER['REQUEST_URI'])
			? (string)wp_unslash($_SERVER['REQUEST_URI'])
			: '';
		$path = (string)strtok($uri, '?');
		
		return preg_match(
			'~\.(?:jpe?g|png|gif|webp|svg|ico|css|js|map|woff2?|ttf|eot)$~i',
			$path) === 1;
	}
	
	/**
	 * Fixed 60-second window cap on 404 reports (a transient counter), so a
	 * hard scan cannot turn this reporter into the flood it surfaces
	 */
	protected function throttled(): bool
	{
		$key = 'ovos_console_404_rate';
		$count = (int)get_transient($key);
		
		if($count >= self::MAX_404_PER_MINUTE)
		{
			return true;
		}
		
		set_transient($key, $count + 1, 60);
		
		return false;
	}
}
