<?php

/**
 * Simple Machines Forum (SMF) CLI Webinstaller 
 *
 * @author Michael Miller mikemill@gmail.com
 * @copyright 2012 Michael Miller
 * @license http://www.opensource.org/licenses/bsd-license.php BSD
 *
 * @version 1.0
 *
 * Based off of webinstall.php
 * copyright 2011 Simple Machines
 * license http://www.simplemachines.org/about/smf/license.php BSD
 */

 /* This version of webinstall is designed to be ran in the command line.
	Example:
		# php smf_cli_install.php
 */

class Webinstall
{
	static private $minphpversion = '5.0.0';

	private $mirrorsurl = 'http://www.simplemachines.org/smf/mirrors.xml';
	private $settings = null;
	private $txt = array();
	private $dir = '';
	private $package_ext = '.zip';

	public function __construct($dir)
	{
		$this->load_language_strings();
		$this->dir = $dir;
	}

	public function start()
	{
		$this->welcome();
		// Get the available packages from SM
		$package_info = $this->cache_get('packages_info', array($this, 'fetch_package_info'));

		// Determine if we should upgrade or install
		$installorupgrade = $this->cache_get('upgradecheck', array($this, 'upgradecheck')) ? 'upgrade' : 'install';

		// Determine which version we should use
		$package_version = $this->cache_get('packageversion', array($this, 'selectversion'), $package_info['versions']);

		$lang_version = current($package_version);
		$package_version = key($package_version);

		// See if they want to also want to install any languages
		$langs = $this->cache_get('additionallangs', array($this, 'selectlangs'), $package_info['languages'], $lang_version);

		$packages = array();
		
		$packages[] = $package_version . $installorupgrade . $this->package_ext;

		foreach ($langs AS $lang)
			$packages[] = $package_version . $lang . $this->package_ext;

		$this->download_packages($packages, $package_info['mirrors']);

		$this->uncompress($packages);
	}

	public static function boot()
	{
		global $argv;
		// Check the PHP version
		if (!function_exists('version_compare') || version_compare(self::$minphpversion, PHP_VERSION) > 0)
			die(sprintf("The minimum version of PHP to use this tool is %s but the version of PHP on this server is %s.  You must upgrade PHP in order to use this tool.\n", self::$minphpversion, PHP_VERSION));

		if (!class_exists('ZipArchive'))
			die("This script currently requires the ZipArchive class.");

		// Make sure we can write to the current location
		$currentloc = getcwd();

		$ret = file_put_contents($currentloc . '/testwrite.txt', 'Just testing to make sure we can write files to the current location');

		if ($ret === false)
			die("Could not write to the current location.\n");

		if (unlink($currentloc . '/testwrite.txt') === false)
			die("Could not remove the write test file.\n");

		$installer = new Webinstall($currentloc);

		if (in_array('restart', $argv))
			$installer->cache_cleanup();

		try
		{
			$installer->start();
		}
		catch (Exception $e)
		{
			die('Exception: ' . print_r($e, true) . "\n");
		}
	}

	private function welcome()
	{
		echo $this->txt['title'], "\n", $this->txt['copyright'], "\n\n", $this->txt['welcome'], "\n\n", $this->txt['requirements'], "\n\n";
	}

	private function getinput()
	{
		$input = fgets(STDIN);
		$input = rtrim($input, "\n");

		return $input;
	}

	private function promptinput($prompt, $validate = null, $validate_array = null)
	{
		do
		{
			echo $prompt, ': '; flush();
			$input = $this->getinput();

			if ($validate !== null)
			{
				switch ($validate)
				{
					case 'Yn':
						if ($input == '' || strtolower($input) == 'y')
							return 'y';
						elseif (strtolower($input) == 'n')
							return 'n';
						break;

					case 'yN':
						if (strtolower($input) == 'y')
							return 'y';
						elseif ($input == '' || strtolower($input) == 'n')
							return 'n';
						break;

					default:
						return $input;
				}

				echo $this->txt['invalid_input'], "\n";
			}
			else
				return $input;


		} while(true);
	}

	private function fetch_package_info()
	{
		$rawinfo = $this->fetch_web_data($this->mirrorsurl);

		if ($rawinfo === false)
			throw Exception($this->txt['couldnotloadmirrordata']);

		$mirrors = array();
		$packageversions = array();
		$languages = array();
		$langversions = array();

		preg_match_all('~<mirror name="([^"]+)">([^<]+)</mirror>~', $rawinfo, $matches, PREG_SET_ORDER);
		foreach ($matches as $match)
		{
			$mirrors[$match[2]] = $match[1];
		}

		preg_match_all('~<install access="([^"]+)" name="([^"]+)">([^<]+)</install>~', $rawinfo, $matches, PREG_SET_ORDER);
		foreach ($matches as $match)
		{
			$packageversions[$match[3]] = $match[2];
			$langversions[] = str_replace('SMF ', '', $match[2]);
		}

		preg_match_all('~<language name="([^"]+)" versions="([^"]+)">([^<]+)</language>~', $rawinfo, $matches, PREG_SET_ORDER);
		foreach ($matches as $match)
		{
			$versions = explode(', ', $match[2]);
			foreach ($versions as $id => $ver)
				if (!in_array($ver, $langversions))
					unset($versions[$id]);

			if (empty($versions))
				continue;

			$utf8 = substr($match[3], -5) == '-utf8';

			$langname = $match[3];

			$languages[$utf8 ? 'utf8' : 'reg'][$langname] = array(
				'name' => str_replace('-utf8', '', $match[1]),
				'versions' => explode(', ', $match[2]),
			);
		}

		$ret = array(
			'mirrors' => $mirrors,
			'versions' => $packageversions,
			'languages' => $languages,
		);

		// Store it before we return.
		$this->cache_set('packages_info', $ret);

		return $ret;
	}

	private function cache_get($key, $func)
	{
		if (file_exists($this->dir . '/.cache_' . md5($key)) && (($data = file_get_contents($this->dir . '/.cache_' . md5($key))) !== false))
		{
			return unserialize($data);
		}

		$parameters = func_get_args();
		return call_user_func_array($func, array_slice($parameters, 2));
	}

	private function cache_set($key, $data)
	{
		$data = serialize($data);

		file_put_contents($this->dir . '/.cache_' . md5($key), $data);
	}

	private function cache_cleanup()
	{
		$dir = dir($this->dir);

		while ($file = $dir->read())
		{
			if (strpos($file, '.cache_') === 0)
				unlink($this->dir . '/' . $file);
		}
	}

	private function upgradecheck()
	{
		$forumexists = $this->forumexists();

		$upgrade = $forumexists && $this->promptinput($this->txt['doupgrade?'], 'Yn') == 'y';

		$this->cache_set('upgradecheck', $upgrade);

		return $upgrade;
	}

	private function forumexists()
	{
		return file_exists($this->dir . '/Settings.php');
	}

	private function quit()
	{
		exit(-1);
	}

	private function selectversion($versions)
	{
		$version_names = array_values($versions);
		$version_vers = array_keys($versions);

		$valid = false;

		do
		{
			echo $this->txt['select_version'], "\n";

			foreach ($version_names AS $index => $version)
				echo sprintf('%1$d) %2$s', $index+1, $version), "\n";

			echo "x) ", $this->txt['exit'], "\n";

			echo $this->txt['make_selection'], ' ';

			$input = $this->getinput();

			if (is_numeric($input))
			{
				$input = (int) $input;
				$input--;
				if (isset($version_names[$input]))
				{
					$valid = true;
				}
				else
				{
					echo $this->txt['invalid_version'],	"\n\n";
				}
			}
			elseif (strtolower($input) == 'x')
			{
				$this->quit();
			}
			else
				echo $this->txt['invalid_input'],	"\n\n";

		}
		while(!$valid);

		$ret = array($version_vers[$input] => $version_names[$input]);

		$this->cache_set('packageversion', $ret);

		return $ret;
	}

	private function selectlangs($languages, $version)
	{
		$ver = str_replace('SMF ', '', $version);
		// First things first:  Are there any languages available for this version?
		$availlangs = array();

		foreach($languages AS $type => $langs)
		{
			foreach ($langs AS $language => $info)
			{
				if (in_array($ver, $info['versions']))
					$availlangs[$type][$language] = $info;
			}
		}

		if (empty($availlangs))
			$ret = array();
		else
		{
			$offer_utf8 = isset($availlangs['utf8']) && isset($availlangs['reg']);

			$wantlanguages = $this->promptinput($this->txt['install_langs'], 'Yn') == 'y';

			if (!$wantlanguages)
				$ret = array();
			else
			{
				$package = isset($availlangs['reg']) ? 'reg' : 'utf8';
				if ($offer_utf8 && $this->promptinput($this->txt['install_utf8'], 'Yn') == 'y')
				{
					$package = 'utf8';
				}

				$langmapping = array_keys($availlangs[$package]);

				$numcolumns = 3;
				$columns = array();
				$colwidth = 0;


				$numlangs = count($availlangs[$package]);
				$numlangwidth = strlen($numlangs);

				$i = 0;

				foreach($availlangs[$package] AS $lang => $info)
				{
					$columns[$i][] = $info['name'];

					$colwidth = max($colwidth, strlen($info['name']));

					if (++$i == $numcolumns)
						$i = 0;
				}

				$numrows = 0;
				foreach ($columns AS $column)
					$numrows = max($numrows, count($column));

				$format = '%1$' . $numlangwidth . 'd: %2$' . $colwidth . 's';

				$valid = false;
				do
				{
					for ($row = 0, $count=0; $row < $numrows; $row++)
					{
						for ($col = 0; $col < $numcolumns; $col++, $count++)
						{
							if (!isset($columns[$col][$row]))
								continue;

							printf($format, $count, $columns[$col][$row]);

							if ($col != $numcolumns - 1)
								echo "\t";
						}
						echo "\n";
					}

					$input = $this->promptinput($this->txt['select_langs']);
					$selectedlangs = array();
					$promptlangs = array();

					if (!empty($input))
					{
						$input = explode(' ', $input);

						foreach ($input AS $in)
						{
							if (!is_numeric($in) || !isset($langmapping[$in]))
								continue;

							$selectedlangs[] = $langmapping[$in];
						}

						foreach ($selectedlangs AS $lang)
							$promptlangs[] = $availlangs[$package][$lang]['name'];
					}

					if (empty($promptlangs))
						$promptlangs = $this->txt['nolangselected'];
					else
						$promptlangs = implode(', ', $promptlangs);

					$valid = $this->promptinput(sprintf($this->txt['confirmlangs'], $promptlangs), 'Yn') == 'y';
				} while (!$valid);

				$this->cache_set('additionallangs', $selectedlangs);

				return $selectedlangs;
			}
		}
	}

	private function download_packages($packages, $mirrors)
	{

		$mirror_urls = array_keys($mirrors);

		// If there are more than 1 mirror then shuffle it up so we aren't always hitting the first mirror
		if (count($mirror_urls) > 1)
			shuffle($mirror_urls);

		foreach($packages AS $file)
		{
			$success = false;

			if (file_exists($file))
			{
				//if ($this->promptinput("\t" . sprintf($this->txt['fileexists'], $file), 'yN') == 'n')
					continue;
			}

			echo sprintf($this->txt['getting_package'], $file), "\n";
			foreach ($mirror_urls AS $url)
			{
				echo "\t", sprintf($this->txt['trying_mirror'], $mirrors[$url]);
				$data = $this->fetch_web_data($url . $file);

				if ($data !== false)
				{
					$success = true;
					file_put_contents($file, $data);
					echo $this->txt['success'], "\n";
					break;
				}
				else
					echo $this->txt['failure'], "\n";
			}

			if (!$success)
				echo sprintf($this->txt['failed_to_get_package'], $file), "\n";
		}
	}

	private function uncompress($packages)
	{
		$zip = new ZipArchive();
		foreach ($packages AS $package)
		{
			if (!file_exists($package))
				continue;

			echo sprintf($this->txt['uncompressing'], $package), "\n";

			$zip->open($package);

			if ($zip->status != 0)
			{
				echo "\t", sprintf($this->txt['uncompress_error'], $zip->getStatusString()), "\n";
				continue;
			}

			$zip->extractTo('.');
		}
	}

	private function fetch_web_data($url)
	{
		return file_get_contents($url);
	}

	private function load_language_strings()
	{
		$this->txt += array(
			'title' => 'Command line installer for SMF',
			'copyright' => '(c) 2012 Michael Miller.  Open Source under the BSD License',
			'welcome' => '',
			'requirements' => 'Please be aware that the requirements for this installer differ from the requirements of the SMF software',
			'doupgrade?' => 'Detected an existing forum.  Would you like to upgrade the forum? [Yn]',
			'select_version' => 'Please select the version of SMF you would like to use:',
			'exit' => 'Exit',
			'couldnotloadmirrordata' => 'Could not load the mirror information from the SM website.',
			'make_selection' => 'Your selection:',
			'invalid_input' => 'Invalid input.',
			'invalid_version' => 'Invalid version selection.',
			'install_langs' => 'Would you like to also download additional language packs? [Yn]',
			'install_utf8' => 'Would you like to download the UTF8 language packs? [Yn]',
			'select_langs' => 'Please enter a space separated list of the language numbers you want',
			'nolangselected' => '(None)',
			'confirmlangs' => "You have selected the following languages: %1\$s\nIs this correct? [Yn]",
			'getting_package' => 'Attempting to download %1$s',
			'trying_mirror' => 'Trying mirror %1$s: ',
			'success' => 'Success',
			'failure' => 'Failed',
			'failed_to_get_package' => 'Unable to download the package %1$s',
			'fileexists' => 'The %1$s package already exists.  Download anyways? [yN]',
			'uncompressing' => 'Extracting contents from %1$s',
			'uncompress_error' => 'An error occured while extracting: %1$s',
		);
	}
}

Webinstall::boot();