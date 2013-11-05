#!/usr/bin/env ruby
# Pulls stats from the Edgecast reporting API and submits them to Graphite
# This outputs keys ready for graphite. Use it something like:
#

require 'net/http'
require 'json'
require 'pp'

# Configuration:
# Enter your customer ID in the URL below:
BASE_URI = 'https://api.edgecast.com/v2/realtimestats/customers/MYID/media/'
# Enter your API token for authentication
TOKEN    = 'abcdefgh-abcd-1234-abcd-1234567890ab'
GRAPHITE_HOST = 'graphite.yourcompany.com'
GRAPHITE_PORT = '2003'
GRAPHITE_PREFIX = 'cdn.edgecast'

# Pulling stats for both the http_small and ADN platforms.
# Unfortunately the stats aren't more fine grained than tihs. If you have
# more than one 'service' using the platform(s), you'll get them added together.
# Remove or add platforms as applicable below if you do or don't use them.
platforms = { 'http_small' => '8', 'adn' => '14' }

timestamp = Time.now.to_i

headers = {
    'Authorization' => "tok:#{TOKEN}",
    'Accept'        => 'application/json',
    'Content-Type'  => 'application/json',
    'Host'          => 'api.edgecast.com',
}

uri = URI(BASE_URI)

http = Net::HTTP.new(uri.host, uri.port)
http.use_ssl = true


def send_graphite(key, value, timestamp)
    begin
        socket = TCPSocket.open(GRAPHITE_HOST, GRAPHITE_PORT)
        message = "#{key} #{value} #{timestamp}\n"
        puts "Sending graphite data: #{message}"
        socket.write(message)
    rescue Exception => e
        puts 'Graphite was unable to process the request.'
        puts e.to_s
    ensure
        socket.close unless socket.nil?
    end
end


platforms.each do |platform,id|
    # Bandwidth
    resp = http.get("#{uri.path}/#{id}/bandwidth", headers)
    values = JSON.parse(resp.body)

    if values.has_key?("Result")
        bps = values["Result"]
    else
        bps = 0
    end

    send_graphite("#{GRAPHITE_PREFIX}.#{platform}.bandwidth", bps, timestamp)

    # Connections
    resp = http.get("#{uri.path}/#{id}/connections", headers)
    values = JSON.parse(resp.body)

    if values.has_key?("Result")
        conns = values["Result"].to_f.ceil
    else
        conns = 0
    end

    send_graphite("#{GRAPHITE_PREFIX}.#{platform}.connections", conns, timestamp)

    # Cache Status
    resp = http.get("#{uri.path}/#{id}/cachestatus", headers)
    values = JSON.parse(resp.body)

    values.each do |status|
        send_graphite("#{GRAPHITE_PREFIX}.#{platform}.cachestatus.#{status["CacheStatus"]}", status["Connections"], timestamp)
    end

    # Status Codes
    resp = http.get("#{uri.path}/#{id}/statuscode", headers)
    values = JSON.parse(resp.body)

    values.each do |status|
        send_graphite("#{GRAPHITE_PREFIX}.#{platform}.statuscode.#{status["StatusCode"]}", status["Connections"], timestamp)
    end
end


