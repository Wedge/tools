<?php
/**
 * Find duplicate or unused global variables.
 *
 * @package tools
 * @copyright 2013 René-Gilles Deberdt, wedge.org
 * @license MIT
 */

find_unused_globals(dirname(__FILE__));

function find_unused_globals($dir)
{
	$i = 0;
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
		preg_match_all('~\n(\t*)function ([\w]+)[^}\n]+\n\1(.*?)\n\1}~s', $php, $matches);
		foreach ($matches[3] as $key => $val)
		{
			preg_match_all('~global (\$[^;]+);~', $val, $globs);
			$globs[1] = isset($globs[1]) ? $globs[1] : array();

			foreach ($globs[1] as $find_dupes)
			{
				$dupes = array_map('trim', explode(',', $find_dupes));
				if (count($dupes) > count(array_flip(array_flip($dupes))))
					echo 'Found duplicate globals in ', $file, ':', $matches[2][$key], ' -- ', $find_dupes, "\n";
			}

			preg_match_all('~\$[a-zA-Z_]+~', implode(', ', $globs[1]), $there_we_are);
			$val = str_replace($globs[0], '', $val);
			if (isset($there_we_are[0]))
				foreach ($there_we_are[0] as $test_me)
					if (strpos($val, $test_me) === false)
						echo 'Unused global in ', $file, ':', $matches[2][$key], ' -- ', $test_me, "\n";
		}
	}
}
