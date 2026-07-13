<?php
declare(strict_types=1);

namespace OvosConsole;

use function function_exists;
use function rawurlencode;

/**
 * Prints the async browser-client loader (the bo2go console.phtml
 * pattern): a slow or missing script never blocks the page, init runs
 * onload. The client itself is bundled — wordpress.org forbids loading
 * executable code from external servers, and a pinned copy beats
 * depending on the console instance being reachable at page load.
 */
class JsClient
{
	public function __construct(
		protected Config $config,
		protected string $file,
	)
	{
	}
	
	public function register(): void
	{
		add_action('wp_head', [$this, 'printLoader'], 1);
		
		if($this->config->jsAdmin())
		{
			add_action('admin_head', [$this, 'printLoader'], 1);
			add_action('login_head', [$this, 'printLoader'], 1);
		}
	}
	
	public function isEnabled(): bool
	{
		return $this->config->enabled()
			&& $this->config->jsEnabled()
			&& $this->config->url() !== ''
			&& $this->config->jsKey() !== '';
	}
	
	public function printLoader(): void
	{
		if($this->isEnabled() === false)
		{
			return;
		}
		
		$src = plugins_url('assets/console-client.js', $this->file)
			. '?ver=' . rawurlencode(Plugin::VERSION);
			
		$options = [
			'url' => $this->config->url(),
			'key' => $this->config->jsKey(),
			'logLevel' => $this->config->logLevel(),
		];
		
		if($this->config->release() !== '')
		{
			$options['release'] = $this->config->release();
		}
		
		if($this->config->snapshot())
		{
			// masked DOM snapshot with the first error per page load
			// (replay-lite); inputs/scripts are stripped client-side
			$options['snapshot'] = true;
			
			if($this->config->snapshotStyles())
			{
				$options['snapshotStyles'] = true;
			}
		}
		
		$context = '';
		
		if(function_exists('get_current_user_id') && (int)get_current_user_id() > 0)
		{
			$context = "\n\toptions.context = function () { return {userId: "
				. wp_json_encode((string)get_current_user_id())
				. "}; };";
		}
		
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- inline bootstrap script; every dynamic value is wp_json_encode()d
		echo "<script>\n"
			. "(function () {\n"
			. "\tvar options = " . wp_json_encode($options, JSON_UNESCAPED_SLASHES) . ";" . $context . "\n"
			. "\tvar script = document.createElement('script');\n"
			. "\tscript.async = true;\n"
			. "\tscript.src = " . wp_json_encode($src, JSON_UNESCAPED_SLASHES) . ";\n"
			. "\tscript.onload = function () {\n"
			. "\t\twindow.ovosConsole && window.ovosConsole.init(options);\n"
			. "\t};\n"
			. "\tdocument.head.appendChild(script);\n"
			. "})();\n"
			. "</script>\n";
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
