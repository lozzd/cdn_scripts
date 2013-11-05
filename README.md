cdn_scripts
===========

### What are these scripts for? 
Miscellaneous scripts for monitoring and operating various CDNs

### What is Graphite? 
Scalable realtime graphing: http://graphite.wikidot.com/

### Who are these CDNs? 
Various providers that may or may not meet your requirements. 

[Fastly](http://www.fastly.com)

[Edgecast](http://www.edgecast.com)

[Akamai](http://www.akamai.com)


## fastly\_to\_graphite.php

This script pulls the stats from the Fastly reporting API and feeds them into Graphite. 

### Configuration

Config is marked down within the file. There are two sections:

* Configuring your Graphite server and Fastly API key
* An options array that has the name and Fastly service ID of each service you wish to monitor.

### Setup/Running

Complete configuration and then run once by hand to check that the script performs as expected.

After that, cron using something like:

     * * * * * /usr/local/bin/fastly_to_graphite.php > /var/log/fastly_to_graphite.log 2>&1

It will run one a minute, pushing the stats into Graphite as configured. 

### Stats Recorded

The following stats are pushed into Graphite:

* Number of requests
* Status of requests (pipe/hit/miss/uncacheable)
* Status codes
* Bandwidth (header size and body size)

### Notes/special considerations: 
* This plugin outputs per datacenter stats, for maximum granularity. You may wish to use sumSeries with a wildcard operator in place of the datacenter name in your graphs to get total stats. 
* This script currently retrieves a minute of information from 11 minutes to 10 minutes ago. This is because historically there were some delays in all the data reaching Fastly. This may have changed. 

## akamai\_to\_graphite.php

This script pulls the stats from the Akamai reporting API and feeds them into Graphite. 

### Configuration

Config is marked down within the file. There are two sections:

* Configuring your Graphite server
* An options array that has the following configuration values:
      * **friendly\_name**: For your benefit for your logs
      * **graphite\_name**: Will be appended to GRAPHITE\_PREFIX to form the graphite key
      * **cp\_code**: The CP Code of your service with Akamai
      * **stats\_period**: The definition of reporting. Recommended to leave at STATS\_PERIOD\_MINUTES
      * **type**: The type of service you hold with Akamai. 'dsa' and 'dsd' are supported.
      * **username**: The webservices username (you MUST create a webservices user under your regular user)
      * **password**: The webservices password


### Setup/Running

As above, you must create a webservices user under your main Akamai account to use with this script. 

Complete configuration and then run once by hand to check that the script performs as expected.

After that, cron using something like:

     * * * * * /usr/local/bin/akamai_to_graphite.php > /var/log/akamai_to_graphite.log 2>&1

It will run one a minute, pushing the stats into Graphite as configured. 

### Stats Recorded

The following stats are pushed into Graphite:

* Number of requests (edge/midgress/origin)
* Status codes (for both edge and origin)
* Bandwidth (edge/midgress/origin)

### Notes/special considerations: 
* This script currently retrieves a minute of information from 21 minutes to 16 minutes ago. This is because the Akamai reporting is not completely live, so data is pulled in with a slight delay to give more chance of it being 100% correct.


## edgecast\_to\_graphite.rb

This script pulls the stats from the Edgecast reporting API and feeds them into Graphite. 

### Configuration

Config is marked down within the file. Two main pieces of information are required:

* Your Edgecast authentication information, including your 4 digit customer ID and your API token
* The details of your Graphite server


### Setup/Running

Complete configuration and then run once by hand to check that the script performs as expected.

After that, cron using something like:

     * * * * * /usr/local/bin/edgecast_to_graphite.rb > /var/log/edgecast_to_graphite.log 2>&1

It will run one a minute, pushing the stats into Graphite as configured. 

### Stats Recorded

The following stats are pushed into Graphite:

* Number of requests
* Status codes
* Bandwidth
* Connections
* Detailed Cache Status

### Notes/special considerations: 
* This script pulls in data live, as Edgecast's reporting API has proven to be very up to date. 
* Unfortunately, Edgecast do not have the concept of splitting out services under a single "platform" bucket, for example ADN. For this reason, you cannot get granular data for each service if two or more share the platform. Stats are pulled in per platform. 
