<?php

namespace Lmc\Steward\Publisher;

class XmlPublisher extends AbstractPublisher
{
    /** @var string */
    protected $fileDir;

    /** @var string */
    protected $fileName = 'results.xml';

    /** @var resource */
    protected $fileHandle;

    /**
     * Create the publisher. If LOGS_DIR constant is not defined, remember to call setFileDir() and set full path to
     * the file.
     *
     * @param string $environment
     * @param string $jobName
     * @param int $buildNumber
     */
    public function __construct($environment, $jobName, $buildNumber)
    {
        if (defined('LOGS_DIR')) { // if the constant is not defined, the setFileDir() must be called explicitly later
            $this->setFileDir(LOGS_DIR);
        }
    }

    /**
     * Set directory where results file should be stored. Usable eg. when LOGS_DIR constant was not set.
     * @param string $dir
     */
    public function setFileDir($dir)
    {
        $this->fileDir = $dir;
    }

    /**
     * Change file name from the default. Mostly usable for testing.
     * @param string $fileName
     */
    public function setFileName($fileName)
    {
        $this->fileName = $fileName;
    }

    /**
     * Get full path to results file.
     * @return string
     */
    public function getFilePath()
    {
        return $this->fileDir . DIRECTORY_SEPARATOR . $this->fileName;
    }

    /**
     * Clean the file with all previous results (if exists).
     */
    public function clean()
    {
        if (file_exists($this->getFilePath())) {
            unlink($this->getFilePath());
        }
    }

    /**
     * Publish testcase result
     *
     * @param string $testCaseName
     * @param string $status
     * @param string $result
     * @param \DateTimeInterface $startDate Testcase start datetime
     * @param \DateTimeInterface $endDate Testcase end datetime
     */
    public function publishResults(
        $testCaseName,
        $status,
        $result = null,
        \DateTimeInterface $startDate = null,
        \DateTimeInterface $endDate = null
    ) {
        $xml = $this->readAndLock();

        $testCaseNode = $this->getTestCaseNode($xml, $testCaseName);
        $testCaseNode['status'] = $status;
        if ($result) {
            $testCaseNode['result'] = $result;
        }
        if ($startDate) {
            $testCaseNode['start'] = $startDate->format(\DateTime::ISO8601);
        }
        if ($endDate) {
            $testCaseNode['end'] = $endDate->format(\DateTime::ISO8601);
        }

        $this->writeAndUnlock($xml);
    }

    /**
     * Publish results of one single test
     *
     * @param string $testCaseName
     * @param string $testName
     * @param string $status
     * @param string $result
     * @param string $message
     */
    public function publishResult($testCaseName, $testName, $status, $result = null, $message = null)
    {
        if (!in_array($status, self::$testStatuses)) {
            throw new \InvalidArgumentException(
                sprintf('Tests status must be one of "%s", but "%s" given', join(', ', self::$testStatuses), $status)
            );
        }
        if (!is_null($result) && !in_array($result, self::$testResults)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Tests result must be null or one of "%s", but "%s" given',
                    join(', ', self::$testResults),
                    $result
                )
            );
        }

        $xml = $this->readAndLock();

        $testCaseNode = $this->getTestCaseNode($xml, $testCaseName);
        $testNode = $this->getTestNode($testCaseNode, $testName);

        $testNode['status'] = $status;

        if ($status == self::TEST_STATUS_STARTED) {
            $testNode['start'] = (new \DateTimeImmutable())->format(\DateTime::ISO8601);
        }
        if ($status == self::TEST_STATUS_DONE) {
            $testNode['end'] = (new \DateTimeImmutable())->format(\DateTime::ISO8601);
        }

        if (!is_null($result)) {
            $testNode['result'] = $result;
        }

        $this->writeAndUnlock($xml);
    }

    /**
     * Get element for test case of given name. If id does not exist yet, it is created.
     * @param \SimpleXMLElement $xml
     * @param $testCaseName
     * @return \SimpleXMLElement
     */
    protected function getTestCaseNode(\SimpleXMLElement $xml, $testCaseName)
    {
        $testcaseNode = $xml->xpath('//testcase[@name="' . $testCaseName . '"]');

        if (!$testcaseNode) {
            $testcaseNode = $xml->addChild('testcase');
            $testcaseNode->addAttribute('name', $testCaseName);
        } else {
            $testcaseNode = reset($testcaseNode);
        }

        return $testcaseNode;
    }

    /**
     * Get element for test of given name. If id does not exist yet, it is created.
     * @param \SimpleXMLElement $xml
     * @param $testName
     * @return \SimpleXMLElement
     */
    protected function getTestNode(\SimpleXMLElement $xml, $testName)
    {
        $testNode = $xml->xpath('//test[@name="' . $testName . '"]');

        if (!$testNode) {
            $testNode = $xml->addChild('test');
            $testNode->addAttribute('name', $testName);
        } else {
            $testNode = reset($testNode);
        }

        return $testNode;
    }

    /**
     * @return \SimpleXMLElement
     */
    protected function readAndLock()
    {
        $file = $this->getFilePath();

        if ($this->fileHandle) {
            throw new \RuntimeException(
                sprintf('File "%s" was already opened by this XmlPublisher and closed', $file)
            );
        }

        if (!is_writable($this->fileDir)) {
            throw new \RuntimeException(
                sprintf(
                    'Directory "%s" does not exist or is not writeable.'
                    . ' Didn\'t you forget to either define LOGS_DIR constant or call setFileDir()?',
                    $this->fileDir
                )
            );
        }

        // open (or create) the file and acquire exclusive lock (or wait until it is acquired)
        $this->fileHandle = fopen($file, 'c+');
        if (!flock($this->fileHandle, LOCK_EX)) {
            throw new \RuntimeException(sprintf('Cannot obtain lock for file "%s"', $file));
        }

        if (fstat($this->fileHandle)['size'] == 0) { // new or empty file, create empty xml element
            $xml = new \SimpleXMLElement(
                '<?xml version="1.0" encoding="utf-8" ?>'
                . '<?xml-stylesheet type="text/xsl" href="../lib/results.xsl"?>'
                . '<testcases />'
            );
        } else { // file already exists, load current xml
            $fileContents = fread($this->fileHandle, fstat($this->fileHandle)['size']);
            $xml = simplexml_load_string($fileContents);
        }

        return $xml;
    }

    /**
     * @param \SimpleXMLElement $xml
     */
    protected function writeAndUnlock(\SimpleXMLElement $xml)
    {
        if (!$this->fileHandle) {
            throw new \RuntimeException(
                sprintf(
                    'File "%s" was not opened by this XmlPublisher yet (or it was already closed)',
                    $this->getFilePath()
                )
            );
        }

        // remove all file contents
        ftruncate($this->fileHandle, 0);
        rewind($this->fileHandle);

        // write new contents (formatted)
        $dom = new \DOMDocument('1.0');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml->asXML());
        fwrite($this->fileHandle, $dom->saveXML());

        // unlock and close the file, remove reference
        flock($this->fileHandle, LOCK_UN);
        fclose($this->fileHandle);
        $this->fileHandle = null;
    }
}
