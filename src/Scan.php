<?php
declare(strict_types=1);
// phpcs:disable WordPress.WP.AlternativeFunctions -- a read-only walk of the site's own tree (scandir, stat, a few hundred bytes read per candidate): WP_Filesystem exists for WRITES through FTP credentials and has nothing to offer a shutdown-time read; nothing here ever writes

namespace OvosConsole;

use function apply_filters;
use function array_pop;
use function basename;
use function bin2hex;
use function count;
use function defined;
use function dirname;
use function explode;
use function fclose;
use function file_get_contents;
use function fileperms;
use function fopen;
use function fread;
use function function_exists;
use function get_bloginfo;
use function get_option;
use function get_theme_root;
use function home_url;
use function implode;
use function in_array;
use function ini_get;
use function is_dir;
use function is_file;
use function is_link;
use function is_multisite;
use function is_string;
use function microtime;
use function preg_match;
use function preg_replace;
use function preg_split;
use function random_bytes;
use function round;
use function rtrim;
use function sanitize_text_field;
use function scandir;
use function stat;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strrpos;
use function strtolower;
use function substr;
use function time;
use function trim;
use function wp_get_environment_type;
use function wp_get_upload_dir;
use function wp_unslash;
use function wp_parse_url;

use const ABSPATH;
use const DIRECTORY_SEPARATOR;
use const PHP_URL_HOST;
use const PHP_VERSION;
use const SCANDIR_SORT_NONE;

/**
 * Integrity scan — the static half of finding the file nobody shipped.
 *
 * Every other sensor in this plugin needs the foreign file to DO something
 * after the plugin is installed: throw (Sender::sourceFor), be saved through
 * the editor (Security), be activated (Inventory). A shell dropped before the
 * plugin arrived, used once and left behind, does none of that. This class
 * asks the tree directly — is there executable code where none belongs, an
 * image that opens with `<?php`, a directive that makes images execute, a
 * drop-in whose owner is not installed — and answers with paths, never with
 * contents.
 *
 * READS ONLY. Never writes, deletes, renames or quarantines: a wrong guess
 * must cost a second look, not a file. Never touches .htaccess. Never
 * follows a symlink. Never descends into a plugin-owned data directory
 * (backups can be gigabytes) — the directory itself is the finding.
 *
 * CHUNKED: the walk keeps its whole position in an array (a stack of
 * pending directories, counters, findings so far) that the runner persists
 * between requests, so a shutdown hook can spend 500 ms per request and a
 * button press a few seconds, and both resume where they stopped. Phases
 * run in order of expected value — the root, then uploads, then wp-content
 * itself — so an interrupted pass has already shipped the urgent part.
 *
 * PRECISION FIRST: a scanner that names something innocent gets switched
 * off, which is worse than one that is late. Every heuristic here carries
 * its known false positive and how it is held down (the `index.php` stubs
 * plugins write into uploads, Wordfence's prepend file, managed hosts'
 * drop-ins). The tier on a finding is a PROPOSAL; the console decides.
 */
class Scan
{
	public const TIER_URGENT = 'urgent';
	
	public const TIER_HIGH = 'high';
	
	public const TIER_INFO = 'info';
	
	public const TIERS = [self::TIER_URGENT, self::TIER_HIGH, self::TIER_INFO];
	
	public const AREAS = ['root', 'uploads', 'content', 'plugins', 'core', 'themes'];
	
	/**
	 * Findings carried in full; past this the report counts them per tier.
	 * Urgent-first phase order means the cap eats the least interesting end.
	 */
	public const MAX_FINDINGS = 200;
	
	protected const MAX_PATH = 512;
	
	protected const MAX_DETAIL = 160;
	
	protected const MAX_SKIPPED = 50;
	
	protected const MAX_DIRECTIVES_PER_FILE = 10;
	
	/**
	 * The first block of a media-shaped file — enough to see the `<?php`
	 * that a favicon.ico shell puts behind a few bytes of decoy
	 */
	protected const HEAD_BYTES = 1024;
	
	protected const STUB_BYTES = 256;
	
	protected const DIRECTIVE_BYTES = 65536;
	
	protected const MIN_POLYGLOT_SIZE = 5;
	
	/**
	 * Executable-shaped: the PHP family plus what Apache may hand to PHP or
	 * SSI. `.php.` inside the name counts too — `shell.php.jpg` executes
	 * wherever AddHandler (not SetHandler) maps .php.
	 */
	protected const EXECUTABLE = '~\.(?:ph(?:p\d?|tml|ar)|inc|shtml)(?:\.|$)~i';
	
	/**
	 * Media-shaped names probed for a PHP opener. Binary formats everywhere;
	 * text formats only under uploads, where a `.txt` opening with `<?php`
	 * has no honest explanation (a plugin's test fixture might).
	 */
	protected const MEDIA = ['ico', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'pdf', 'svg'];
	
	protected const MEDIA_TEXT = ['txt', 'log', 'csv'];
	
	protected const DIRECTIVE_FILES = ['.htaccess', '.user.ini', 'php.ini'];
	
	/**
	 * Dotfile PHP that developer tooling ships inside packages — a code-style
	 * config is not a hidden shell, and vendored plugins carry them by the
	 * dozen
	 */
	protected const HIDDEN_TOOLING = '~^\.(?:php-cs-fixer|php_cs|phpstorm\.meta|phpunit|phpcs|phpmd|phplint|phpstan|psalm)~i';
	
	/**
	 * WordPress 6.5+ writes translations as PHP (`<locale>.l10n.php`, or
	 * `<textdomain>-<locale>.l10n.php`) under wp-content/languages — core's own
	 * executable-shaped data files, loaded only by name for a requested locale
	 */
	protected const TRANSLATION = '~\.l10n\.php$~';
	
	/**
	 * Never descended: version control (thousands of objects, and its
	 * presence in the docroot is its own posture line) and node_modules
	 */
	protected const SKIP_DIRS = ['.git', '.svn', '.hg', 'node_modules'];
	
	/**
	 * wp-content directories skipped whole: page caches (caching plugins
	 * write PHP-named cache files by the thousand) and core's own upgrade
	 * staging
	 */
	protected const CONTENT_SKIP = ['cache', 'upgrade', 'upgrade-temp-backup'];
	
	/**
	 * A "Silence is golden." index.php — the stub WordPress and half its
	 * plugins write into directories to stop listings: an opener, comments,
	 * an optional closer, nothing else
	 */
	protected const STUB = '~^\s*<\?php\s*(?:(?://|#)[^\n]*\s*)*(?:\?>)?\s*$~';
	
	/**
	 * Drop-ins and other wp-content files WordPress or a plugin writes, and
	 * the plugins that write them. A file here with none of its owners
	 * installed is a stranger wearing a known name.
	 */
	protected const CONTENT_OWNERS = [
		'advanced-cache.php' => ['wp-super-cache', 'w3-total-cache', 'wp-rocket', 'litespeed-cache',
			'wp-fastest-cache', 'hummingbird-performance', 'breeze', 'cache-enabler', 'comet-cache',
			'swift-performance', 'swift-performance-lite', 'nitropack', 'flying-press', 'wp-optimize',
			'sg-cachepress', 'powered-cache', 'hyper-cache', 'simple-cache', 'surge',
			'wp-cloudflare-page-cache', 'seraphinite-accelerator', 'speed-booster-pack'],
		'object-cache.php' => ['redis-cache', 'w3-total-cache', 'litespeed-cache', 'memcached',
			'memcached-redux', 'object-cache-pro', 'wp-redis', 'sqlite-object-cache', 'docket-cache',
			'powered-cache', 'apcu-manager', 'relay-cache', 'wp-spider-cache', 'hummingbird-performance'],
		'db.php' => ['query-monitor', 'ludicrousdb', 'hyperdb', 'w3-total-cache',
			'sqlite-database-integration', 'wp-db-driver'],
		'wp-cache-config.php' => ['wp-super-cache'],
	];
	
	/**
	 * wp-content directories a plugin (or theme) owns and fills with its own
	 * PHP-named data and logs. Never descended; owner installed → listed,
	 * owner missing → a finding.
	 */
	protected const WRITER_DIRS = [
		'wflogs' => ['wordfence'],
		'wfcache' => ['wordfence'],
		'w3tc-config' => ['w3-total-cache'],
		'wp-rocket-config' => ['wp-rocket'],
		'litespeed' => ['litespeed-cache'],
		'sucuri' => ['sucuri-scanner'],
		'nfwlog' => ['ninjafirewall'],
		'wp-cloudflare-super-page-cache' => ['wp-cloudflare-page-cache'],
		'bps-backup' => ['bulletproof-security'],
		'aiowps_backups' => ['all-in-one-wp-security-and-firewall'],
		'backups-dup-lite' => ['duplicator'],
		'backups-dup-pro' => ['duplicator-pro'],
		'ai1wm-backups' => ['all-in-one-wp-migration'],
		'updraft' => ['updraftplus'],
		'wpvividbackups' => ['wpvivid-backuprestore'],
		'wp-security-audit-log' => ['wp-security-audit-log'],
		'ithemes-security' => ['better-wp-security', 'ithemes-security-pro'],
		'siteground-optimizer-assets' => ['sg-cachepress'],
		'smush-webp' => ['wp-smushit', 'wp-smush-pro'],
		'ewww' => ['ewww-image-optimizer'],
		'et-cache' => ['theme:Divi', 'theme:Extra'],
		'backup-db' => ['wp-db-backup'],
	];
	
	/**
	 * PHP files a plugin legitimately puts in the document ROOT
	 */
	protected const ROOT_OWNERS = [
		'wordfence-waf.php' => ['wordfence'],
	];
	
	/**
	 * Area roots for the pass in progress — set from the state on every
	 * advance() so the helpers can classify without threading the array
	 *
	 * @var array<string, string>
	 */
	protected array $roots = [];
	
	protected ?string $host = null;
	
	public function __construct(
		protected Config $config,
	)
	{
	}
	
	/**
	 * A fresh pass: the phase list, empty counters, no position yet
	 */
	public function start(
		string $mode,
	): array
	{
		$roots = $this->roots();
		
		$phases = [['root', $roots['root'], false, []]];
		
		if($roots['uploads'] !== '' && is_dir($roots['uploads']))
		{
			$phases[] = ['uploads', $roots['uploads'], true, []];
		}
		
		$phases[] = ['content', $roots['content'], false, []];
		
		if(is_dir($roots['mu']))
		{
			$phases[] = ['content', $roots['mu'], true, ['mu' => true]];
		}
		
		$phases[] = ['plugins', $roots['plugins'], true, []];
		$phases[] = ['core', $roots['root'] . '/wp-admin', true, []];
		$phases[] = ['core', $roots['root'] . '/wp-includes', true, []];
		$phases[] = ['themes', $roots['themes'], true, []];
		$phases[] = ['posture', '', false, []];
		
		$areas = [];
		
		foreach(self::AREAS as $area)
		{
			$areas[$area] = [
				'root' => match($area)
				{
					'uploads' => $roots['uploads'],
					'content' => $roots['content'],
					'plugins' => $roots['plugins'],
					'themes' => $roots['themes'],
					default => $roots['root'],
				},
				'files' => 0,
				'dirs' => 0,
				'executable' => 0,
				'bytes' => 0,
				'probed' => 0,
			];
		}
		
		return [
			'id' => bin2hex(random_bytes(8)),
			'mode' => $mode,
			'started' => time(),
			'elapsed' => 0,
			'chunks' => 0,
			'roots' => $roots,
			'phases' => $phases,
			'phase' => 0,
			'opened' => false,
			'stack' => [],
			'areas' => $areas,
			'findings' => [],
			'counts' => [self::TIER_URGENT => 0, self::TIER_HIGH => 0, self::TIER_INFO => 0],
			'truncated' => [self::TIER_URGENT => 0, self::TIER_HIGH => 0, self::TIER_INFO => 0],
			'skipped' => [],
			'unreadable' => 0,
			'symlinks' => 0,
			'vcs' => [],
			'uploads_htaccess' => false,
			'uploads_php_denied' => false,
			'posture' => null,
			'done' => false,
		];
	}
	
	/**
	 * Spend up to $budgetMs on the pass and hand the position back. A
	 * directory is the unit of work: the budget is checked between
	 * directories, so one enormous flat directory may overrun it once.
	 */
	public function advance(
		array $state,
		int $budgetMs,
	): array
	{
		$this->roots = $state['roots'];
		$begun = microtime(true);
		$deadline = $begun + $budgetMs / 1000;
		$state['chunks']++;
		
		while($state['done'] === false && microtime(true) < $deadline)
		{
			if($state['phase'] >= count($state['phases']))
			{
				$state['done'] = true;
				
				break;
			}
			
			[$area, $dir, $recursive, $flags] = $state['phases'][$state['phase']];
			
			if($area === 'posture')
			{
				$this->posture($state);
				$state['phase']++;
				
				continue;
			}
			
			if($state['opened'] === false)
			{
				$state['stack'] = is_dir($dir)
					? [[$area, $dir, $flags + ['recursive' => $recursive]]]
					: [];
				$state['opened'] = true;
			}
			
			if($state['stack'] === [])
			{
				$state['phase']++;
				$state['opened'] = false;
				
				continue;
			}
			
			[$area, $dir, $flags] = array_pop($state['stack']);
			
			$this->visit($state, $area, $dir, $flags);
		}
		
		$state['elapsed'] += (int)round((microtime(true) - $begun) * 1000);
		
		return $state;
	}
	
	/**
	 * A human-readable position for a progress line: the phase's area and
	 * the files counted so far
	 */
	public static function progress(
		array $state,
	): array
	{
		$phase = $state['phases'][$state['phase']] ?? null;
		$files = 0;
		
		foreach($state['areas'] as $area)
		{
			$files += (int)$area['files'];
		}
		
		return [
			'area' => $phase === null ? '' : (string)$phase[0],
			'files' => $files,
			'findings' => count($state['findings']),
		];
	}
	
	/**
	 * The report — what the console ingests and what wp-admin shows. Area
	 * roots are absolute (the console already knows server paths from
	 * every error's context.dir); finding paths are relative to their area.
	 */
	public function report(
		array $state,
		string $client,
	): array
	{
		$areas = [];
		$files = 0;
		$dirs = 0;
		
		foreach($state['areas'] as $area => $stats)
		{
			$areas[$area] = [
				'root' => $this->clean((string)$stats['root']),
				'files' => (int)$stats['files'],
				'dirs' => (int)$stats['dirs'],
				'executable' => (int)$stats['executable'],
				'bytes' => (int)$stats['bytes'],
				'probed' => (int)$stats['probed'],
			];
			$files += (int)$stats['files'];
			$dirs += (int)$stats['dirs'];
		}
		
		$report = [
			'v' => 1,
			'type' => 'files',
			'platform' => 'wordpress',
			'core' => Inventory::version((string)get_bloginfo('version')),
			'php' => Inventory::version(PHP_VERSION),
			'client' => $client,
			'scan' => [
				'id' => (string)$state['id'],
				'mode' => (string)$state['mode'],
				'started' => (int)$state['started'],
				'finished' => time(),
				'duration' => (int)$state['elapsed'],
				'chunks' => (int)$state['chunks'],
				'complete' => (bool)$state['done'],
				'files' => $files,
				'dirs' => $dirs,
				'unreadable' => (int)$state['unreadable'],
				'symlinks' => (int)$state['symlinks'],
				'skipped' => $state['skipped'],
				'counts' => $state['counts'],
				'truncated' => $state['truncated'],
			],
			'areas' => $areas,
			'findings' => $state['findings'],
			'posture' => $state['posture'] ?? [],
		];
		
		$release = $this->config->release();
		
		if($release !== '')
		{
			$report['release'] = $release;
		}
		
		$environment = $this->config->environment();
		
		if($environment !== '')
		{
			$report['environment'] = $environment;
		}
		
		return $report;
	}
	
	/**
	 * One directory: files inspected, subdirectories pushed. The root area
	 * lists its files only (wp-admin and wp-includes are the core area's);
	 * the wp-content top level classifies each subdirectory instead of
	 * descending blindly.
	 */
	protected function visit(
		array &$state,
		string $area,
		string $dir,
		array $flags,
	): void
	{
		$entries = @scandir($dir, SCANDIR_SORT_NONE);
		
		if($entries === false)
		{
			$state['unreadable']++;
			
			return;
		}
		
		$state['areas'][$area]['dirs']++;
		
		$top = $area === 'content' && $dir === $this->roots['content'];
		$recursive = (bool)($flags['recursive'] ?? false);
		
		foreach($entries as $name)
		{
			if($name === '.' || $name === '..')
			{
				continue;
			}
			
			$path = $dir . '/' . $name;
			
			if(@is_link($path))
			{
				$state['symlinks']++;
				
				continue;
			}
			
			if(@is_dir($path))
			{
				if(in_array($name, self::SKIP_DIRS, true))
				{
					if($area === 'root' && $name !== 'node_modules')
					{
						$state['vcs'][] = $name;
					}
					
					$this->skip($state, $area, $path);
					
					continue;
				}
				
				if($top)
				{
					$this->classifyContentDir($state, $path, $name);
					
					continue;
				}
				
				if($recursive === false)
				{
					continue;
				}
				
				$child = $flags;
				
				if($name[0] === '.')
				{
					$child['hidden'] = true;
				}
				
				$state['stack'][] = [$area, $path, $child];
				
				continue;
			}
			
			$this->inspect($state, $area, $path, $name, $flags);
		}
	}
	
	/**
	 * A wp-content subdirectory: the four areas walked on their own are
	 * skipped here, caches and upgrade staging are skipped whole, a
	 * plugin-owned data directory is one finding and never descended,
	 * anything else is walked as content — where executable code is a
	 * stranger.
	 */
	protected function classifyContentDir(
		array &$state,
		string $path,
		string $name,
	): void
	{
		if(in_array($path, [$this->roots['plugins'], $this->roots['themes'],
			$this->roots['uploads'], $this->roots['mu']], true))
		{
			return;
		}
		
		if(in_array($name, self::CONTENT_SKIP, true))
		{
			$this->skip($state, 'content', $path);
			
			return;
		}
		
		if(isset(self::WRITER_DIRS[$name]))
		{
			$owner = $this->installedOwner(self::WRITER_DIRS[$name]);
			
			$this->finding($state, 'content', $path,
				$owner !== null ? 'writer_dir' : 'writer_dir_orphan',
				$owner !== null ? self::TIER_INFO : self::TIER_HIGH,
				$owner !== null
					? 'owned by ' . $owner . ', not scanned'
					: 'owner not installed: ' . implode(', ', self::WRITER_DIRS[$name]));
			
			return;
		}
		
		$flags = ['recursive' => true];
		
		if($name[0] === '.')
		{
			$flags['hidden'] = true;
		}
		
		$state['stack'][] = ['content', $path, $flags];
	}
	
	/**
	 * One file: counted, then shape-checked — executable names by area,
	 * directive files by content, media names for a PHP opener
	 */
	protected function inspect(
		array &$state,
		string $area,
		string $path,
		string $name,
		array $flags,
	): void
	{
		$stat = @stat($path);
		$size = $stat !== false ? (int)$stat['size'] : 0;
		$mtime = $stat !== false ? (int)$stat['mtime'] : 0;
		
		$state['areas'][$area]['files']++;
		$state['areas'][$area]['bytes'] += $size;
		
		$meta = ['size' => $size, 'mtime' => $mtime];
		$lower = strtolower($name);
		
		if(preg_match(self::EXECUTABLE, $name) === 1)
		{
			$state['areas'][$area]['executable']++;
			
			$this->inspectExecutable($state, $area, $path, $name, $size, $flags, $meta);
			
			return;
		}
		
		if(in_array($lower, self::DIRECTIVE_FILES, true))
		{
			$this->inspectDirectives($state, $area, $path, $meta);
			
			return;
		}
		
		$this->inspectPolyglot($state, $area, $path, $lower, $size, $meta);
	}
	
	/**
	 * Executable-shaped, judged by where it stands. Hidden anywhere is a
	 * finding; under uploads anything but a listing stub is; in the root
	 * anything core did not ship is; at the wp-content top level a drop-in
	 * is judged by its owner and anything else is a stranger; in plugins,
	 * themes and core the checksum pass (a later slice) is the judge, so
	 * this one says nothing.
	 */
	protected function inspectExecutable(
		array &$state,
		string $area,
		string $path,
		string $name,
		int $size,
		array $flags,
		array $meta,
	): void
	{
		if(($flags['hidden'] ?? false) === true || $name[0] === '.')
		{
			if(preg_match(self::HIDDEN_TOOLING, $name) !== 1)
			{
				$this->finding($state, $area, $path, 'hidden_php', self::TIER_HIGH, '', $meta);
			}
			
			return;
		}
		
		switch($area)
		{
			case 'uploads':
				if($this->isStub($path, $size) === false)
				{
					$this->finding($state, $area, $path, 'uploads_php', self::TIER_URGENT, '', $meta);
				}
				
				return;
			
			case 'root':
				if(in_array($name, Sender::CORE_ROOT_FILES, true))
				{
					return;
				}
				
				$owner = isset(self::ROOT_OWNERS[$name])
					? $this->installedOwner(self::ROOT_OWNERS[$name])
					: null;
				
				$this->finding($state, $area, $path,
					$owner !== null ? 'root_php_owned' : 'root_php',
					$owner !== null ? self::TIER_INFO : self::TIER_URGENT,
					$owner !== null ? 'owned by ' . $owner : '', $meta);
				
				return;
			
			case 'content':
				$this->inspectContentExecutable($state, $path, $name, $size, $flags, $meta);
				
				return;
			
			default:
				return;
		}
	}
	
	protected function inspectContentExecutable(
		array &$state,
		string $path,
		string $name,
		int $size,
		array $flags,
		array $meta,
	): void
	{
		$parent = dirname($path);
		
		if(($flags['mu'] ?? false) === true)
		{
			// only the top level of mu-plugins is loaded; deeper files are
			// its includes and belong to the file that requires them
			if($parent === $this->roots['mu'])
			{
				$this->finding($state, 'content', $path, 'mu_plugin', self::TIER_INFO, '', $meta);
			}
			
			return;
		}
		
		if($parent !== $this->roots['content'])
		{
			// core's PHP translation files live under languages/ and its
			// plugins/ and themes/ subdirectories, named for their text domain
			// and locale; anything else executable there is a stranger
			$translation = str_starts_with($path, $this->roots['content'] . '/languages/')
				&& preg_match(self::TRANSLATION, $name) === 1;
			
			if($translation === false && $this->isStub($path, $size) === false)
			{
				$this->finding($state, 'content', $path, 'content_php', self::TIER_HIGH, '', $meta);
			}
			
			return;
		}
		
		if($name === 'index.php' && $this->isStub($path, $size))
		{
			return;
		}
		
		if(isset(self::CONTENT_OWNERS[$name]))
		{
			$owner = $this->installedOwner(self::CONTENT_OWNERS[$name]);
			
			if($owner !== null)
			{
				$this->finding($state, 'content', $path, 'dropin', self::TIER_INFO,
					'owned by ' . $owner, $meta);
				
				return;
			}
			
			// managed hosts drop their own object-cache.php with a vendor
			// header and no plugin behind it; a header is not proof, but a
			// drop-in WITHOUT one and without an owner is the stranger
			$this->finding($state, 'content', $path,
				$this->looksVendored($path) ? 'dropin' : 'dropin_orphan',
				$this->looksVendored($path) ? self::TIER_INFO : self::TIER_HIGH,
				'no installed owner', $meta);
			
			return;
		}
		
		if(in_array($name, Sender::CONTENT_DROPINS, true))
		{
			// install.php runs core's installer hooks and has no business on
			// a live site; the rest (maintenance, error pages, sunrise) are
			// hand-written by design and only listed
			$this->finding($state, 'content', $path, 'dropin',
				$name === 'install.php' ? self::TIER_HIGH : self::TIER_INFO,
				$name === 'install.php' ? 'install drop-in on a live site' : '', $meta);
			
			return;
		}
		
		$this->finding($state, 'content', $path, 'content_php', self::TIER_HIGH, '', $meta);
	}
	
	/**
	 * A media-shaped name whose first block carries a PHP opener — the
	 * favicon shell, the `.jpg` behind an AddHandler
	 */
	protected function inspectPolyglot(
		array &$state,
		string $area,
		string $path,
		string $lower,
		int $size,
		array $meta,
	): void
	{
		if($size < self::MIN_POLYGLOT_SIZE)
		{
			return;
		}
		
		$dot = strrpos($lower, '.');
		$extension = $dot === false ? '' : substr($lower, $dot + 1);
		
		if(in_array($extension, self::MEDIA, true) === false
			&& ($area !== 'uploads' || in_array($extension, self::MEDIA_TEXT, true) === false))
		{
			return;
		}
		
		$state['areas'][$area]['probed']++;
		
		$head = $this->head($path, self::HEAD_BYTES);
		
		if($head !== null && (str_contains($head, '<?php') || str_contains($head, '<?=')))
		{
			$this->finding($state, $area, $path, 'polyglot', self::TIER_URGENT,
				'PHP opener in a .' . $extension . ' file', $meta);
		}
	}
	
	/**
	 * .htaccess / .user.ini / php.ini: the directives that make a foreign
	 * file execute (a prepend, an image handler, engine on under uploads)
	 * or send visitors elsewhere. The finding names the directive, the file
	 * and the line — never the line's text.
	 */
	protected function inspectDirectives(
		array &$state,
		string $area,
		string $path,
		array $meta,
	): void
	{
		$content = @file_get_contents($path, false, null, 0, self::DIRECTIVE_BYTES);
		
		if(is_string($content) === false)
		{
			return;
		}
		
		$uploads = $area === 'uploads';
		
		if($uploads && dirname($path) === $this->roots['uploads'] && basename($path) === '.htaccess')
		{
			$state['uploads_htaccess'] = true;
			$state['uploads_php_denied'] = $this->deniesPhp($content);
		}
		
		$found = 0;
		
		foreach(preg_split('~\r\n|\r|\n~', $content) ?: [] as $index => $line)
		{
			$line = trim($line);
			
			if($line === '' || $line[0] === '#' || $line[0] === ';')
			{
				continue;
			}
			
			$verdict = $this->judgeDirective($line, $uploads);
			
			if($verdict === null)
			{
				continue;
			}
			
			[$detector, $tier, $detail] = $verdict;
			
			$this->finding($state, $area, $path, $detector, $tier, $detail,
				$meta + ['line' => $index + 1]);
			
			if(++$found >= self::MAX_DIRECTIVES_PER_FILE)
			{
				return;
			}
		}
	}
	
	/**
	 * One directive line → [detector, tier, detail] or null when harmless
	 *
	 * @return array{0: string, 1: string, 2: string}|null
	 */
	protected function judgeDirective(
		string $line,
		bool $uploads,
	): ?array
	{
		if(preg_match('~auto_(prepend|append)_file\s*(?:=|\s)\s*["\']?([^"\'\s;]*)~i', $line, $match) === 1)
		{
			$target = $match[2];
			
			if($target === '' || strtolower($target) === 'none')
			{
				return null;
			}
			
			$owner = $this->ownerOfPath($target);
			
			return $owner !== null
				? ['directive_owned', self::TIER_INFO, 'auto_' . strtolower($match[1]) . '_file owned by ' . $owner]
				: ['directive_prepend', self::TIER_URGENT, 'auto_' . strtolower($match[1]) . '_file → ' . basename($target)];
		}
		
		if(preg_match('~^(?:AddHandler|AddType)\s+(\S*php\S*)\s+(.+)$~i', $line, $match) === 1)
		{
			$foreign = [];
			
			foreach(preg_split('~\s+~', trim($match[2])) ?: [] as $extension)
			{
				if(preg_match('~^\.?(?:php\d?|phtml|phar)$~i', $extension) !== 1)
				{
					$foreign[] = $extension;
				}
			}
			
			return $foreign === []
				? null
				: ['handler_php_extension', self::TIER_URGENT, 'PHP handler for ' . implode(' ', $foreign)];
		}
		
		if(preg_match('~^SetHandler\s+\S*php~i', $line) === 1)
		{
			return ['set_handler_php', $uploads ? self::TIER_URGENT : self::TIER_HIGH, 'SetHandler to PHP'];
		}
		
		if($uploads && preg_match('~^php_(?:admin_)?flag\s+engine\s+on~i', $line) === 1)
		{
			return ['engine_on_uploads', self::TIER_URGENT, 'PHP engine switched on under uploads'];
		}
		
		if($uploads && preg_match('~^(?:Options\b.*\bExecCGI|AddHandler\s+cgi-script)~i', $line) === 1)
		{
			return ['cgi_uploads', self::TIER_HIGH, 'CGI execution under uploads'];
		}
		
		if(preg_match('~^(?:RewriteRule\s+\S+|Redirect(?:Match|Permanent|Temp)?\s+(?:\d{3}\s+)?\S+|ErrorDocument\s+\d{3})\s+["\']?https?://([^/\s"\']+)~i', $line, $match) === 1)
		{
			$host = strtolower($match[1]);
			
			if($this->isForeignHost($host))
			{
				return ['redirect_external', self::TIER_HIGH, 'redirect to ' . $host];
			}
		}
		
		return null;
	}
	
	/**
	 * Whether an uploads .htaccess denies PHP — engine off, or a php
	 * Files/FilesMatch block that denies or unsets the handler
	 */
	protected function deniesPhp(
		string $content,
	): bool
	{
		return preg_match('~php_(?:admin_)?flag\s+engine\s+off~i', $content) === 1
			|| preg_match('~<Files(?:Match)?\s+[^>]*php[^>]*>.*?(?:deny\s+from\s+all|Require\s+all\s+denied|SetHandler\s+(?:none|default-handler))~is', $content) === 1;
	}
	
	/**
	 * The final phase: the site's posture, so the console's advice can say
	 * "and close the door" — plus the one live directive that no file scan
	 * sees, the ini's own auto_prepend_file
	 */
	protected function posture(
		array &$state,
	): void
	{
		$roots = $this->roots;
		$config = $this->configPath($roots['root']);
		$server = $this->server();
		$windows = DIRECTORY_SEPARATOR === '\\';
		
		$debug = defined('WP_DEBUG') && (bool)WP_DEBUG;
		
		$state['posture'] = [
			'server' => $server,
			'multisite' => function_exists('is_multisite') && is_multisite(),
			'environment' => function_exists('wp_get_environment_type') ? (string)wp_get_environment_type() : '',
			'file_edit_disabled' => defined('DISALLOW_FILE_EDIT') && (bool)DISALLOW_FILE_EDIT,
			'file_mods_disabled' => defined('DISALLOW_FILE_MODS') && (bool)DISALLOW_FILE_MODS,
			'debug' => $debug,
			'debug_display' => $debug && (defined('WP_DEBUG_DISPLAY') === false || (bool)WP_DEBUG_DISPLAY),
			'auto_update_core' => defined('WP_AUTO_UPDATE_CORE') ? $this->constantWord(WP_AUTO_UPDATE_CORE) : null,
			'updater_disabled' => defined('AUTOMATIC_UPDATER_DISABLED') && (bool)AUTOMATIC_UPDATER_DISABLED,
			'users_can_register' => (bool)get_option('users_can_register'),
			'default_role' => Inventory::slugOf((string)get_option('default_role')),
			'xmlrpc' => (bool)apply_filters('xmlrpc_enabled', true),
			'config_world_readable' => $config !== null && $windows === false ? $this->permits($config, 0004) : null,
			'uploads_world_writable' => $roots['uploads'] !== '' && $windows === false ? $this->permits($roots['uploads'], 0002) : null,
			'uploads_htaccess' => (bool)$state['uploads_htaccess'],
			'uploads_php_denied' => in_array($server, ['apache', 'litespeed'], true) ? (bool)$state['uploads_php_denied'] : null,
			'vcs_exposed' => $state['vcs'],
			'readme_html' => is_file($roots['root'] . '/readme.html'),
			'ini' => [
				'auto_prepend_file' => $this->clean((string)ini_get('auto_prepend_file')),
				'auto_append_file' => $this->clean((string)ini_get('auto_append_file')),
				'user_ini_filename' => $this->clean((string)ini_get('user_ini.filename')),
				'open_basedir' => $this->clean((string)ini_get('open_basedir')),
				'disable_functions' => $this->clean((string)ini_get('disable_functions')),
				'display_errors' => $this->clean((string)ini_get('display_errors')),
				'expose_php' => (bool)ini_get('expose_php'),
				'allow_url_include' => (bool)ini_get('allow_url_include'),
				'opcache' => (bool)ini_get('opcache.enable'),
			],
		];
		
		foreach(['auto_prepend_file', 'auto_append_file'] as $key)
		{
			$target = trim((string)ini_get($key));
			
			if($target === '' || strtolower($target) === 'none')
			{
				continue;
			}
			
			$owner = $this->ownerOfPath($target);
			
			$this->finding($state, 'root', $this->normalize($target),
				$owner !== null ? 'ini_prepend_owned' : 'ini_prepend',
				$owner !== null ? self::TIER_INFO : self::TIER_URGENT,
				$key . ($owner !== null ? ' owned by ' . $owner : ' is live in php.ini'));
		}
	}
	
	/**
	 * Record one finding, or count it once the report is full. The path is
	 * relative to its area root (absolute when it stands outside, as an ini
	 * target may) and always cleaned: it is attacker-authored.
	 */
	protected function finding(
		array &$state,
		string $area,
		string $path,
		string $detector,
		string $tier,
		string $detail = '',
		array $meta = [],
	): void
	{
		$state['counts'][$tier]++;
		
		if(count($state['findings']) >= self::MAX_FINDINGS)
		{
			$state['truncated'][$tier]++;
			
			return;
		}
		
		$finding = [
			'detector' => $detector,
			'tier' => $tier,
			'area' => $area,
			'path' => $this->clean($this->relative($path, (string)$state['areas'][$area]['root'])),
		];
		
		if(isset($meta['size']))
		{
			$finding['size'] = (int)$meta['size'];
		}
		
		if(isset($meta['mtime']))
		{
			$finding['mtime'] = (int)$meta['mtime'];
		}
		
		if(isset($meta['line']))
		{
			$finding['line'] = (int)$meta['line'];
		}
		
		if($detail !== '')
		{
			$finding['detail'] = $this->clean($detail, self::MAX_DETAIL);
		}
		
		$state['findings'][] = $finding;
	}
	
	protected function skip(
		array &$state,
		string $area,
		string $path,
	): void
	{
		if(count($state['skipped']) < self::MAX_SKIPPED)
		{
			$state['skipped'][] = $area . ':'
				. $this->clean($this->relative($path, (string)$state['areas'][$area]['root']));
		}
	}
	
	/**
	 * The first installed owner of a plugin-written path — plugin slugs, or
	 * `theme:<dir>` — as a label, or null
	 *
	 * @param string[] $owners
	 */
	protected function installedOwner(
		array $owners,
	): ?string
	{
		foreach($owners as $owner)
		{
			[$type, $slug] = str_contains($owner, ':') ? explode(':', $owner, 2) : ['plugin', $owner];
			$root = $type === 'theme' ? $this->roots['themes'] : $this->roots['plugins'];
			
			if($slug !== '' && is_dir($root . '/' . $slug))
			{
				return $owner;
			}
		}
		
		return null;
	}
	
	/**
	 * Who owns an arbitrary path a directive points at: a file inside an
	 * installed plugin's directory, or a known root file whose plugin is
	 * installed (Wordfence's prepend). Anything else is nobody's.
	 */
	protected function ownerOfPath(
		string $target,
	): ?string
	{
		$target = $this->normalize($target);
		$name = basename($target);
		
		if(isset(self::ROOT_OWNERS[$name]))
		{
			$owner = $this->installedOwner(self::ROOT_OWNERS[$name]);
			
			if($owner !== null)
			{
				return $owner;
			}
		}
		
		foreach(['plugins' => 'plugin', 'mu' => 'mu-plugin'] as $root => $type)
		{
			$prefix = $this->roots[$root] . '/';
			
			if(str_starts_with($target, $prefix))
			{
				$slug = explode('/', substr($target, strlen($prefix)), 2)[0];
				
				if($slug !== '' && (is_dir($prefix . $slug) || is_file($prefix . $slug)))
				{
					return $type . ':' . $slug;
				}
			}
		}
		
		return null;
	}
	
	/**
	 * Whether a redirect target leaves the site — hosts compared without a
	 * leading www., so a move between the two spellings is not a finding
	 */
	protected function isForeignHost(
		string $host,
	): bool
	{
		$this->host ??= $this->siteHost();
		
		$strip = static fn(string $value): string => preg_replace('~^www\.~', '', strtolower(explode(':', $value, 2)[0])) ?? '';
		
		return $this->host !== '' && $strip($host) !== $strip($this->host);
	}
	
	protected function siteHost(): string
	{
		if(function_exists('home_url') === false)
		{
			return '';
		}
		
		$host = wp_parse_url((string)home_url(), PHP_URL_HOST);
		
		return is_string($host) ? $host : '';
	}
	
	protected function isStub(
		string $path,
		int $size,
	): bool
	{
		if($size === 0)
		{
			return true;
		}
		
		if($size > self::STUB_BYTES)
		{
			return false;
		}
		
		$head = $this->head($path, self::STUB_BYTES);
		
		return $head !== null && preg_match(self::STUB, $head) === 1;
	}
	
	/**
	 * A drop-in that carries a vendor header — plugin headers, package
	 * tags, a copyright or licence line — in its first kilobyte
	 */
	protected function looksVendored(
		string $path,
	): bool
	{
		$head = $this->head($path, self::HEAD_BYTES);
		
		return $head !== null
			&& preg_match('~Plugin Name:|@package|@author|Copyright|License|Version:~i', $head) === 1;
	}
	
	protected function head(
		string $path,
		int $bytes,
	): ?string
	{
		$handle = @fopen($path, 'rb');
		
		if($handle === false)
		{
			return null;
		}
		
		$head = @fread($handle, $bytes);
		fclose($handle);
		
		return is_string($head) ? $head : null;
	}
	
	protected function permits(
		string $path,
		int $bit,
	): ?bool
	{
		$perms = @fileperms($path);
		
		return $perms === false ? null : ($perms & $bit) !== 0;
	}
	
	/**
	 * wp-config.php stands in ABSPATH or, by core's own convention, one
	 * level above it
	 */
	protected function configPath(
		string $root,
	): ?string
	{
		foreach([$root . '/wp-config.php', dirname($root) . '/wp-config.php'] as $candidate)
		{
			if(is_file($candidate))
			{
				return $candidate;
			}
		}
		
		return null;
	}
	
	protected function server(): string
	{
		$software = isset($_SERVER['SERVER_SOFTWARE'])
			? strtolower(sanitize_text_field(wp_unslash((string)$_SERVER['SERVER_SOFTWARE'])))
			: '';
		
		return match(true)
		{
			str_contains($software, 'litespeed') => 'litespeed',
			str_contains($software, 'apache') => 'apache',
			str_contains($software, 'nginx') => 'nginx',
			str_contains($software, 'microsoft-iis') => 'iis',
			str_contains($software, 'caddy') => 'caddy',
			default => 'unknown',
		};
	}
	
	protected function constantWord(
		mixed $value,
	): string
	{
		if($value === true)
		{
			return 'true';
		}
		
		if($value === false)
		{
			return 'false';
		}
		
		return $this->clean((string)$value, 32);
	}
	
	/**
	 * The area roots, normalised to forward slashes without a trailing one
	 *
	 * @return array<string, string>
	 */
	protected function roots(): array
	{
		$root = $this->normalize((string)ABSPATH);
		$content = defined('WP_CONTENT_DIR') ? $this->normalize((string)WP_CONTENT_DIR) : $root . '/wp-content';
		
		return [
			'root' => $root,
			'content' => $content,
			'plugins' => defined('WP_PLUGIN_DIR') ? $this->normalize((string)WP_PLUGIN_DIR) : $content . '/plugins',
			'mu' => defined('WPMU_PLUGIN_DIR') ? $this->normalize((string)WPMU_PLUGIN_DIR) : $content . '/mu-plugins',
			'themes' => function_exists('get_theme_root') ? $this->normalize((string)get_theme_root()) : $content . '/themes',
			'uploads' => function_exists('wp_get_upload_dir')
				? $this->normalize((string)(wp_get_upload_dir()['basedir'] ?? ''))
				: '',
		];
	}
	
	protected function normalize(
		string $path,
	): string
	{
		return rtrim(str_replace('\\', '/', $path), '/');
	}
	
	protected function relative(
		string $path,
		string $root,
	): string
	{
		$path = $this->normalize($path);
		
		return $root !== '' && str_starts_with($path, $root . '/')
			? substr($path, strlen($root) + 1)
			: $path;
	}
	
	/**
	 * Attacker-authored text on its way to a report: control characters
	 * replaced, length capped
	 */
	protected function clean(
		string $value,
		int $max = self::MAX_PATH,
	): string
	{
		$value = (string)preg_replace('~[\x00-\x1f\x7f]~', '?', $value);
		
		return strlen($value) > $max ? substr($value, 0, $max) : $value;
	}
}
