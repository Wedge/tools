<?php
/**
 * Find duplicate or unused global variables.
 *
 * @package tools
 * @copyright 2013 René-Gilles Deberdt, wedge.org
 * @license MIT
 */

/*
	To-do:
		- Correctly handle globals declared in anonymous functions (PHP 5.3+) and create_function evals.
		- Remove quotes from tests, so we don't find false positives in commented-out code.
		- Fix duplicate globals, instead of just unused globals.
*/

$root = dirname(__FILE__);

echo '<!DOCTYPE html><html><style>
	body { font-family: Arial, sans-serif; color: #666; }
	span { font: 700 17px monospace; }
	.file { color: teal; }
	.new { margin-top: 8px; }
	em { color: #c30; }
</style><body>

<h1>', basename(__FILE__), '</h1>

<p>
	This script will list all PHP files in the <samp>', $root, '</samp> folder that have duplicate or unneeded global declarations.
	Credits: Nao (Wedge.org)
<p>
<p>
	Add <kbd>?fixme</kbd> to the URL to allow the script to fix all files for you, except for potential false-positives.
</p>

<ol>';

find_unused_globals();

echo '</ol></body></html>';

function find_unused_globals($dir = '')
{
	global $root;

	$i = 0;
	$old_file = '';
	$dir = $dir ? $dir : $root;
	$files = scandir($dir);
	foreach ($files as $file)
	{
		if ($file == '.' || $file == '..' || $file == '.git' || $file == 'other')
			continue;
		if (is_dir($dir . '/' . $file))
		{
			find_unused_globals($dir . '/' . $file);
			continue;
		}
		if (substr($file, -4) != '.php')
			continue;

		$php = file_get_contents($dir . '/' . $file);
		preg_match_all('~[\r\n](\t*)function ([\w]+)[^}\r\n]+[\r\n]+\1(.*?)[\r\n]+\1}~s', $php, $matches);
		foreach ($matches[3] as $key => $val)
		{
			preg_match_all('~[\r\n{][\t ]*global (\$[^;]+);~', $val, $globs);
			$globs[1] = isset($globs[1]) ? $globs[1] : array();

			foreach ($globs[1] as $find_dupes)
			{
				$dupes = array_map('trim', explode(',', $find_dupes));
				if (count($dupes) > count(array_flip(array_flip($dupes))))
					echo '<li>Found duplicate globals in ', $file, ':', $matches[2][$key], ' -- ', $find_dupes, '</li>';
			}

			preg_match_all('~\$[a-zA-Z_]+~', implode(', ', $globs[1]), $there_we_are);
			$val = str_replace($globs[0], '', $val);
			if (isset($there_we_are[0]))
			{
				foreach ($there_we_are[0] as $test_me)
				{
					if (!preg_match('~\\' . $test_me . '[^a-zA-Z_]~', $val))
					{
						$is_new = $file != $old_file;
						$old_file = $file;
						$func_line = substr_count(substr($php, 0, strpos($php, $matches[0][$key])), "\n");
						$glob_line = substr_count(substr($matches[3][$key], 0, strpos($matches[3][$key], $test_me)), "\n");
						echo '<li', $is_new ? ' class="new"' : '', '>Unused global in <span>', str_replace($root, '', $dir), '/<span class="file">', $file, ':', $func_line + $glob_line + 3, '</span></span> (', $matches[2][$key], ') -- <span>', $test_me, '</span>';

						$ex = $matches[0][$key];
						if ((($r = '$$') && strpos($ex, '$$') !== false) ||
							(($r = '${$') && strpos($ex, '${$') !== false) ||
							(($r = 'include()') && strpos($ex, 'include(') !== false) ||
							(($r = 'require()') && strpos($ex, 'require(') !== false) ||
							(($r = 'include_once()') && strpos($ex, 'include_once') !== false) ||
							(($r = 'require_once()') && strpos($ex, 'require_once') !== false))
						{
							if (!isset($_GET['fixme']))
								echo '<br><em>The line above might be a false positive (<span>', $r, '</span>); it will be skipped during automatic fixes.</em></li>';
							else
								echo '<br><em>Skipping fix for the line above, as it may be a false positive (<span>', $r, '</span>); please check it yourself manually!</em></li>';
							continue;
						}
						// Add ?fixme to the URL after a dry run. The script will skip anything
						// that looks suspicious, but you should still make a backup before.
						if (isset($_GET['fixme']))
						{
							$matches[0][$key] = preg_replace(
								'~(?<=[\r\n{])[\t ]*global \\' . $test_me . ';[\r\n]+~',
								'',
								preg_replace(
									'~(, ?\\' . $test_me . '(?![a-zA-Z_])|\\' . $test_me . ', ?)~',
									'',
									$matches[0][$key]
								)
							);
							$php = str_replace($ex, $matches[0][$key], $php);
							file_put_contents($dir . '/' . $file, $php);
						}
						echo '</li>';
					}
				}
			}
		}
	}
}
