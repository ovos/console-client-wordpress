<?php
declare(strict_types=1);

namespace OvosConsole;

use function in_array;
use function is_array;
use function is_string;
use function ltrim;
use function max;
use function mb_substr;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function preg_split;
use function rawurldecode;
use function rawurlencode;
use function strlen;
use function strpos;
use function strtolower;
use function strtr;
use function substr;
use function trim;

/**
 * Scrubs request variables and extras before they leave the site.
 *
 * Secret fields (per docs/API.V1.md plus WordPress's own login/profile field
 * names) are dropped entirely; username fields are anonymized to their first
 * character and e-mail addresses (in any value) are masked with the domain
 * kept. The console redacts again server-side as a backstop.
 */
final class Redactor
{
	/**
	 * Field names whose value is dropped ([redacted]). pass(word|wd)? also
	 * covers pass1/pass2/user_pass as a substring; pwd is wp-login's field.
	 */
	protected const REDACT_PATTERN =
		'/pass(word|wd)?|pwd|token|secret|authorization|cookie|api[-_]key/i';
	
	/**
	 * Field names whose value is masked to all-but-the-first character
	 * (e.g. "marcin" -> "m***"). Includes WordPress's own login field names —
	 * log (wp-login) and user_login (profile/registration) — beside the generic
	 * ones. Anchored, so identifier fields like userId / userAgent stay intact.
	 */
	protected const USERNAME_PATTERN = '/^(user([_-]?(name|login))?|log(in)?)$/i';
	
	/**
	 * Names that are credentials ONLY as query parameters, kept apart from
	 * REDACT_PATTERN because they are too generic to drop as field names.
	 *
	 * `key` is WordPress's own password-reset token: wp-login.php?action=rp&
	 * key=<20 chars>&login=<user>. Nothing above touched it — api[-_]key needs
	 * the api — so a 404 or an error on a reset link sent a live key. `login`
	 * was already masked; the key beside it was not.
	 */
	protected const QUERY_NAMES = ['key', 'auth', 'code', 'sig', 'signature'];
	
	/**
	 * A path segment is a secret, not a slug, when it has no word structure and
	 * carries the character mix a generated token does: a JWT, a uuid, a long
	 * hex string, or one long run of mixed case with digits. Single-use
	 * credentials travel in paths and are followed over GET — /reset/<token>,
	 * /invite/<token> — and this plugin had nothing between such a URL and the
	 * console.
	 *
	 * The rule is shared with the console's own Scrubber and the browser
	 * client; the corpus that pins all three lives in the console repo
	 * (project/application/tests/fixtures/looks-secret.json). Keep them equal:
	 * a length-only version of this once redacted every German slug.
	 */
	protected const PATH_CANDIDATE = '~(/)([A-Za-z0-9_.-]{20,})(?=[/?#]|$)~';
	
	/**
	 * An e-mail address inside a string value. The first local-part character
	 * is kept and the domain is left intact (j***@example.com) — enough to tell
	 * providers/customers apart while dropping the identifying part.
	 */
	protected const EMAIL_PATTERN =
		'/([a-z0-9._%+\-])[a-z0-9._%+\-]*@([a-z0-9.\-]+\.[a-z]{2,})/i';
	
	protected const MAX_DEPTH = 8;
	
	public static function scrub(
		array $values,
		int $depth = 0,
	): array
	{
		if($depth >= self::MAX_DEPTH)
		{
			return ['[redacted]'];
		}
		
		$clean = [];
		
		foreach($values as $key => $value)
		{
			// secret fields are dropped wholesale — before recursing, so a
			// secret key whose value is an array cannot leak through its children
			if(preg_match(self::REDACT_PATTERN, (string)$key) === 1)
			{
				$clean[$key] = '[redacted]';
				
				continue;
			}
			
			if(is_array($value))
			{
				$clean[$key] = self::scrub($value, $depth + 1);
				
				continue;
			}
			
			if(is_string($value) === false)
			{
				$clean[$key] = $value;
				
				continue;
			}
			
			// an e-mail in ANY field (a login that is an e-mail, a "to"
			// address, ...) — masked with the domain kept
			$masked = (string)preg_replace(self::EMAIL_PATTERN, '${1}***@${2}', $value);
			
			if($masked !== $value)
			{
				$clean[$key] = $masked;
				
				continue;
			}
			
			if(preg_match(self::USERNAME_PATTERN, (string)$key) === 1)
			{
				$clean[$key] = self::maskName($value);
				
				continue;
			}
			
			$clean[$key] = $value;
		}
		
		return $clean;
	}
	
	/**
	 * Scrubs a URL the way scrub() scrubs request arrays: secret-named query
	 * parameters are dropped, e-mail values (in any parameter) are masked
	 * with the domain kept and username-named parameters are anonymized.
	 * The same data already leaves through request.get — this closes the
	 * uri/referer copy of it. Untouched parameters stay byte-for-byte
	 * identical; values are only re-encoded when changed.
	 */
	public static function scrubUrl(
		string $url,
	): string
	{
		// the PATH first — a token in a path is not a query parameter and had
		// no rule at all here
		$position = strpos($url, '?');
		$url = self::scrubPath(
			$position === false ? $url : substr($url, 0, $position),
		) . ($position === false ? '' : substr($url, $position));
		$position = strpos($url, '?');
		if($position !== false)
		{
			$query = (string)preg_replace_callback(
				'~(^|&)([^&=]+)=([^&]*)~',
				static function(array $match): string
				{
					$name = rawurldecode($match[2]);
					if(preg_match(self::REDACT_PATTERN, $name) === 1
						|| in_array(strtolower(trim($name)), self::QUERY_NAMES, true))
					{
						return $match[1] . $match[2] . '=[redacted]';
					}
					
					$value = rawurldecode($match[3]);
					$masked = (string)preg_replace(self::EMAIL_PATTERN, '${1}***@${2}', $value);
					if($masked === $value
						&& preg_match(self::USERNAME_PATTERN, rawurldecode($match[2])) === 1)
					{
						$masked = self::maskName($value);
					}
					
					if($masked === $value)
					{
						return $match[0];
					}
					
					// keep the mask readable — @ and * are legal in a query
					return $match[1] . $match[2] . '='
						. strtr(rawurlencode($masked), ['%40' => '@', '%2A' => '*']);
				},
				substr($url, $position + 1),
			);
			
			$url = substr($url, 0, $position + 1) . $query;
		}
		
		// a plain e-mail in the path (unsubscribe links and the like)
		return (string)preg_replace(self::EMAIL_PATTERN, '${1}***@${2}', $url);
	}
	
	/**
	 * Scrubs CLI argv (WP-CLI) the way scrub() scrubs request arrays. Both
	 * argument styles are covered: --password=x / password=x get the value
	 * dropped, and a bare secret-named token drops the FOLLOWING argument
	 * ("name value" pair style). E-mails in any argument are masked with
	 * the domain kept.
	 */
	public static function scrubArgs(
		array $args,
	): array
	{
		$removeNext = false;
		
		foreach($args as $key => $arg)
		{
			if(is_string($arg) === false)
			{
				continue;
			}
			
			if($removeNext)
			{
				$args[$key] = '[redacted]';
				$removeNext = false;
				
				continue;
			}
			
			if(preg_match('~^(--?)?([^=]+)=(.*)$~s', $arg, $match) === 1)
			{
				$args[$key] = preg_match(self::REDACT_PATTERN, $match[2]) === 1
					? $match[1] . $match[2] . '=[redacted]'
					: (string)preg_replace(self::EMAIL_PATTERN, '${1}***@${2}', $arg);
				
				continue;
			}
			
			if(preg_match(self::REDACT_PATTERN, ltrim($arg, '-')) === 1)
			{
				// the name stays, the value that follows is dropped
				$removeNext = true;
				
				continue;
			}
			
			$args[$key] = (string)preg_replace(self::EMAIL_PATTERN, '${1}***@${2}', $arg);
		}
		
		return $args;
	}
	
	/**
	 * Keeps the first character and masks the rest (marcin -> m***); an empty
	 * string stays empty. The fixed suffix does not leak the original length.
	 */
	/**
	 * Token-shaped PATH segments -> [redacted], leaving readable slugs alone.
	 */
	public static function scrubPath(
		string $path,
	): string
	{
		return (string)preg_replace_callback(
			self::PATH_CANDIDATE,
			static fn(array $match): string => self::looksSecret($match[2])
				? $match[1] . '[redacted]'
				: $match[0],
			$path,
		);
	}
	
	/**
	 * A path segment ending in one of these is a static asset, not a
	 * credential — see looksSecret().
	 *
	 * Build output and media only. `pdf`, `zip`, `csv`, `xlsx`, `json`, `xml`
	 * and friends are deliberately absent: a signed one-time download link ends
	 * in one of those, and none of them is ever emitted by a bundler.
	 */
	protected const ASSET_PATTERN = '~\.(?:js|mjs|cjs|jsx|ts|tsx|css|scss|less|map|wasm'
		. '|woff2?|ttf|otf|eot'
		. '|svg|png|jpe?g|gif|webp|avif|ico|bmp'
		. '|mp3|mp4|webm|ogg|wav)$~i';
		
	/**
	 * See PATH_CANDIDATE: a generated token, not a slug. Where it is genuinely
	 * ambiguous this errs towards redaction — an unreadable URI costs less than
	 * a leaked reset token.
	 *
	 * A cache-busted STATIC ASSET is the exception, and not an ambiguous one:
	 * every bundler names its output after a content hash (WordPress ships
	 * plenty — main.<md5>.js, index-DkL9mQxZ8vB2nR4tY7wA.js), and each trips a
	 * rule below on its 32-character or mixed-case run. Nothing was protected
	 * by that: the browser fetched the file with no credential. It cost `file`,
	 * which is the field a JS error is read from. A one-time credential in a
	 * path is a bare segment; it does not end in .js.
	 */
	public static function looksSecret(
		string $segment,
	): bool
	{
		// before the asset rule: a uuid and a bare hex run hold no dot, so only
		// a JWT could end in something that reads like an extension, and a JWT
		// stays a JWT
		if(preg_match('~^eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$~', $segment) === 1
			|| preg_match('~^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$~i', $segment) === 1
			|| preg_match('~^[0-9a-f]{24,}$~i', $segment) === 1)
		{
			return true;
		}
		
		if(preg_match(self::ASSET_PATTERN, $segment) === 1)
		{
			return false;
		}
		
		if(strlen($segment) < 24)
		{
			return false;
		}
		
		$longest = 0;
		foreach(preg_split('~[-_.]+~', $segment) ?: [] as $run)
		{
			$longest = max($longest, strlen($run));
		}
		
		$digits = preg_match('~[0-9]~', $segment) === 1;
		
		// one long mixed-case run with digits, or a very long single run
		return ($digits && preg_match('~[A-Z]~', $segment) === 1 && $longest >= 16)
			|| ($digits && $longest >= 32);
	}
	
	/**
	 * First character + *** — the identity mask for usernames (same format
	 * the php-library sender uses), enough to tell accounts apart in a
	 * grouped issue while dropping the identifying part
	 */
	public static function maskName(
		string $value,
	): string
	{
		if($value === '')
		{
			return $value;
		}
		
		return mb_substr($value, 0, 1) . '***';
	}
	
	/**
	 * Masks e-mail addresses inside a plain string (j***@example.com),
	 * the domain kept — for message text that may quote an address
	 */
	public static function maskEmails(
		string $value,
	): string
	{
		return (string)preg_replace(self::EMAIL_PATTERN, '${1}***@${2}', $value);
	}
}
