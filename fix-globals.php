<?php
/**
 * Finds duplicate, undeclared or unused global variables.
 * Fixes unused globals when using the ?fixme query string.
 *
 * @package tools
 * @copyright 2013 René-Gilles Deberdt, wedge.org
 * @license MIT
 */

/*
	To-do:
		- Correctly handle globals declared in anonymous functions (PHP 5.3+) and create_function evals.
		- Remove quotes from tests, so we don't find false positives in commented-out code.
		- Fix duplicate and undeclared globals, instead of just unused globals.
*/

$root = dirname(__FILE__);

echo '<!DOCTYPE html><html><style>
	body { font-family: Arial, sans-serif; color: #666; }
	span { font: 700 17px monospace; }
	.file { color: teal; }
	.new { margin-top: 8px; }
	em { color: #c30; }
	li { padding: 3px 0 3px 8px; }

	.duplicates { background: linear-gradient(to right, #fcc, #fff 30px); }
	.undeclared { background: linear-gradient(to right, #ccf, #fff 30px); }
	.unused     { background: linear-gradient(to right, #bea, #fff 30px); }
</style><body>

<h1>', basename(__FILE__), '</h1>

<p>
	This script will list all PHP files in the <samp>', $root, '</samp> folder that have duplicate, unneeded global declarations or undeclared globals.
	Credits: Nao (Wedge.org)
<p>
<p>
	Add <kbd>?fixme</kbd> to the URL to allow the script to fix all files for you, except for potential false-positives and duplicate or undeclared globals.
</p>

<ol>';

// We're just going to provide a list of global variables commonly used in Wedge. Feel free to edit to your taste...
find_global_problems(array(
	'$txt',
	'$context',
	'$settings',
	'$options',
	'$board',
	'$topic',
	'$boarddir',
	'$boardurl',
	'$scripturl',
	'$board_info',
	'$action_list',
));

echo '</ol></body></html>';

function find_global_problems($real_globals = array(), $dir = '')
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
			find_global_problems($real_globals, $dir . '/' . $file);
			continue;
		}
		if (substr($file, -4) != '.php')
			continue;

		$php = file_get_contents($dir . '/' . $file);
		preg_match_all('~[\r\n](\t*)(?:private|protected|public|static| )*function ([\w]+)[^}\r\n]+[\r\n]+\1(.*?)[\r\n]+\1}~s', $php, $matches);
		foreach ($matches[3] as $key => $val)
		{
			preg_match_all('~[\r\n{][\t ]*global (\$[^;]+);~', $val, $globs);
			$globs[1] = isset($globs[1]) ? $globs[1] : array();

			foreach ($globs[1] as $find_dupes)
			{
				$dupes = array_map('trim', explode(',', $find_dupes));
				if (count($dupes) > count(array_flip(array_flip($dupes))))
				{
					$is_new = $file != $old_file;
					$old_file = $file;
					echo '<li class="duplicates', $is_new ? ' new' : '', '">Found duplicate globals in ', $file, ':', $matches[2][$key], ' -- ', $find_dupes, '</li>';
				}
			}

			// Get the final list of all declared globals in that particular function.
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
						echo '<li class="unused', $is_new ? ' new' : '', '">Unused global in <span>', str_replace($root, '', $dir), '/<span class="file">', $file, ':', $func_line + $glob_line + 3, '</span></span> (', $matches[2][$key], ') -- <span>', $test_me, '</span>';

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
			// Find undeclared globals. There might be false positives in this, if you're using a global's name as a local variable (e.g. $options, etc.)
			foreach ($real_globals as $real_global)
			{
				if ((!isset($there_we_are[0]) || !in_array($real_global, $there_we_are[0])) && preg_match('~\\' . $real_global . '[^a-zA-Z_]~', $val))
				{
					// Is it on a line with a comment on it? Probably a false positive... (Unless you're using another instance, but you'll still have to fix manually.)
					$probably_false_positive = preg_match('~(?:^|[;\r\n}])[\t ]*//[^\r\n]*\\' . $real_global . '[^a-zA-Z_]~', $val, $truc);
					$probably_false_positive |= preg_match('~(?:^|[;\r\n}])[\t ]*/\*([^*]|\*[^/])*\\' . $real_global . '[^a-zA-Z_]~', $val, $truc);
					$is_new = $file != $old_file;
					$old_file = $file;
					$func_line = substr_count(substr($php, 0, strpos($php, $matches[0][$key])), "\n");
					$glob_line = substr_count(substr($matches[3][$key], 0, strpos($matches[3][$key], $test_me)), "\n");
					echo '<li class="undeclared', $is_new ? ' new' : '', '">Undeclared global in <span>', str_replace($root, '', $dir), '/<span class="file">', $file, ':', $func_line + $glob_line + 3, '</span></span> (', $matches[2][$key], ') -- <span>', $real_global, '</span>';
					if ($probably_false_positive)
					{
						echo '<br><em>The line above might be a false positive (found in a comment).</em></li>';
						continue;
					}
					echo '</li>';
				}
			}
		}
	}
}
