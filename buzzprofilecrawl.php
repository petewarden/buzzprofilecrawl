#!/usr/bin/php
<?php

/**
*************WARNING*****************
By running this script you're using resources on Google's servers, so be respectful of their 
commitment to an open and crawlable web. Check http://google.com/robots.txt at least once a week to
ensure you're abiding by their site rules, don't fire too many requests at once, and make sure you
supply a valid contact email so they can get in touch in case of problems.
*************************************

This script allows you to download public Google Profiles and extract structured information from
the HTML. 

To test it, go to the command line, cd to this folder and run

./buzzprofilecrawl.php -f "testdata_*.txt" -e <email> -o <organization>

where <email> and <organization> are replaced by your contact email address and company, so that
Google can get in touch if your crawling causes any problems. You should see JSON arrays of
information for each of the 20 users mentioned in the test data files. 

The script fetches the HTML for the page from Google's servers, and then runs a set of regular
expressions to extract the microformatted information for that user. The profiles mostly use hcard
to help robots like us understand what the meaning of the different elements is.

The output is in the form <userid><tab character><json data>, eg:

106664725926862859359	{"user_name":"searchbrowser","name":"Pete Warden","portrait_url":"\/s2\/photos\/public\/AIbEiAIAAABDCN_Y_J-1nfe-XCILdmNhcmRfcGhvdG8qKDdkYTYyODgxMTAzYjg0OGUzODAzNjM1OTUxMzgxMWVhNjY3MzdlZDgwAUQ6MaRMKXz3oZLOOF-uOVBoUoqx","location":"Boulder, CO","location_born":"Cambridge, UK","employment_history":["Apple"],"education_history":["University of Manchester"],"links":["http:\/\/petewarden.typepad.com\/"],"title":"Software Engineer","organization":"Mailana Inc","location_history":["Dundee, Scotland","Los Angeles, CA"]}
 
(c) Pete Warden <pete@petewarden.com> http://petewarden.typepad.com/ Jan 8th 2010

Redistribution and use in source and binary forms, with or without modification, are
permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice, this 
  list of conditions and the following disclaimer.
2. Redistributions in binary form must reproduce the above copyright notice, this 
  list of conditions and the following disclaimer in the documentation and/or 
  other materials provided with the distribution.
3. The name of the author may not be used to endorse or promote products derived 
  from this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE AUTHOR ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR ANY
DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, 
BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR 
PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, 
WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) 
ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY
OF SUCH DAMAGE.

 */

require_once('cliargs.php');
require_once('parallelcurl.php');

define('SOURCE_USER_ID_RE', '@http://www.google.com/profiles/([0-9]+)@');
define('SOURCE_USER_NAME_RE', '@http://www.google.com/profiles/([^/]+)@');

// These are the REs used to extract the information from the raw HTML. Most of the
// elements are defined by the hCard microformat, for more details see
// http://microformats.org/wiki/hcard
define('NAME_RE', '@<span class="fn">([^<]*)</span>@');
define('PORTRAIT_RE', '@<img class="ll_profilephoto photo" src="([^"]+)"@');
define('LOCATION_RE', '@<span class="adr">([^<]*)</span>@');
define('TITLE_RE', '@<div class="tagline"><p>([^<]*) at@');
define('SCHOOL_RE', '@<span class="school">([^<]*)</span>@');
define('LOCATION_BORN_RE', '@<dt>Where I grew up</dt><dd>([^<]*)</dd>@');
define('WORK_HISTORY_RE', '@<dt>Companies I&#39;ve worked for</dt><dd>([^<]*)</dd>@');
define('EDUCATION_HISTORY_RE', '@<dt>Schools I&#39;ve attended</dt><dd>([^<]*)</dd>@');
define('OTHER_NAME_RE', '@<dt>Other names</dt><dd>([^<]*)</dd></dl></div>@');
define('LINKS_RE', '@<div class="link"><a href="([^"]+)">@');
define('TITLE_ALT_RE', '@<span class="title">([^<]*)</span>@');
define('ORGANIZATION_RE', '@<span class="org">([^<]*)</span>@');
define('LOCATION_HISTORY_RE', '@<dt>Places I&#39;ve lived</dt><dd>([^<]*)</dd>@');
define('MENTIONS_RE', '@/profiles/([0-9]{21,21})@');

$contentrelist = array(
    NAME_RE => array('name' => 'name'),
    PORTRAIT_RE => array('name' => 'portrait_url'),
    LOCATION_RE => array('name' => 'location'),
    TITLE_RE => array('name' => 'title'),
    SCHOOL_RE => array('name' => 'organization'),
    LOCATION_BORN_RE => array('name' => 'location_born'),
    WORK_HISTORY_RE => array('name' => 'employment_history', 'list' => true),
    EDUCATION_HISTORY_RE => array('name' => 'education_history', 'list' => true),
    OTHER_NAME_RE => array('name' => 'other_names', 'list' => true),
    LINKS_RE => array('name' => 'links', 'multiple' => true),
    TITLE_ALT_RE => array('name' => 'title'),
    ORGANIZATION_RE => array('name' => 'organization'),
    LOCATION_HISTORY_RE => array('name' => 'location_history', 'list' => true),
    MENTIONS_RE => array('name' => 'mentions', 'multiple' => true),
);

define('FETCH_TIMEOUT', 30);

// This function is called when ParallelCurl completes a page fetch, and it handles converting
// the HTML into structured JSON data that's printed to stdout.
function parse_page($content, $url, $ch, $userdata)
{
	global $contentrelist;

    $redirecturl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

	if (empty($content))
		return null;

    $content = str_replace("\n", "", $content);

    if (!preg_match(SOURCE_USER_ID_RE, $url, $matches))
        return null;

    $userid = $matches[1];

    if (!preg_match(SOURCE_USER_NAME_RE, $redirecturl, $matches))
        return null;

    $username = $matches[1];
    if ($username==$userid)
        $username = '';
    
    $result = array();
    if (!empty($username))
        $result['user_name'] = $username;
    
	foreach ($contentrelist as $currentre => $reinfo)
	{
		if (!preg_match_all($currentre, $content, $matches))
            continue;

        if (isset($reinfo['multiple']))
        {
            $matcharray = $matches[1];
            $output = array();
            foreach ($matcharray as $matchtext)
                $output[] = htmlspecialchars_decode($matchtext, ENT_QUOTES);
        }
        else if (isset($reinfo['list']))
        {
            $matchtext = htmlspecialchars_decode($matches[1][0], ENT_QUOTES);
            $output = explode('; ', $matchtext);
        }
        else
        {
            $matchtext = htmlspecialchars_decode($matches[1][0], ENT_QUOTES);
            $output = $matchtext;;
        }
        $name = $reinfo['name'];
        
        if (!empty($output))
            $result[$name] = $output;
	}
    
    print $userid."\t".json_encode($result)."\n";
}

$cliargs = array(
	'filepattern' => array(
		'short' => 'f',
		'type' => 'required',
		'description' => 'The files to read the URLs from',
	),
    'organization' => array(
        'short' => 'o',
        'type' => 'required',
        'description' => 'The name of the organization or company running this crawler',
    ),
    'email' => array(
        'short' => 'e',
        'type' => 'required',
        'description' => 'An email address where server owners can report any problems with this crawler',
    ),
    'threads' => array(
        'short' => 't',
        'type' => 'optional',
        'description' => 'How many to requests to run at the same time',
        'default' => 1,
    ),
);

$options = cliargs_get_options($cliargs);
$filepattern = $options['filepattern'];
$organization = $options['organization'];
$email = $options['email'];
$threads = $options['threads'];

if (empty($organization) || empty($email) || (!strpos($email, '@')))
    die("You need to specify a valid organization and email address\n");

$agent = 'Crawler from '.$organization;
$agent .= ' - contact '.$email;
$agent .= ' to report any problems with my crawling. Based on code from http://petewarden.typepad.com';

$curloptions = array(
    CURLOPT_SSL_VERIFYPEER => FALSE,
    CURLOPT_SSL_VERIFYHOST => FALSE,
	CURLOPT_FOLLOWLOCATION => TRUE,
	CURLOPT_USERAGENT => $agent,
	CURLOPT_TIMEOUT => FETCH_TIMEOUT,
);

$parallelcurl = new ParallelCurl($threads, $curloptions);

// Loop through all the files, extract all the URLs and process them
foreach (glob($filepattern) as $filename) 
{
    error_log("Reading $filename");
    
    $filehandle = fopen($filename, 'r');
    
    $usertotal = 0;
    
    while(!feof($filehandle))
    {
        $currentline = fgets($filehandle);
        $currenturl = trim($currentline);

        $usertotal += 1;
        if (($usertotal%10000)===0)
            error_log(number_format($usertotal).' users processed');

        if (empty($currenturl))
            continue;

        if (is_numeric($currenturl))
            $currenturl = 'http://www.google.com/profiles/'.$currenturl;
    
        $parallelcurl->startRequest($currenturl, 'parse_page');
    }

    fclose($filehandle);
}

// Important - if you remove this any pending requests may not be processed
$parallelcurl->finishAllRequests();

?>