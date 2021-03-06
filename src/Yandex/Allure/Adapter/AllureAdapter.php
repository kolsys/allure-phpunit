<?php

namespace Yandex\Allure\Adapter;

use Exception;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestListener;
use PHPUnit\Framework\TestSuite;
use PHPUnit\Framework\DataProviderTestSuite;
use PHPUnit\Framework\Warning;
use Yandex\Allure\Adapter\Annotation;
use Yandex\Allure\Adapter\Event\TestCaseBrokenEvent;
use Yandex\Allure\Adapter\Event\TestCaseCanceledEvent;
use Yandex\Allure\Adapter\Event\TestCaseFailedEvent;
use Yandex\Allure\Adapter\Event\TestCaseFinishedEvent;
use Yandex\Allure\Adapter\Event\TestCasePendingEvent;
use Yandex\Allure\Adapter\Event\TestCaseStartedEvent;
use Yandex\Allure\Adapter\Event\TestSuiteFinishedEvent;
use Yandex\Allure\Adapter\Event\TestSuiteStartedEvent;
use Yandex\Allure\Adapter\Model;

class AllureAdapter implements TestListener
{

    //NOTE: here we implicitly assume that PHPUnit runs in single-threaded mode
    private $uuid;
    private $suiteName;
    private $methodName;

    /**
     * Annotations that should be ignored by the annotations parser (especially PHPUnit annotations)
     * @var array
     */
    private $ignoredAnnotations = [
        'after', 'afterClass', 'backupGlobals', 'backupStaticAttributes', 'before', 'beforeClass',
        'codeCoverageIgnore', 'codeCoverageIgnoreStart', 'codeCoverageIgnoreEnd', 'covers',
        'coversDefaultClass', 'coversNothing', 'dataProvider', 'depends', 'expectedException',
        'expectedExceptionCode', 'expectedExceptionMessage', 'group', 'large', 'medium',
        'preserveGlobalState', 'requires', 'runTestsInSeparateProcesses', 'runInSeparateProcess',
        'small', 'test', 'testdox', 'ticket', 'uses',
    ];

    /**
     * @param string $outputDirectory XML files output directory
     * @param bool $deletePreviousResults Whether to delete previous results on return
     * @param array $ignoredAnnotations Extra annotations to ignore in addition to standard PHPUnit annotations
     */
    public function __construct(
        $outputDirectory,
        $deletePreviousResults = false,
        array $ignoredAnnotations = []
    ) {
        if (!isset($outputDirectory)){
            $outputDirectory = 'build' . DIRECTORY_SEPARATOR . 'allure-results';
        }

        $this->prepareOutputDirectory($outputDirectory, $deletePreviousResults);

        // Add standard PHPUnit annotations
        Annotation\AnnotationProvider::addIgnoredAnnotations($this->ignoredAnnotations);
        // Add custom ignored annotations
        Annotation\AnnotationProvider::addIgnoredAnnotations($ignoredAnnotations);
    }

    public function prepareOutputDirectory($outputDirectory, $deletePreviousResults)
    {
        if (!file_exists($outputDirectory)) {
            mkdir($outputDirectory, 0755, true);
        }
        if ($deletePreviousResults) {
            $files = glob($outputDirectory . DIRECTORY_SEPARATOR . '{,.}*', GLOB_BRACE);
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        if (is_null(Model\Provider::getOutputDirectory())) {
            Model\Provider::setOutputDirectory($outputDirectory);
        }
    }

    protected function getAnnotationManager(array $annotations)
    {
        return new Annotation\AnnotationManager($annotations);
    }

    protected function updateEventInfo($event, Exception $e, $message = null)
    {
        $event->withException($e);
        if ($message !== null) {
            $event->withMessage($message);
        }
    }

    /**
     * An error occurred.
     *
     * @param Test $test
     * @param Exception $e
     * @param float $time
     */
    public function addError(Test $test, Exception $e, $time)
    {
        $event = new TestCaseBrokenEvent();
        $this->updateEventInfo($event, $e, $e->getMessage());
        Allure::lifecycle()->fire($event);
    }

    /**
     * A warning occurred.
     *
     * @param \PHPUnit\Framework\Test $test
     * @param \PHPUnit\Framework\Warning $e
     * @param float $time
     */
    public function addWarning(Test $test, Warning $e, $time)
    {
        // TODO: Implement addWarning() method.
    }

    /**
     * A failure occurred.
     *
     * @param Test $test
     * @param AssertionFailedError $e
     * @param float $time
     */
    public function addFailure(Test $test, AssertionFailedError $e, $time)
    {
        $event = new TestCaseFailedEvent();

        $message = $e->getMessage();

        // Append comparison diff for errors of type ExpectationFailedException (and is subclasses)
        if (($e instanceof ExpectationFailedException
            || is_subclass_of($e, 'PHPUnit\Framework\ExpectationFailedException'))
            && $e->getComparisonFailure()
        ) {
            $message .= $e->getComparisonFailure()->getDiff();
        }
        $this->updateEventInfo($event, $e, $message);
        Allure::lifecycle()->fire($event);
    }

    /**
     * Incomplete test.
     *
     * @param Test $test
     * @param Exception $e
     * @param float $time
     */
    public function addIncompleteTest(Test $test, Exception $e, $time)
    {
        $event = new TestCasePendingEvent();
        $this->updateEventInfo($event, $e);
        Allure::lifecycle()->fire($event);
    }

    /**
     * Risky test.
     *
     * @param Test $test
     * @param Exception $e
     * @param float $time
     * @since  Method available since Release 4.0.0
     */
    public function addRiskyTest(Test $test, Exception $e, $time)
    {
        $this->addIncompleteTest($test, $e, $time);
    }

    /**
     * Skipped test.
     *
     * @param Test $test
     * @param Exception $e
     * @param float $time
     * @since  Method available since Release 3.0.0
     */
    public function addSkippedTest(Test $test, Exception $e, $time)
    {
        $shouldCreateStartStopEvents = false;
        if ($test instanceof TestCase){
            $methodName = $test->getName();
            if ($methodName !== $this->methodName){
                $shouldCreateStartStopEvents = true;
                $this->startTest($test);
            }
        }

        $event = new TestCaseCanceledEvent();
        $this->updateEventInfo($event, $e, $e->getMessage());
        Allure::lifecycle()->fire($event);

        if ($shouldCreateStartStopEvents && $test instanceof TestCase){
            $this->endTest($test, 0);
        }
    }

    /**
     * A test suite started.
     *
     * @param TestSuite $suite
     * @since  Method available since Release 2.2.0
     */
    public function startTestSuite(TestSuite $suite)
    {
        if ($suite instanceof DataProviderTestSuite) {
            return;
        }

        $suiteName = $suite->getName();
        $event = new TestSuiteStartedEvent($suiteName);
        $this->uuid = $event->getUuid();
        $this->suiteName = $suiteName;

        if (class_exists($suiteName, false)) {
            $this->getAnnotationManager(
                Annotation\AnnotationProvider::getClassAnnotations($suiteName)
            )->updateTestSuiteEvent($event);
        }

        Allure::lifecycle()->fire($event);
    }

    /**
     * A test suite ended.
     *
     * @param TestSuite $suite
     * @since  Method available since Release 2.2.0
     */
    public function endTestSuite(TestSuite $suite)
    {
        if ($suite instanceof DataProviderTestSuite) {
            return;
        }

        Allure::lifecycle()->fire(new TestSuiteFinishedEvent($this->uuid));
    }

    /**
     * A test started.
     *
     * @param Test $test
     */
    public function startTest(Test $test)
    {
        if ($test instanceof TestCase) {
            $testName = $test->getName();
            $methodName = $this->methodName = $test->getName(false);

            $event = new TestCaseStartedEvent($this->uuid, $testName);
            if (method_exists($test, $methodName)) {
                $this->getAnnotationManager(
                    Annotation\AnnotationProvider::getMethodAnnotations(get_class($test), $methodName)
                )->updateTestCaseEvent($event);
            }
            Allure::lifecycle()->fire($event);
        }
    }

    /**
     * A test ended.
     *
     * @param Test $test
     * @param float $time
     * @throws \Exception
     */
    public function endTest(Test $test, $time)
    {
        if ($test instanceof TestCase) {
            Allure::lifecycle()->fire(new TestCaseFinishedEvent());
        }
    }
}
