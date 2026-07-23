<?php
declare(strict_types=1);

namespace OvosConsole;

use function is_array;
use function is_string;
use function ltrim;
use function mb_substr;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function rawurldecode;
use function rawurlencode;
use function strpos;
use function strtr;
use function substr;

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
	 * Field names whose value is dropped ([removed]). pass(word|wd)? also
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
			return ['[removed]'];
		}
		
		$clean = [];
		
		foreach($values as $key => $value)
		{
			// secret fields are dropped wholesale — before recursing, so a
			// secret key whose value is an array cannot leak through its children
			if(preg_match(self::REDACT_PATTERN, (string)$key) === 1)
			{
				$clean[$key] = '[removed]';
				
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
		$position = strpos($url, '?');
		if($position !== false)
		{
			$query = (string)preg_replace_callback(
				'~(^|&)([^&=]+)=([^&]*)~',
				static function(array $match): string
				{
					if(preg_match(self::REDACT_PATTERN, rawurldecode($match[2])) === 1)
					{
						return $match[1] . $match[2] . '=[removed]';
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
				$args[$key] = '[removed]';
				$removeNext = false;
				
				continue;
			}
			
			if(preg_match('~^(--?)?([^=]+)=(.*)$~s', $arg, $match) === 1)
			{
				$args[$key] = preg_match(self::REDACT_PATTERN, $match[2]) === 1
					? $match[1] . $match[2] . '=[removed]'
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
	protected static function maskName(
		string $value,
	): string
	{
		if($value === '')
		{
			return $value;
		}
		
		return mb_substr($value, 0, 1) . '***';
	}
}
