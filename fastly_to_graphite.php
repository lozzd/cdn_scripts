#!/bin/env php
<?php
# Takes the stats from the Fastly reporting API and feeds them into Graphite.

# Your Fastly API key
const FASTLY_KEY = "";

# Configure your Fastly services goes here:
# - The array key is the name of the service and the graphite key that will be used
# - The array value is the service ID, which can be pulled from the Fastly UI.
$config = array('www' => 'AbCdEfGhIjKlMnOpQrStUv', 'img' => 'a1bc3d4e5f6g7h8i9j0k1l');

const GRAPHITE_HOST = 'graphite.yourcompany.com';
const GRAPHITE_PORT = '2003';
const GRAPHITE_PREFIX = 'cdn.fastly';

# Can adjust these if you wish. The intentional delay in reporting is a safeguard in case
# the API doesn't have complete stats yet. Feel free to experiment.
const STATS_PERIOD = "minutely";
const STATS_START = "10 minutes ago";
const STATS_END = "9 minutes ago";
const FASTLY_API_URL = "https://api.fastly.com";

# End configuration

function getStatsJSON($service_id) {
    // Get the JSON API data from Fastly and return it. 

    $start = new DateTime(STATS_START, new DateTimeZone('UTC'));
    $end = new DateTime(STATS_END, new DateTimeZone('UTC'));

    $opts = array(
        'http' => array('method' => 'GET',
                        'header' => "X-Fastly-Key: " . FASTLY_KEY . "\r\n"
                    )
                );

    $context = stream_context_create($opts);

    $url = FASTLY_API_URL . "/service/" . $service_id . "/stats/summary?start_time=" . $start->format('U') . "&end_time=" . $end->format('U');
    echo date("r") . " - Getting data from Fastly API from $url\n";
    $content = file_get_contents($url, false, $context);
    $json = json_decode($content, true); 
    return $json['stats'];

}


function runStats($service_id, $service_name) { 

    if (!$this_stats = getStatsJSON($service_id)) {
        echo date("r") . " - Pulling stats failed, please check error messages! Probably failed auth or service ID wrong.\n";
        return;
    }
    echo date("r") . " - Processing Data\n";
    $start = new DateTime(STATS_START, new DateTimeZone('UTC'));
    $end = new DateTime(STATS_END, new DateTimeZone('UTC'));
    $duration = $end->format('U') - $start->format('U');
    // Now perform post processing for various metrics. 
    foreach ($this_stats as $dc_name => $dc_stats) {
        foreach ($dc_stats as $stat_name => $stat_value) {
            switch ($stat_name) {
                // All of these need just dividing by the interval ($duration)
            case "requests":
            case "uncacheable":
            case "pass":
            case "status_1xx":
            case "status_2xx":
            case "status_3xx":
            case "status_4xx":
            case "status_5xx":
            case "hits":
            case "pipe":
            case "miss":
            case "status_204":
            case "status_200":
            case "status_503":
            case "status_302":
            case "status_304":
            case "status_301":
                $this_stats[$dc_name][$stat_name] = makePerSecond($this_stats[$dc_name][$stat_name], $duration);
                break;
                // All of the below are measured in bytes and across all requests, so convert to bits, and per second. 
                // Don't divide by requests unless you want average size of request!
            case "header_size":
            case "body_size":
                $this_stats[$dc_name][$stat_name] = bytesToBits($this_stats[$dc_name][$stat_name]);
                $this_stats[$dc_name][$stat_name] = makePerSecond($this_stats[$dc_name][$stat_name], $duration);
                break;
            default:
                unset($this_stats[$dc_name][$stat_name]);
            }
        }
    }

    // Calculations done... Send to graphite.
    $timestamp = $end->format('U');
    parseStatsToGraphite($this_stats, $service_name, $timestamp);
}

function parseStatsToGraphite(Array $stats, $graphite_prefix, $timestamp) {
    // Flatten the array and send everything to the graphite server

    $graphite_stats_flat = array_flat($stats);

    foreach ($graphite_stats_flat as $k => $v) {
        $graphite_key = GRAPHITE_PREFIX . ".{$graphite_prefix}.{$k}";
        sendToGraphite($timestamp, $graphite_key, $v);
    }

}

function sendToGraphite($timestamp, $key, $value) {
    // Connect to graphite and send the data

    $data = "$key $value $timestamp";
    echo "Sending to graphite: $data\n";
    $fh = fsockopen(GRAPHITE_HOST, GRAPHITE_PORT);
    if ($fh) {
        fwrite($fh, "$data\r\n");
        fclose($fh);
    } else {
        echo "Could not open connection to graphite!\n";
    }
}

function makePerSecond($value, $minute_period) {
    return $value / $minute_period;
}

function bytesToBits($value) {
    return $value * 8;
}

function array_flat($array, $prefix = '')
{ // Handy function that turns a multidimensional array into a flat key with a prefix (e.g. a full stop for graphite)
    $result = array();
    foreach ($array as $key => $value) {
        $new_key = $prefix . (empty($prefix) ? '' : '.') . $key;
        if (is_array($value)) {
            $result = array_merge($result, array_flat($value, $new_key));
        } else {
            $result[$new_key] = $value;
        }
    }
    return $result;
}


echo date("r") . " - Beginning run\n";
foreach ($config as $service_name => $service_id) {
    echo date("r") . " - Pulling stats for {$service_name}...\n";
    runStats($service_id, $service_name);
}
echo date("r") . " - Done\n";


?>
