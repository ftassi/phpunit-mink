<?php
/**
 * This file is part of the phpunit-mink library.
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @copyright Alexander Obuhovich <aik.bold@gmail.com>
 * @link      https://github.com/aik099/phpunit-mink
 */

namespace aik099\PHPUnit;


use aik099\PHPUnit\BrowserConfiguration\BrowserConfiguration;
use aik099\PHPUnit\BrowserConfiguration\SauceLabsBrowserConfiguration;
use aik099\PHPUnit\Common\RemoteCoverage;
use aik099\PHPUnit\SessionStrategy\ISessionStrategy;
use aik099\PHPUnit\SessionStrategy\SessionStrategyManager;
use Behat\Mink\Exception\DriverException;
use Behat\Mink\Session;

/**
 * Test Case class for writing browser-based tests.
 *
 * @method \Mockery\Expectation shouldReceive
 */
abstract class BrowserTestCase extends \PHPUnit_Framework_TestCase
{

	/**
	 * Browser list to be used in tests.
	 *
	 * @var array
	 */
	public static $browsers = array();

	/**
	 * Remote coverage collection url.
	 *
	 * @var string Override to provide code coverage data from the server
	 */
	protected $coverageScriptUrl;

	/**
	 * Current browser configuration.
	 *
	 * @var BrowserConfiguration
	 */
	private $_browser;

	/**
	 * Reference to Mink session.
	 *
	 * @var Session
	 */
	private $_session;

	/**
	 * Session strategy manager.
	 *
	 * @var SessionStrategyManager
	 */
	protected $sessionStrategyManager;

	/**
	 * Session strategy, used currently.
	 *
	 * @var ISessionStrategy
	 */
	protected $sessionStrategy;

	/**
	 * Test ID.
	 *
	 * @var string
	 */
	private $_testId;

	/**
	 * Sets session strategy manager.
	 *
	 * @param SessionStrategyManager $session_strategy_manager Session strategy manager.
	 *
	 * @return self
	 */
	public function setSessionStrategyManager(SessionStrategyManager $session_strategy_manager)
	{
		$this->sessionStrategyManager = $session_strategy_manager;

		return $this;
	}

	/**
	 * Set session meta-info for "Sauce Labs".
	 *
	 * @return void
	 */
	protected function setUp()
	{
		parent::setUp();

		$this->getBrowser()->testSetUpHook($this);
	}

	/**
	 * Sets browser configuration.
	 *
	 * @param BrowserConfiguration $browser Browser configuration.
	 *
	 * @return self
	 */
	public function setBrowser(BrowserConfiguration $browser)
	{
		$this->_browser = $browser;

		// configure session strategy
		$session_strategy = $browser->getSessionStrategy();
		$session_strategy_hash = $browser->getSessionStrategyHash($this);
		$browser_strategy = $this->sessionStrategyManager->getSessionStrategy($session_strategy, $session_strategy_hash);

		return $this->setSessionStrategy($browser_strategy);
	}

	/**
	 * Returns browser configuration.
	 *
	 * @return BrowserConfiguration
	 * @throws \RuntimeException When browser configuration isn't defined.
	 */
	public function getBrowser()
	{
		if ( !is_object($this->_browser) ) {
			throw new \RuntimeException('Browser configuration not defined');
		}

		return $this->_browser;
	}

	/**
	 * Initializes a browser with given configuration.
	 *
	 * @param array $browser_config Browser configuration.
	 *
	 * @return self
	 */
	public function setBrowserFromConfiguration(array $browser_config)
	{
		$browser_config = BrowserConfiguration::resolveAliases($browser_config, $this->getBrowserAliases());

		// configure browser
		if ( isset($browser_config['sauce']) ) {
			$browser = new SauceLabsBrowserConfiguration($this->getBrowserAliases());
		}
		else {
			$browser = new BrowserConfiguration($this->getBrowserAliases());
		}

		$browser->setup($browser_config);

		return $this->setBrowser($browser);
	}

	/**
	 * Sets session strategy.
	 *
	 * @param ISessionStrategy $session_strategy Session strategy.
	 *
	 * @return self
	 */
	public function setSessionStrategy(ISessionStrategy $session_strategy = null)
	{
		$this->sessionStrategy = $session_strategy;

		return $this;
	}

	/**
	 * Returns session strategy used currently.
	 *
	 * @return ISessionStrategy
	 * @see    setSessionStrategy()
	 */
	public function getSessionStrategy()
	{
		if ( $this->sessionStrategy ) {
			return $this->sessionStrategy;
		}

		// default session strategy (not session itself) shared across all test cases
		return $this->sessionStrategyManager->getDefaultSessionStrategy();
	}

	/**
	 * Creates Mink session using current session strategy and returns it.
	 *
	 * @return Session
	 */
	public function getSession()
	{
		if ( $this->_session && $this->_session->isStarted() ) {
			return $this->_session;
		}

		$browser = $this->getBrowser();

		try {
			$this->_session = $this->getSessionStrategy()->session($browser);

			if ( $this->getCollectCodeCoverageInformation() ) {
				$this->_session->visit($browser->getBaseUrl());
			}
		}
		catch ( DriverException $e ) {
			$message = 'The Selenium Server is not active on host %s at port %s';
			$this->markTestSkipped(sprintf($message, $browser->getHost(), $browser->getPort()));
		}

		return $this->_session;
	}

	/**
	 * Runs the test case and collects the results in a TestResult object.
	 *
	 * If no TestResult object is passed a new one will be created.
	 *
	 * @param \PHPUnit_Framework_TestResult $result Test result.
	 *
	 * @return \PHPUnit_Framework_TestResult
	 * @throws \PHPUnit_Framework_Exception When exception was thrown during a test.
	 */
	public function run(\PHPUnit_Framework_TestResult $result = null)
	{
		if ( $result === null ) {
			$result = $this->createResult();
		}

		parent::run($result);

		if ( $result->getCollectCodeCoverageInformation() ) {
			$result->getCodeCoverage()->append($this->getRemoteCodeCoverageInformation(), $this);
		}

		$this->getBrowser()->testAfterRunHook($this, $result);

		// do not call this before to give the time to the Listeners to run
		$this->getSessionStrategy()->endOfTest($this->_session);

		return $result;
	}

	/**
	 * Whatever or not code coverage information should be gathered.
	 *
	 * @return boolean
	 * @throws \RuntimeException When used before test is started.
	 */
	public function getCollectCodeCoverageInformation()
	{
		$result = $this->getTestResultObject();

		if ( !is_object($result) ) {
			throw new \RuntimeException('Test must be started before attempting to collect coverage information');
		}

		return $result->getCollectCodeCoverageInformation();
	}

	/**
	 * Override to tell remote website, that code coverage information needs to be collected.
	 *
	 * @return mixed
	 * @throws \Exception When exception was thrown inside the test.
	 */
	protected function runTest()
	{
		if ( $this->getCollectCodeCoverageInformation() ) {
			$this->_testId = get_class($this) . '__' . $this->getName();

			$session = $this->getSession();
			$session->setCookie('PHPUNIT_SELENIUM_TEST_ID', null);
			$session->setCookie('PHPUNIT_SELENIUM_TEST_ID', $this->_testId);
		}

		return parent::runTest();
	}

	/**
	 * Called, when last test in a test case has ended.
	 *
	 * @return self
	 */
	public function endOfTestCase()
	{
		$this->getSessionStrategy()->endOfTestCase($this->_session);

		return $this;
	}

	/**
	 * Returns remote code coverage information.
	 *
	 * @return array
	 */
	public function getRemoteCodeCoverageInformation()
	{
		$remote_coverage = new RemoteCoverage($this->coverageScriptUrl, $this->_testId);

		return $remote_coverage->get();
	}

	/**
	 * Creates test suite for usage with Mink.
	 *
	 * @param string $class_name Test case class name.
	 *
	 * @return TestSuite
	 */
	public static function suite($class_name)
	{
		return TestSuite::fromTestCaseClass($class_name);
	}

	/**
	 * This method is called when a test method did not execute successfully.
	 *
	 * @param \Exception $e Exception.
	 *
	 * @return void
	 */
	protected function onNotSuccessfulTest(\Exception $e)
	{
		$this->getSessionStrategy()->notSuccessfulTest($e);

		parent::onNotSuccessfulTest($e);
	}

	/**
	 * Get test id (generated internally).
	 *
	 * @return string
	 */
	public function getTestId()
	{
		return $this->_testId;
	}

	/**
	 * Gets browser configuration aliases.
	 *
	 * Allows to decouple actual test server connection details from test cases.
	 *
	 * @return array
	 */
	public function getBrowserAliases()
	{
		return array();
	}

}
