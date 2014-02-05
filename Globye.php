<?php
/**
 * Finds duplicate, undeclared or unused global variables.
 *
 * @package tools
 * @copyright 2013 René-Gilles Deberdt, wedge.org
 * @license MIT
 */

/*
	To-do:
		- Correctly handle globals declared in anonymous functions (PHP 5.3+) and create_function evals.
		- Remove quotes from tests, so we don't find false positives in commented-out code.
*/

$script_name = basename(__FILE__);
$root = dirname(__FILE__);
$problems = 0;
set_time_limit(300);
@ini_set('xdebug.max_nesting_level', 300);

// Folders that we don't want to waste time on.
$ignored_folders = array(
	'.git',
	'gz',
	'cache',
);

// Known globals that we don't want to understimate.
$known_globals = array(
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
);

echo '<!DOCTYPE html>
<html>
<head>
	<title>Globye.php</title>
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

<h1>Globye.php</h1>
<div>By Nao (Wedge.org)</div>
<hr>

<p>
	This script will list all PHP files in the <samp>', $root, '</samp> folder that have duplicate, unneeded global declarations or undeclared globals.
<p>
<p>
	Add <kbd><a href="', $script_name, '?noclean">?noclean</a></kbd> to view a quick, dirty list of results that may generate more false positives.
	<br>
	Add <kbd><a href="', $script_name, '?ignorefp">?ignorefp</a></kbd> to list only strong suspects; potential false positives will be ignored.
</p>

<ol>';

// We're just going to provide a list of global variables commonly used in Wedge. Feel free to edit to your taste...
find_global_problems($known_globals, $ignored_folders);

echo '</ol>';

if (!$problems)
	echo '<p>No problems found, congratulations!</p>';
else
	echo '<p>', $problems, ' problems found, including potential false positives.</p>';

echo '</body>
</html>';

function find_global_problems($real_globals = array(), $ignored_folders = array(), $dir = '')
{
	global $root, $problems;

	$old_file = '';
	$dir = $dir ? $dir : $root;
	$files = scandir($dir);

	foreach ($files as $file)
	{
		if ($file == '.' || $file == '..' || in_array($file, $ignored_folders))
			continue;
		if (is_dir($dir . '/' . $file))
		{
			find_global_problems($real_globals, $ignored_folders, $dir . '/' . $file);
			continue;
		}
		if (substr($file, -4) != '.php')
			continue;

		$php = $real_php = file_get_contents($dir . '/' . $file);

		// Remove comments, quotes and double quotes from the string.
		// This makes the script about 5x to 10x slower, so if you just need a quick check, add the ?noclean parameter.
		// A few alternative ways to remove these through regexes, but they won't work together:
		// http://ideone.com/rLP1nq
		// http://blog.stevenlevithan.com/archives/match-quoted-string
		if (!isset($_GET['noclean']))
			$php = clean_me_up($php);

		// Detect functions and class methods.
		$matches = get_function_list($php);

		foreach ($matches as $match)
		{
			preg_match_all('~(\$[a-zA-Z_][a-zA-Z0-9_]*)~', $match[2], $params);
			$params = isset($params[1]) ? $params[1] : array();
			preg_match_all('~\sglobal (\$[^;]+);~', $match['clean'], $globs);
			$globs[1] = isset($globs[1]) ? $globs[1] : array();

			foreach ($globs[1] as $find_dupes)
			{
				$dupes = array_map('trim', explode(',', $find_dupes));
				if (count($dupes) > count(array_flip(array_flip($dupes))))
				{
					$problems++;
					$is_new = $file != $old_file;
					$old_file = $file;
					echo '<li class="duplicates', $is_new ? ' new' : '', '">Found duplicate globals in <span>', str_replace($root, '', $dir), '/<span class="file">', $file, '</span></span> (', $match[1] ?: 'anonymous function', ') -- ', $find_dupes, '</li>';
				}
			}

			// Get the final list of all declared globals in that particular function.
			preg_match_all('~\$[a-zA-Z_]+~', implode(', ', $globs[1]), $there_we_are);
			$val = str_replace($globs[0], '', $match['clean']);
			if (isset($there_we_are[0]))
			{
				foreach ($there_we_are[0] as $test_me)
				{
					if (!preg_match('~\\' . $test_me . '[^a-zA-Z_]~', $val))
					{
						$ex = $match['clean'];
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
							$func_line = substr_count(substr($php, 0, $match['pos']), "\n");
							$glob_line = substr_count(substr($match['pristine'], 0, strpos($match['pristine'], $test_me)), "\n");
							echo '<li class="unused', $is_new ? ' new' : '', '">Unused global in <span>', str_replace($root, '', $dir), '/<span class="file">', $file, ':', $func_line + $glob_line + 3, '</span></span> (', $match[1] ?: 'anonymous function', ') -- <span>', $test_me, '</span>';
							if ($probably_false_positive)
							{
								echo '<br><em>The line above might be a false positive (<span>', $r, '</span>).</em></li>';
								continue;
							}
							echo '</li>';
						}
					}
				}
			}
			// Find undeclared globals. There might be false positives in this, if you're using a generic global's name as a local variable (e.g. $options, etc.)
			foreach ($real_globals as $real_global)
			{
				if (!in_array($real_global, $params) && (!isset($there_we_are[0]) || !in_array($real_global, $there_we_are[0])) && preg_match('~\\' . $real_global . '[^a-zA-Z_]~', $val))
				{
					// Is it on a line with a comment on it? Probably a false positive... (Unless you're using another instance, but you'll still have to fix manually.)
					$in_foreach = preg_match('~foreach[\s(].*?\bas\b\s*(?:[^)=>]+=>\s*)?\\' . $real_global . '[^a-zA-Z_]~', $val);
					$in_list = preg_match('~\slist\s*\((?:[^)]+[,\s])?\s*\\' . $real_global . '[^a-zA-Z_]~', $val);
					$in_assign = preg_match('~\\' . $real_global . '\s*=[^=]~', $val);
					$in_arrass = preg_match('~\\' . $real_global . '\[[^]]*]\s*=[^=]~', $val);
					$probably_false_positive = $in_foreach || $in_list || $in_assign || $in_arrass;
					$problems++;

					if (!$probably_false_positive || !isset($_GET['ignorefp']))
					{
						$is_new = $file != $old_file;
						$old_file = $file;
						$func_line = substr_count(substr($php, 0, $match['pos']), "\n");
						$glob_line = substr_count(substr($match['pristine'], 0, strpos($match['pristine'], reset($there_we_are[0]))), "\n");
						echo '<li class="undeclared', $is_new ? ' new' : '', '">Undeclared global in <span>', str_replace($root, '', $dir), '/<span class="file">', $file, ':', $func_line + $glob_line + 3, '</span></span> (', $match[1] ?: 'anonymous function', ') -- <span>', $real_global, '</span>';
						if ($probably_false_positive)
						{
							echo '<br><em>The line above might be a false positive (',
								$in_foreach ? 'found in a foreach' :
								($in_assign ? 'initialized within function' :
								($in_arrass ? 'initialized as implied array within function' :
								($in_list ? 'initialized in a list() within function' : ''))), ').</em></li>';
							continue;
						}
						echo '</li>';
					}
				}
			}
			flush();
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

function get_function_list($php)
{
	$matches = find_functions($php);
	for ($i = 0, $c = count($matches); $i < $c; $i++)
		for ($j = $i - 1; $j >= 0; $j--)
			if (strpos($matches[$i]['clean'], $matches[$j]['pristine']) !== false)
				$matches[$i]['clean'] = str_replace($matches[$j]['pristine'], '', $matches[$i]['clean']);
	return $matches;
}

function find_functions($php, $offset = 0)
{
	$pos = 0;
	$matches = array();

	while (true)
	{
		$next = stripos($php, 'function', $pos);
		// Did we reach the end of the block, or maybe we're in a nested function and we've reached its end?
		if ($next === false || (substr_count($count_brackets = substr($php, $pos, $next - $pos), '{') < substr_count($count_brackets, '}')))
			return $matches;

		if (($next > 0 && preg_match('~[\w$]~', $php[$next - 1])) || !preg_match('~^function(?:[\h\v](\w*)[\h\v]*)?\(([^{}]+){~i', substr($php, $next), $cur))
		{
			$pos = $next + 1;
			continue;
		}
		$bracket = strpos($php, '{', $next);
		$next_bracket = $bracket + 1;

		// Now, find the next function declaration, add it to $matches, and delete it from $php so we don't get confused later.
		$nums = 1;
		while ($nums > 0)
		{
			$opening = strpos($php, '{', $next_bracket);
			$closing = strpos($php, '}', $next_bracket);
			if ($closing !== false && ($opening === false || $closing < $opening))
			{
				$nums--;
				$next_bracket = $closing + 1;
			}
			if ($closing !== false && $opening !== false && $opening < $closing)
			{
				$nums++;
				$next_bracket = $opening + 1;
			}
			if ($closing === false)
				break;
		}

		$item = $cur;
		$item['pos'] = $offset + $bracket;

		// Okay, now we've got our main function, we'll store a pristine version, and a version we'll later gut to remove its nested functions.
		$item['clean'] = substr($php, $bracket, $next_bracket - $bracket);
		$item['pristine'] = substr($php, $next, $next_bracket - $next);

		// Is there a nested function declaration, in here..?
		if (stripos($item['clean'], 'function') !== false)
			$matches = array_merge($matches, find_functions($item['clean'], $bracket));

		$matches[] = $item;
		$pos = $next_bracket;
	}
}
