<?php
/**
 * This file is part of the phpunit-mink library.
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @copyright Alexander Obuhovich <aik.bold@gmail.com>
 * @link      https://github.com/aik099/phpunit-mink
 */

namespace aik099\PHPUnit\Common;


/**
 * Class collects remove code coverage information and maps patch from remote to local server.
 *
 * @method \Mockery\Expectation shouldReceive
 */
class RemoteCoverage
{

	/**
	 * Url to a script, that will provide remote coverage information.
	 *
	 * @var string
	 */
	private $_coverageScriptUrl;

	/**
	 * ID of test, that to look for.
	 *
	 * @var string
	 */
	private $_testId;

	/**
	 * Creates an instance of remote coverage class.
	 *
	 * @param string $coverage_script_url Coverage script irl.
	 * @param string $test_id             Test ID.
	 *
	 * @throws \InvalidArgumentException When empty coverage script url given.
	 */
	public function __construct($coverage_script_url, $test_id)
	{
		if ( empty($coverage_script_url) ) {
			throw new \InvalidArgumentException('Coverage script url is empty');
		}

		$this->_coverageScriptUrl = $coverage_script_url;
		$this->_testId = $test_id;
	}

	/**
	 * Returns raw remote coverage information.
	 *
	 * @return string
	 */
	public function getFetchUrl()
	{
		return sprintf('%s?PHPUNIT_SELENIUM_TEST_ID=%s', $this->_coverageScriptUrl, $this->_testId);
	}

	/**
	 * Retrieves remote coverage information.
	 *
	 * @return array
	 * @throws \Exception When no data was retrieved.
	 */
	public function get()
	{
		$url = $this->getFetchUrl();
		$buffer = file_get_contents($url);

		if ( $buffer !== false ) {
			$coverage_data = unserialize($buffer);

			if ( is_array($coverage_data) ) {
				return $this->matchLocalAndRemotePaths($coverage_data);
			}

			throw new \RuntimeException(sprintf('Empty or invalid code coverage data received from url "%s"', $url));
		}

		return array();
	}

	/**
	 * Returns only files from remote server, that are matching files on test machine.
	 *
	 * @param array $coverage Remote coverage information.
	 *
	 * @return array
	 * @author Mattis Stordalen Flister <mattis@xait.no>
	 */
	protected function matchLocalAndRemotePaths(array $coverage)
	{
		$coverage_with_local_paths = array();

		foreach ($coverage as $original_remote_path => $data) {
			$remote_path = $original_remote_path;
			$separator = $this->findDirectorySeparator($remote_path);

			while ( !($local_path = stream_resolve_include_path($remote_path)) &&
				strpos($remote_path, $separator) !== false ) {
				$remote_path = substr($remote_path, strpos($remote_path, $separator) + 1);
			}

			if ( $local_path && md5_file($local_path) == $data['md5'] ) {
				$coverage_with_local_paths[$local_path] = $data['coverage'];
			}
		}

		return $coverage_with_local_paths;
	}

	/**
	 * Returns path separator in given path.
	 *
	 * @param string $path Path to file.
	 *
	 * @return string
	 * @author Mattis Stordalen Flister <mattis@xait.no>
	 */
	protected function findDirectorySeparator($path)
	{
		if ( strpos($path, '/') !== false ) {
			return '/';
		}

		return '\\';
	}

}
