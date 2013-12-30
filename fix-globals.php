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

$script_name = basename(__FILE__);
$root = dirname(__FILE__);
$problems = 0;
set_time_limit(300);
@ini_set('xdebug.max_nesting_level', 300);

echo '<!DOCTYPE html>
<html>
<head>
	<title>', $script_name, '</title>
	<style>
		body { font-family: Arial, sans-serif; color: #666; }
		span { font: 700 17px monospace; }
		.file { color: teal; }
		.new { margin-top: 8px; }
		em { color: #c30; }
		li { padding: 3px 0 3px 8px; }

		.duplicates { background: linear-gradient(to right, #fcc, #fff 30px); }
		.undeclared { background: linear-gradient(to right, #ccf, #fff 30px); }
		.unused     { background: linear-gradient(to right, #bea, #fff 30px); }
	</style>
</head>
<body>

<h1>', $script_name, '</h1>
<div>By Nao (Wedge.org)</div>
<hr>

<p>
	This script will list all PHP files in the <samp>', $root, '</samp> folder that have duplicate, unneeded global declarations or undeclared globals.
<p>
<p>
	Add <kbd><a href="', $script_name, '?noclean">?noclean</a></kbd> to view a quick, dirty list of results that may generate more false positives.
	<br>
	Add <kbd><a href="', $script_name, '?ignorefp">?ignorefp</a></kbd> to list only strong suspects; potential false positives will be ignored.
	<br>
	Add <kbd>?fixme</kbd> to the URL to allow the script to fix all files for you, except for potential false-positives and duplicate or undeclared globals. Still, beware!
</p>

<ol>';

// We're just going to provide a list of global variables commonly used in Wedge. Feel free to edit to your taste...
find_global_problems(array(
	'$txt',
	'$context',
	'$settings',
	'$modSettings', // for SMF (forks or mainline)
	'$theme', // for SMF (forks or mainline)
	'$options',
	'$board',
	'$topic',
	'$boarddir',
	'$boardurl',
	'$scripturl',
	'$board_info',
	'$action_list',
));

echo '</ol>';

if (!$problems)
	echo '<p>No problems found, congratulations!</p>';
else
	echo '<p>', $problems, ' problems found, including potential false positives.</p>';

echo '</body>
</html>';

function find_global_problems($real_globals = array(), $dir = '')
{
	global $root, $problems;

	$old_file = '';
	$dir = $dir ? $dir : $root;
	$files = scandir($dir);

	foreach ($files as $file)
	{
		if ($file == '.' || $file == '..' || $file == '.git' || $file == 'other' || $file == 'cache')
			continue;
		if (is_dir($dir . '/' . $file))
		{
			find_global_problems($real_globals, $dir . '/' . $file);
			continue;
		}
		if (substr($file, -4) != '.php')
			continue;

		$php = $real_php = file_get_contents($dir . '/' . $file);

		// Remove comments, quotes and double quotes from the string.
		// This makes the script about 5x to 10x slower, so if you just need a quick check, add the ?noclean parameter.
		// A few alternative ways to remove these through regex, but they won't work together:
		// http://ideone.com/rLP1nq
		// http://blog.stevenlevithan.com/archives/match-quoted-string
		if (!isset($_GET['noclean']) || isset($_GET['fixme']))
			$php = clean_me_up($php);

		// Detect named functions and class methods.
		// !! @todo: detect anonymous functions.
		preg_match_all('~[\r\n](\t*)(?:private|protected|public|static| )*?function (\w+)([^}\r\n]+[\r\n]+\1.*?)[\r\n]+\1}~s', $php, $matches);

		// !! @todo: test this!
		if (isset($_GET['fixme']))
			preg_match_all('~[\r\n](\t*)(?:private|protected|public|static| )*?function (\w+)([^}\r\n]+[\r\n]+\1.*?)[\r\n]+\1}~s', $real_php, $real_matches);

		foreach ($matches[3] as $key => $val)
		{
			preg_match_all('~[\r\n{][\t ]*global (\$[^;]+);~', $val, $globs);
			$globs[1] = isset($globs[1]) ? $globs[1] : array();

			foreach ($globs[1] as $find_dupes)
			{
				$dupes = array_map('trim', explode(',', $find_dupes));
				if (count($dupes) > count(array_flip(array_flip($dupes))))
				{
					$problems++;
					$is_new = $file != $old_file;
					$old_file = $file;
					echo '<li class="duplicates', $is_new ? ' new' : '', '">Found duplicate globals in <span>', str_replace($root, '', $dir), '/<span class="file">', $file, '</span></span> (', $matches[2][$key], ') -- ', $find_dupes, '</li>';
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
						$ex = $matches[0][$key];
						$problems++;
						$probably_false_positive =
							((($r = '$$') && strpos($ex, '$$') !== false) ||
							(($r = '${$') && strpos($ex, '${$') !== false) ||
							(($r = 'include()') && strpos($ex, 'include(') !== false) ||
							(($r = 'require()') && strpos($ex, 'require(') !== false) ||
							(($r = 'include_once()') && strpos($ex, 'include_once') !== false) ||
							(($r = 'require_once()') && strpos($ex, 'require_once') !== false));

						if (!$probably_false_positive || !isset($_GET['ignorefp']))
						{
							$is_new = $file != $old_file;
							$old_file = $file;
							$func_line = substr_count(substr($php, 0, strpos($php, $matches[0][$key])), "\n");
							$glob_line = substr_count(substr($matches[3][$key], 0, strpos($matches[3][$key], $test_me)), "\n");
							echo '<li class="unused', $is_new ? ' new' : '', '">Unused global in <span>', str_replace($root, '', $dir), '/<span class="file">', $file, ':', $func_line + $glob_line + 3, '</span></span> (', $matches[2][$key], ') -- <span>', $test_me, '</span>';
							if ($probably_false_positive)
							{
								if (!isset($_GET['fixme']))
									echo '<br><em>The line above might be a false positive (<span>', $r, '</span>); it will be skipped during automatic fixes.</em></li>';
								else
									echo '<br><em>Skipping fix for the line above, as it may be a false positive (<span>', $r, '</span>); please check it yourself manually!</em></li>';
								continue;
							}
							echo '</li>';
						}

						// Add ?fixme to the URL after a dry run. The script will skip anything
						// that looks suspicious, but you should still make a backup before.
						if (isset($_GET['fixme']))
						{
							$real_ex = $real_matches[0][$key];
							$real_matches[0][$key] = preg_replace(
								'~(?<=[\r\n{])[\t ]*global \\' . $test_me . ';[\r\n]+~',
								'',
								preg_replace(
									'~(, ?\\' . $test_me . '(?![a-zA-Z_])|\\' . $test_me . ', ?)~',
									'',
									$real_matches[0][$key]
								)
							);
							$real_php = str_replace($real_ex, $real_matches[0][$key], $real_php);
							file_put_contents($dir . '/' . $file, $real_php);
						}
					}
				}
			}
			// Find undeclared globals. There might be false positives in this, if you're using a global's name as a local variable (e.g. $options, etc.)
			foreach ($real_globals as $real_global)
			{
				if ((!isset($there_we_are[0]) || !in_array($real_global, $there_we_are[0])) && preg_match('~\\' . $real_global . '[^a-zA-Z_]~', $val))
				{
					// Is it on a line with a comment on it? Probably a false positive... (Unless you're using another instance, but you'll still have to fix manually.)
					$in_foreach = preg_match('~foreach[\s(].*?\bas\b\s*(?:[^)=>]+=>\s*)?\\' . $real_global . '[^a-zA-Z_]~', $val);
					$in_params = preg_match('~^\s*\((?:[^)]+[,\s])?\s*\\' . $real_global . '[^a-zA-Z_]~', $val);
					$in_list = preg_match('~\slist\s*\((?:[^)]+[,\s])?\s*\\' . $real_global . '[^a-zA-Z_]~', $val);
					$in_assign = preg_match('~\\' . $real_global . '\s*=[^=]~', $val);
					$in_arrass = preg_match('~\\' . $real_global . '\[[^]]*]\s*=[^=]~', $val);
					$probably_false_positive = $in_foreach || $in_params || $in_list || $in_assign || $in_arrass;
					$problems++;

					if (!$probably_false_positive || !isset($_GET['ignorefp']))
					{
						$is_new = $file != $old_file;
						$old_file = $file;
						$func_line = substr_count(substr($php, 0, strpos($php, $matches[0][$key])), "\n");
						$glob_line = substr_count(substr($matches[3][$key], 0, strpos($matches[3][$key], reset($there_we_are[0]))), "\n");
						echo '<li class="undeclared', $is_new ? ' new' : '', '">Undeclared global in <span>', str_replace($root, '', $dir), '/<span class="file">', $file, ':', $func_line + $glob_line + 3, '</span></span> (', $matches[2][$key], ') -- <span>', $real_global, '</span>';
						if ($probably_false_positive)
						{
							echo '<br><em>The line above might be a false positive (',
								$in_params ? 'used as a parameter' :
								($in_assign ? 'initialized within function' :
								($in_arrass ? 'initialized as implied array within function' :
								($in_list ? 'initialized in a list() within function' :
								'found in a ' . ($in_comment ? 'comment' : 'foreach')))), ').</em></li>';
							continue;
						}
						echo '</li>';
					}
				}
			}
		}
	}
}

// This is a very, very slow function. But it's needed for best results.
function clean_me_up($php)
{
	// List of characters we'll be looking for.
	$search_for = array('/*', '//', "'", '"');
	$pos = 0;

	while (true)
	{
		$next = find_next($php, $pos, $search_for);
		if ($next === false)
			return $php;

		$look_for = $php[$next];
		if ($look_for === '/')
		{
			if ($php[$next + 1] === '/') // Remove //
				$look_for = array("\r", "\n", "\r\n");
			else // Remove /* ... */
				$look_for = '*/';
		}
		// We can't skip double quotes because they might hold parsable variables.
		// So, we'll just find the end of that string, and then skip to the rest.
		elseif ($look_for == '"')
		{
			$next = find_next($php, $next + 1, '"');
			if ($next === false)
				return $php;
			$pos = $next + 1;
			continue;
		}

		$end = find_next($php, $next + 1, $look_for);
		if ($end === false)
		{
			$php = substr($php, 0, $next);
			return $php;
		}

		if (!is_array($look_for))
			$end += strlen($look_for);
		$temp = substr($php, $next, $end - $next);

		$breaks = substr_count($temp, "\n") + substr_count($temp, "\r") - substr_count($temp, "\r\n");
		$php = substr_replace($php, str_pad(str_repeat("\n", $breaks), $end - $next), $next, $end - $next);
		$pos = $end + 1;
	}
}

function find_next(&$php, $pos, $search_for)
{
	if (is_array($search_for))
	{
		$positions = array();
		foreach ((array) $search_for as $item)
		{
			$position = strpos($php, $item, $pos);
			if ($position !== false)
				$positions[] = $position;
		}
		if (empty($positions))
			return false;
		$next = min($positions);
	}
	else
	{
		$next = strpos($php, $search_for, $pos);
		if ($next === false)
			return false;
	}

	$check_before = $next;
	$escaped = false;
	while (--$check_before >= 0 && $php[$check_before] == '\\')
		$escaped = !$escaped;
	if ($escaped)
		return find_next($php, ++$next, $search_for);
	return $next;
}
