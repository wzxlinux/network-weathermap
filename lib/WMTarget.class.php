<?php

/**
 * Class WMTarget
 *
 * A small class to keep together all the processing for targets (data sources).
 * This is to replace an ugly array that gets passed around with mysterious fields.
 *
 */
class WMTarget {
    public $finalTargetString;
    public $originalTargetString;

    public $pluginName;
    public $scaleFactor = 1.0;
    public $pluginRunnable = true;

    public $configLineNumber;
    public $configFileName;

    public $values = array();
    public $timestamp;
    public $dataValid = false;

    public function __construct($targetString, $configFile, $lineNumber)
    {
        $this->originalTargetString = $targetString;
        $this->finalTargetString = $targetString;
        $this->configFileName = $configFile;
        $this->configLineNumber = $lineNumber;
        $this->pluginRunnable = false;

        $this->values[IN] = null;
        $this->values[OUT] = null;
        $this->timestamp = null;

        wm_debug("New Target Created: $this\n");
    }

    public function __toString()
    {
        return sprintf("%s on config line %s of %s", $this->finalTargetString, $this->configLineNumber, $this->configFileName);
    }

    public function preProcess(&$mapItem, &$map)
    {
        // We're excluding notes from other plugins here
        // to stop plugin A from messing with plugin B
        $this->finalTargetString = $map->processString($this->originalTargetString, $mapItem, false, false);

        if ($this->originalTargetString != $this->finalTargetString) {
            wm_debug("Targetstring is now %s\n", $this->finalTargetString);
        }

        // if the targetstring starts with a -, then we're taking this value OFF the aggregate
        $this->scaleFactor = 1;

        if (preg_match('/^-(.*)/', $this->finalTargetString, $matches)) {
            $this->finalTargetString = $matches[1];
            $this->scaleFactor = -1 * $this->scaleFactor;
        }

        // if the remaining targetstring starts with a number and a *-, then this is a scale factor
        if (preg_match('/^(\d+\.?\d*)\*(.*)/', $this->finalTargetString, $matches)) {
            $this->finalTargetString = $matches[2];
            $this->scaleFactor = $this->scaleFactor * floatval($matches[1]);
        }
    }

    public function findHandlingPlugin($pluginList)
    {
        wm_debug("Finding handler for '%s'\n", $this->finalTargetString);
        foreach ($pluginList as $name => $pluginObject) {
            $isRecognised = $pluginObject->Recognise($this->finalTargetString);

            if ($isRecognised) {
                wm_debug("plugin %s says it can handle it\n", $name);
                $this->pluginName = $name;
                return $name;
            }
        }
        wm_debug("Failed to find a plugin\n");
        return false;
    }

    public function registerWithPlugin($pluginList, &$map, &$mapItem)
    {
        $pluginList[$this->pluginName]->Register($this->finalTargetString, $map, $mapItem);
        $this->pluginRunnable = true;
    }

    public function readData($pluginList, &$map, &$mapItem)
    {
        if( ! $this->pluginRunnable) {
            wm_debug("Plugin %s isn't runnable\n", $this->pluginName);
            return;
        }

        if ($this->scaleFactor != 1) {
            wm_debug("Will multiply result by %f\n", $this->scaleFactor);
        }

        list($in, $out, $datatime) = $pluginList[$this->pluginName]->ReadData($this->finalTargetString, $map, $mapItem);

        if ($in === null && $out === null) {
            wm_warn(sprintf("ReadData: %s %s, target: %s had no valid data, according to %s [WMWARN70]\n", $mapItem->my_type(), $mapItem->name, $this->finalTargetString, $this->pluginName));
            return;
        }

        wm_debug("Collected data %f,%f\n", $in, $out);

        $in *= $this->scaleFactor;
        $out *= $this->scaleFactor;

        $this->values[IN] = $in;
        $this->values[OUT] = $out;
        $this->timestamp = $datatime;
        $this->dataValid = true;
    }

    public function asConfig()
    {
        if (strpos($this->originalTargetString, " ") !== false) {
            return '"'.$this->originalTargetString.'"';
        }
        return $this->originalTargetString;
    }

    public function hasValidData()
    {
        return $this->dataValid;
    }

    public function getData()
    {
        $result = $this->values;
        array_unshift($result, $this->timestamp);

        return $result;
    }

}