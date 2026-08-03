<?php
declare(strict_types=1);

namespace OvosConsole;

use function is_array;
use function is_string;
use function json_decode;
use function ltrim;
use function preg_match;

/**
 * Self-update through WordPress core's Update URI mechanism (WP 5.8+):
 * the plugin header names github.com as its update source, core asks the
 * update_plugins_github.com filter whenever it rebuilds the plugin-update
 * transient, and the answer — version and zip of the latest GitHub
 * release — feeds the normal wp-admin update flow. No update server and
 * no updater plugin on the sites: the release workflow's zip IS the
 * package (git archive with the ovos-console/ prefix, so the install
 * directory survives the swap).
 *
 * Fire-and-forget like the rest of the plugin: any failure — offline
 * host, API rate limit, renamed asset, the repository not (yet) public —
 * returns $update unchanged and simply means "no update visible right
 * now". A successful answer is cached for twelve hours: core's own
 * rebuild schedule is about as frequent, but the "Check again" button
 * bypasses it, and the unauthenticated GitHub API budget (60 requests
 * per hour) is shared by every site behind the same egress IP.
 */
class Updater
{
	protected const RELEASES = 'https://api.github.com/repos/ovos/console-client-wordpress/releases/latest';
	
	protected const ASSET = 'ovos-console.zip';
	
	protected const CACHE_KEY = 'ovos_console_latest_release';
	
	public function __construct(
		protected string $file,
	)
	{
	}
	
	public function register(): void
	{
		add_filter('update_plugins_github.com', [$this, 'check'], 10, 3);
	}
	
	/**
	 * update_plugins_github.com: answer for this plugin only — the hook
	 * fires for every installed plugin whose Update URI points at GitHub.
	 * Core version_compare()s the answer against the installed version
	 * itself and sorts it into response (update offered) or no_update, so
	 * the latest release is always returned, never compared here.
	 *
	 * @param array|false $update whatever an earlier filter decided
	 * @return array|false
	 */
	public function check(
		$update,
		array $pluginData,
		string $pluginFile,
	)
	{
		if($pluginFile !== plugin_basename($this->file))
		{
			return $update;
		}
		
		$release = $this->latestRelease();
		
		if($release === null)
		{
			return $update;
		}
		
		return [
			'id' => 'github.com/ovos/console-client-wordpress',
			'slug' => 'ovos-console',
			'plugin' => $pluginFile,
			'version' => $release['version'],
			'url' => $release['url'],
			'package' => $release['package'],
		];
	}
	
	/**
	 * The latest GitHub release as [version, url, package]; null whenever
	 * it cannot be known, which the caller treats as "no update visible"
	 */
	protected function latestRelease(): ?array
	{
		$cached = get_transient(self::CACHE_KEY);
		
		if(is_array($cached))
		{
			return $cached;
		}
		
		$response = wp_remote_get(self::RELEASES, [
			'timeout' => 5,
			'headers' => ['Accept' => 'application/vnd.github+json'],
		]);
		
		if(is_wp_error($response)
			|| (int)wp_remote_retrieve_response_code($response) !== 200)
		{
			return null;
		}
		
		$body = json_decode(wp_remote_retrieve_body($response), true);
		$release = $this->parse(is_array($body) ? $body : []);
		
		if($release !== null)
		{
			set_transient(self::CACHE_KEY, $release, HOUR_IN_SECONDS * 12);
		}
		
		return $release;
	}
	
	/**
	 * The fields the updater needs, or null when the release does not
	 * carry a plausible version tag and the workflow-built zip asset
	 */
	protected function parse(array $body): ?array
	{
		$version = ltrim(is_string($body['tag_name'] ?? null) ? $body['tag_name'] : '', 'v');
		
		if(preg_match('~^\d+(?:\.\d+){1,3}$~', $version) !== 1)
		{
			return null;
		}
		
		$package = '';
		$assets = is_array($body['assets'] ?? null) ? $body['assets'] : [];
		
		foreach($assets as $asset)
		{
			if(is_array($asset)
				&& ($asset['name'] ?? '') === self::ASSET
				&& is_string($asset['browser_download_url'] ?? null))
			{
				$package = $asset['browser_download_url'];
				break;
			}
		}
		
		if($package === '')
		{
			return null;
		}
		
		return [
			'version' => $version,
			'url' => is_string($body['html_url'] ?? null)
				? $body['html_url']
				: 'https://github.com/ovos/console-client-wordpress/releases',
			'package' => $package,
		];
	}
}
