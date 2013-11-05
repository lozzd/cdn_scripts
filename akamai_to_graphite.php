#!/bin/env php
<?php
# Takes stats from the Akamai reporting API and feeds them into Graphite.
# See below for configuration options
#
# Note: Because of Akamai's reporting delay, this script will be inserting data for 15 minutes previous 
# to the current time, so the data will always be slightly delayed in graphite. 
#

global $akamai;

const SITE_ACCELERATOR_WSDL = "https://control.akamai.com/nmrws/services/SiteAcceleratorReportService?wsdl";
const SITE_DELIVERY_WSDL = "https://control.akamai.com/nmrws/services/SiteDeliveryReportService?wsdl";
const AKAMAI_GRANULARITY_5MIN = 'F';
const AKAMAI_GRANULARITY_HOUR = 'H';
const AKAMAI_GRANULARITY_DAY = 'D';
const CRON_FREQUENCY = 60;
const STATS_PERIOD_MINUTES = 5;
const STATS_START = '21 minutes ago';
const STATS_END = '11 minutes ago';


# Start Configuration

# Enter your graphite configuration here
const GRAPHITE_HOST = 'graphite.yourcompany.com';
const GRAPHITE_PORT = '2003';
const GRAPHITE_PREFIX = 'cdn.akamai';

# This array has each of the services you wish to monitor. You can monitor different CP codes of different service
# types (dsa vs dsd) and have them go to seperate graphite keys. 
#
# Config options:
# friendly_name: For your benefit for your logs
# graphite_name: Will be appended to GRAPHITE_PREFIX to form the graphite key
# cp_code: The CP Code of your service with Akamai
# stats_period: The definition of reporting. Recommended to leave at STATS_PERIOD_MINUTES
# type: The type of service you hold with Akamai. 'dsa' and 'dsd' are supported. 
# username: The webservices username (you MUST create a webservices user under your regular user)
# password: The webservices password
$config = array(
    array("friendly_name" => "www", "graphite_name" => "www", "cp_code" => 123456, "stats_period" => STATS_PERIOD_MINUTES, "type" => "dsa", "username" => "stats_webservices_user", "password" => "stats_password"),
);


# End Configuration

function connectAkamai($wsdl, $user, $password) {

    global $akamai;

    $akamai = new SoapClient($wsdl,
        array("login" => $user,
        "password" => $password,
        "trace" => true,
        "connection_timeout" => 5));
}

function getTrafficStats($cpcode) {

    global $akamai;

    $start = new DateTime(STATS_START, new DateTimeZone('UTC'));
    $end = new DateTime(STATS_END, new DateTimeZone('UTC'));
    $csv = $akamai->getTrafficSummaryGranularityForCPCode(array($cpcode), $start->format('c'), $end->format('c'), AKAMAI_GRANULARITY_5MIN);
    $lines = explode("\n", $csv);
    $headers = str_getcsv($lines[2]);
    $values = str_getcsv($lines[4]);
    if(!$stats = @array_combine($headers, $values)) {
        return false;
    } else {
        return $stats;
    }

}

function parseDSAStatsToGraphite(Array $stats, $graphite_prefix, $duration) {
    // This function is used for DSA stats. Both DSD and DSA report APIs use slightly different headers, unfortunately. 

    // Parse Akamai time into a graphite time
    $timestamp = date("U", strtotime($stats['# Time']));

    // Overall stats
    $graphite_stats['total_mb'] = bytesToBits(makePerSecond($stats['Total Volume in MB'], $duration));
    $graphite_stats['edge_mb'] = bytesToBits(makePerSecond($stats['Edge Traffic Volume in MB'], $duration));
    $graphite_stats['midgress_mb'] = bytesToBits(makePerSecond($stats['Midgress Traffic Volume in MB'], $duration));
    $graphite_stats['origin_mb'] = bytesToBits(makePerSecond($stats['Origin Traffic Volume in MB'], $duration));
    $graphite_stats['edge_requests'] = makePerSecond($stats['Edge Requests'], $duration);
    $graphite_stats['midgress_requests'] = makePerSecond($stats['Midgress Requests'], $duration);
    $graphite_stats['origin_requests'] = makePerSecond($stats['Origin Requests'], $duration);

    // Status codes
    $graphite_stats['status_code']['edge']['2xx'] = makePerSecond($stats['Edge OK Requests: 200/206/210'], $duration);
    $graphite_stats['status_code']['edge']['304'] = makePerSecond($stats['Edge 304 Requests'], $duration);
    $graphite_stats['status_code']['edge']['3xx'] = makePerSecond($stats['Edge Redirect Requests: 301/302'], $duration);
    $graphite_stats['status_code']['edge']['4xx'] = makePerSecond($stats['Edge Permission Requests: 401/403/415'], $duration);
    $graphite_stats['status_code']['edge']['403'] = makePerSecond($stats['Edge 403 Requests'], $duration);
    $graphite_stats['status_code']['edge']['404'] = makePerSecond($stats['Edge 404 Requests'], $duration);
    $graphite_stats['status_code']['edge']['5xx'] = makePerSecond($stats['Edge Server Error Requests: 500/501/502/503/504'], $duration);
    $graphite_stats['status_code']['edge']['000'] = makePerSecond($stats['Edge Client Abort Requests: 000'], $duration);
    $graphite_stats['status_code']['edge']['other'] = makePerSecond($stats['Edge Other Requests(all other status codes)'], $duration);

    $graphite_stats['status_code']['origin']['2xx'] = makePerSecond($stats['Origin OK: 200/206/210 Requests'], $duration);
    $graphite_stats['status_code']['origin']['304'] = makePerSecond($stats['Origin 304 Requests'], $duration);
    $graphite_stats['status_code']['origin']['3xx'] = makePerSecond($stats['Origin Redirect: 301/302 Requests'], $duration);
    $graphite_stats['status_code']['origin']['404'] = makePerSecond($stats['Origin 404 Requests'], $duration);
    $graphite_stats['status_code']['origin']['4xx'] = makePerSecond($stats['Origin Permission: 401/403/415 Requests'], $duration);
    $graphite_stats['status_code']['origin']['5xx'] = makePerSecond($stats['Origin Server Error Requests: 500/501/502/503/504'], $duration);
    $graphite_stats['status_code']['origin']['other'] = makePerSecond($stats['Origin Other Requests (all other status codes)'], $duration);

    $graphite_stats_flat = array_flat($graphite_stats);

    foreach ($graphite_stats_flat as $k => $v) {
        $graphite_key = GRAPHITE_PREFIX . ".{$graphite_prefix}.{$k}";
        sendToGraphite($timestamp, $graphite_key, $v);
    }

}

function parseDSDStatsToGraphite(Array $stats, $graphite_prefix, $duration) {
    // This function is used for DSD stats. Both DSD and DSA report APIs use slightly different headers, unfortunately.

    // Parse Akamai time into a graphite time
    $timestamp = date("U", strtotime($stats['# Time']));

    // Overall stats
    $graphite_stats['total_mb'] = bytesToBits(makePerSecond($stats['Total Volume in MB'], $duration));
    $graphite_stats['edge_mb'] = bytesToBits(makePerSecond($stats['Edge Response Volume in MB'], $duration));
    $graphite_stats['midgress_mb'] = bytesToBits(makePerSecond($stats['Midgress Response Volume in MB'], $duration));
    $graphite_stats['origin_mb'] = bytesToBits(makePerSecond($stats['Origin Response Volume in MB'], $duration));
    $graphite_stats['edge_requests'] = makePerSecond($stats['Edge Requests'], $duration);
    $graphite_stats['midgress_requests'] = makePerSecond($stats['Midgress Requests'], $duration);
    $graphite_stats['origin_requests'] = makePerSecond($stats['Origin Requests'], $duration);

    // Status codes
    $graphite_stats['status_code']['edge']['2xx'] = makePerSecond($stats['Edge OK Requests: 200/206/210'], $duration);
    $graphite_stats['status_code']['edge']['304'] = makePerSecond($stats['Edge 304 Requests'], $duration);
    $graphite_stats['status_code']['edge']['3xx'] = makePerSecond($stats['Edge Redirect Requests: 301/302'], $duration);
    $graphite_stats['status_code']['edge']['4xx'] = makePerSecond($stats['Edge Permission Requests: 401/403/415'], $duration);
    $graphite_stats['status_code']['edge']['403'] = makePerSecond($stats['Edge 403 Requests'], $duration);
    $graphite_stats['status_code']['edge']['404'] = makePerSecond($stats['Edge 404 Requests'], $duration);
    $graphite_stats['status_code']['edge']['5xx'] = makePerSecond($stats['Edge Server Error Requests: 500/501/502/503/504'], $duration);
    $graphite_stats['status_code']['edge']['000'] = makePerSecond($stats['Edge Client Abort Requests: 000'], $duration);
    $graphite_stats['status_code']['edge']['other'] = makePerSecond($stats['Edge Other Requests(all other status codes)'], $duration);

    $graphite_stats['status_code']['origin']['2xx'] = makePerSecond($stats['Origin OK: 200/206/210 Requests'], $duration);
    $graphite_stats['status_code']['origin']['304'] = makePerSecond($stats['Origin 304 Requests'], $duration);
    $graphite_stats['status_code']['origin']['3xx'] = makePerSecond($stats['Origin Redirect: 301/302 Requests'], $duration);
    $graphite_stats['status_code']['origin']['404'] = makePerSecond($stats['Origin 404 Requests'], $duration);
    $graphite_stats['status_code']['origin']['4xx'] = makePerSecond($stats['Origin Permission: 401/403/415 Requests'], $duration);
    $graphite_stats['status_code']['origin']['5xx'] = makePerSecond($stats['Origin Server Error Requests: 500/501/502/503/504'], $duration);
    $graphite_stats['status_code']['origin']['other'] = makePerSecond($stats['Origin Other Requests (all other status codes)'], $duration);

    $graphite_stats_flat = array_flat($graphite_stats);

    foreach ($graphite_stats_flat as $k => $v) {
        $graphite_key = GRAPHITE_PREFIX . ".{$graphite_prefix}.{$k}";
        sendToGraphite($timestamp, $graphite_key, $v);
    }

}

function sendToGraphite($timestamp, $key, $value) {
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
    return $value / $minute_period / 60;
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

logline("main", "Beginning Run...");

foreach ($config as $this_config) {
    logline($this_config['friendly_name'], "Connecting to Akamai API...");

    switch ($this_config['type']) {
        case 'dsa':
            $wsdl = SITE_ACCELERATOR_WSDL;
            $stats_function = "parseDSAStatsToGraphite";
            break;
        case 'dsd':
            $wsdl = SITE_DELIVERY_WSDL;
            $stats_function = "parseDSDStatsToGraphite";
            break;
    }

    connectAkamai($wsdl, $this_config['username'], $this_config['password']);

    logline($this_config['friendly_name'], "Gathering stats from Akamai API (CP code: {$this_config['cp_code']})");

    if(!$stats = getTrafficStats($this_config['cp_code'])) {
        logline($this_config['friendly_name'], "ERROR: Could not retrieve stats for this service, perhaps the service has no traffic or Akamai is broken");
    } else {
        logline($this_config['friendly_name'], "Sending stats to Graphite...");
        $stats_function($stats, $this_config['graphite_name'], $this_config['stats_period']);
    }

}

logline("main", "Run Done.");

function logline($context, $message) {
    echo date("r") . " $context - $message\n";
}

?>
