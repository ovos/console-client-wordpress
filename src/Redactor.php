<?php
declare(strict_types=1);

namespace OvosConsole;

use function is_array;
use function preg_match;

/**
 * Scrubs request variables and extras before they leave the site.
 *
 * Pattern per docs/API.V1.md plus the WordPress login/profile field
 * names; the console redacts again server-side as a backstop.
 */
final class Redactor
{
	protected const PATTERN =
		'/password|passwd|pwd|pass\d|user_pass|token|secret|authorization|cookie|api[-_]key/i';
		
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
			if(preg_match(self::PATTERN, (string)$key) === 1)
			{
				$clean[$key] = '[redacted]';
			}
			elseif(is_array($value))
			{
				$clean[$key] = self::scrub($value, $depth + 1);
			}
			else
			{
				$clean[$key] = $value;
			}
		}
		
		return $clean;
	}
}
