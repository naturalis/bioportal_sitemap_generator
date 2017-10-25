<?php
/**
 * Indicator
 *
 * Class to display progress of running script by printing dots and percentage
 * done. Optionally (by default) also displays current running time and estimated
 * remaining time.
 *
 * @author Nuria Torrescasana Aloy, Ruud Altenburg
 */
class Indicator
{
	protected $_enabled = false;
	// settings
	protected $_marker = '.';
    protected $_breakLine = "<br>";
    protected $_iterationsPerMarker = 10;
    protected $_markersPerLine = 75;

    protected $_totalNumberOfIterations = 0;

    protected $_iterationCounter = 0;
    protected $_markersPerCycleCounter = 0;
    protected $_cycleCounter = 0;
    protected $_summarizedDuration = 0;
    protected $_start = 0;

    protected $_fh;
    protected $_dir;

    /**
     * Overwrite default breakline
     */
    public function setBreakLine ($breakLine)
    {
    	$this->_breakLine = $breakLine;
    }

    public function setMarkersPerLine ($n)
    {
    	$this->_markersPerLine = $n;
    }

    public function setIterationsPerMarker ($n)
    {
    	$this->_iterationsPerMarker = $n;
    }

    /**
     * Initialize indicator
     *
     * @param int $numberOfIterations total number of records
     * @param int $markersPerLine number of dots per line; if not set default is used
     * @param int $iterationsPerMarker number of request per dot; if not set default is used
     */
    public function init($numberOfIterations, $markersPerLine = null,
        $iterationsPerMarker = null)
    {
    	if($markersPerLine !== null) {
    		$this->_markersPerLine = (int)$markersPerLine;
    	}
        if($iterationsPerMarker !== null) {
            $this->_iterationsPerMarker = (int)$iterationsPerMarker;
        }
    	$this->_enabled = true;
        $this->_totalNumberOfIterations = (int)$numberOfIterations;
        $this->reset();
    }

    /**
     * Main method displaying progress
     *
     * Currently flush() is used to flush the buffer but it's safer to set
     * alwaysFlush() (in library.php) at the top of the converter script document
     *
     * @param bool $displayRemaining optionally disable display of running/remaining time
     */
    public function iterate($displayRemaining = true)
    {
    	if(!$this->_enabled) {
    		return;
    	}
    	$this->_iterationCounter ++;
    	$this->_cycleCounter ++;
    	if ($this->_iterationCounter >= $this->_iterationsPerMarker) {
            echo $this->_marker;
            flush();
            $this->_iterationCounter = 0;
            $this->_markersPerCycleCounter ++;
            if ($this->_markersPerCycleCounter >= $this->_markersPerLine) {
                if ($this->_markersPerCycleCounter > 0 &&
                        $this->_totalNumberOfIterations > 0) {
                    $currentPercentageDone = round(
                        ($this->_cycleCounter /
                        $this->_totalNumberOfIterations * 100), 1
                    );
                    $res = ' ' . $currentPercentageDone . '% done';
                }
                if ($displayRemaining) {
                    $res .= '; ' . $this->_printRemainingTime();
                }
    		    echo $res . $this->_breakLine;
    		    $this->_writeProgress($res);
    		    flush();
    		    $this->_markersPerCycleCounter = 0;
            }
     	}
    }

    /**
     * Disable iterator
     */
    public function disable() {
    	$this->reset();
    	$this->_enabled = false;
    }

    /**
     * Reset iterator
     *
     * Used when iterator is used for a different cycle
     */
    public function reset()
    {
    	$this->_iterationCounter = 0;
    	$this->_markersPerCycleCounter = 0;
    	$this->_cycleCounter = 0;
    	$this->_start = microtime(true);
    }

    /**
     * Format time in seconds to d:h:m:s format
     *
     * Typically takes the difference between two microtime(true) instances.
     * Displays days only if relevant.
     *
     * @param float $time time in seconds
     * @return string formatted time
     */
    public function formatTime($time)
    {
        $remaining = '';
        $units = array();
        $days = floor($time / 86400);
        $hours = floor((($time / 86400) - $days) * 24);
        $minutes = floor((((($time / 86400) - $days) * 24) -
            $hours) * 60);
        $seconds = floor((((((($time / 86400) - $days) * 24) -
            $hours) * 60) - $minutes) * 60);
        // Only print days if these are set
        $days > 0 ? $units[] = $days : false;
        array_push($units, $hours, $minutes, $seconds);
        foreach ($units as $unit) {
            if ($unit < 10) {
                $remaining .= "0";
            }
            $remaining .= $unit.":";
        }
        return substr($remaining, 0, -1);
    }

    /**
     * Prints running and remaining time
     *
     * @return string running and remaining time
     */
    private function _printRemainingTime()
    {
        $now = microtime(true) - $this->_start;
        $runningTime = $this->formatTime($now);
        $remainingTime = $this->formatTime(
            ($this->_totalNumberOfIterations - $this->_cycleCounter) *
            $now / $this->_cycleCounter
        );
        return "running $runningTime, remaining $remainingTime";
    }

    private function _writeProgress ($str)
    {
        $fh = fopen($this->_dir . 'monitor', 'w+');
        if (!$fh) {
            throw new Exception('Cannot write progress');
        }
        fwrite($fh, trim($str));
        fclose($fh);
    }

    public function setDir ($dir)
    {
        $this->_dir = $dir;
    }
}