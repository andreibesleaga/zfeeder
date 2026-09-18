<?php 
// zFeeder 1.6 - copyright (c) 2003-2004 Andrei Besleaga
// http://zvonnews.sourceforge.net
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
// You should have received a copy of the GNU General Public License
// along with this program; if not, write to the Free Software
// Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
//
//
// MANUAL FEED CONFIGURATION //////////////////////////////////////////////////////////
//
// NOTE: this is to be used ONLY when the option "use subscription file" is "no" (in the admin panel :: config)
// whenever you disable that option (or it is not working) you can configure the feeds here
//
// feeds are displayed in the order they are below
// follow the below format (all three fields are required) to add/modify/delete RSS feeds
//
// first parameter is : the RSS url
// second parameter is : the number of news which should be displayed from this news feed
// the third parameter is : the refreshtime - number of minutes when feed is re-read from it's url and stored in the cache dir
//
// examples:
//
// addFeed("http://zvonnews.sourceforge.net/news/rss.php", 1, 1440);
// addFeed("http://rss.com.com/2547-12-0-20.xml", 3, 100);
// addFeed("http://slashdot.org/slashdot.rdf", 3, 120);
// addFeed("http://www.wired.com/news_drop/netcenter/netcenter.rdf", 3, 240);
// addFeed("http://newsforge.com/newsforge.rss", 3, 120);
// addFeed("http://www.explodingcigar.com/backend.xml", 3, 1440);
// addFeed("http://sourceforge.net/export/rss2_projsummary.php?group_id=84348",3,1440);
//
// END OF MANUAL FEED CONFIGURATION ///////////////////////////////////////////////////


// zFeeder main

// global $zf_feed, $zf_html;
error_reporting (E_ALL ^ E_NOTICE);
$zf_path = str_replace("\\", "/", dirname(__FILE__)) . "/";
require_once($zf_path . 'config.php');

if (ZF_REFRESHKEY == '') {
    $zf_scrMode = 'online';
} elseif (!isset($_GET['zfrefresh'])) {
    $zf_scrMode = 'offline';
} elseif ($_GET['zfrefresh'] == ZF_REFRESHKEY) {
    $zf_scrMode = 'offrefresh';
    echo date("r") . " - zFeeder feed refreshing <br />\n\n";
} else exit;

require_once($zf_path . 'includes/zfuncs.php');
set_magic_quotes_runtime(0);

if (ZF_USEOPML == 'yes') {
	if (isset($_GET['zfcategory']) && $_GET['zfcategory']!='' && file_exists($zf_path . ZF_OPMLDIR . '/' . $_GET['zfcategory'] . '.opml')) {
		$opmlfilename=$zf_path . ZF_OPMLDIR . '/' . $_GET['zfcategory'] . '.opml';
		$zf_category=$_GET['zfcategory'];
	} else {
		$opmlfilename=$zf_path . ZF_OPMLDIR . '/' . ZF_CATEGORY . '.opml';
		$zf_category=ZF_CATEGORY;
	}
    $zf_opmlResult = parseOpmlFile($opmlfilename);
    if ($zf_opmlResult == 'opmlok') {
        sort($zf_opmlItems);
		$zf_showedPositions='';
        foreach($zf_opmlItems as $i => $zf_opmlItem) {
            $zf_showedItems = $zf_opmlItem['showeditems'];
            $zf_xmlUrl = $zf_opmlItem['xmlurl'];
            $zf_position = $zf_opmlItem['position'];
            if ($zf_opmlItem['issubscribed'] == 'yes' && $zf_showedItems > 0 && $zf_xmlUrl != '' && $zf_position >= 0) {
                $zf_feeds[$zf_position]['url'] = $zf_xmlUrl;
                $zf_feeds[$zf_position]['items'] = $zf_showedItems;
                $zf_feeds[$zf_position]['refreshtime'] = $zf_opmlItem['refreshtime'];
                $zf_showedPositions .= 'p' . $zf_position . ',';
            } 
        } 
    } else {
        echo $zf_opmlResult;
    } 
} 

if (!empty($zf_feeds)) {
    if (isset($_GET['zfposition']) && $_GET['zfposition'] != '') {
        $zf_showUrl = split(',', str_replace('p', '', $_GET['zfposition']));
    } 
    if ($zf_scrMode != 'offrefresh') {
        if (isset($_GET['zftemplate']) && $_GET['zftemplate'] != '' && templateExists($zf_path . 'templates/' . $_GET['zftemplate'] . '.html')) {
            $zf_template = $zf_path . 'templates/' . $_GET['zftemplate'] . '.html';
        } elseif (templateExists($zf_path . ZF_TEMPLATE . '.html', true)) {
            $zf_template = $zf_path . ZF_TEMPLATE . '.html';
        } 
        $zf_html = loadTemplate($zf_template);
		echo splitTemplate($zf_html, 'zFeeder template header');
    } 
	
    if (!isset($zf_showUrl)) {
        foreach($zf_feeds as $zf_crtFeedNr => $zf_crtFeed) {
            if (isset($zf_crtFeed['url']) && trim($zf_crtFeed['url']) != '' && $zf_crtFeed['items'] > 0) {
                $zf_feedUrl = $zf_crtFeed['url'];
                $zf_storedFile = url2file($zf_feedUrl);

                /* if(file_exists($zf_path.ZF_CACHEDIR) && is_dir($zf_path.ZF_CACHEDIR))
						if(!is_writable($zf_path.ZF_CACHEDIR) || !is_readable($zf_path.ZF_CACHEDIR) || !is_executable($zf_path.ZF_CACHEDIR)) {@chmod($zf_path.ZF_CACHEDIR,0777);}
				else {mkdir($zf_path.ZF_CACHEDIR,0777);} */

                if ($zf_scrMode != 'offline') {
                    $zf_reResult = refreshFeed($zf_feedUrl, $zf_path . ZF_CACHEDIR . '/' . $zf_storedFile, $zf_crtFeed['refreshtime']);
                } 
                if ($zf_scrMode != 'offrefresh') {
                    parseRssFile($zf_path . ZF_CACHEDIR . '/' . $zf_storedFile, $zf_crtFeed['items'], $zf_feedUrl, $zf_crtFeedNr);
                } else {
                    echo $zf_reResult;
                } 
            } 
        } 
    } elseif(isset($zf_showUrl)) {
        foreach($zf_showUrl as $zf_crtFeed => $zf_crtFeedNr) {
            if (isset($zf_feeds[$zf_crtFeedNr]['url']) && trim($zf_feeds[$zf_crtFeedNr]['url']) != '' && $zf_feeds[$zf_crtFeedNr]['items'] > 0) {
                $zf_feedUrl = $zf_feeds[$zf_crtFeedNr]['url'];
                $zf_storedFile = url2file($zf_feedUrl);

                /* if(file_exists($zf_path.ZF_CACHEDIR) && is_dir($zf_path.ZF_CACHEDIR))
						if(!is_writable($zf_path.ZF_CACHEDIR) || !is_readable($zf_path.ZF_CACHEDIR) || !is_executable($zf_path.ZF_CACHEDIR)) {@chmod($zf_path.ZF_CACHEDIR,0777);}
				else {mkdir($zf_path.ZF_CACHEDIR,0777);} */

                if ($zf_scrMode != 'offline') {
                    $zf_reResult = refreshFeed($zf_feedUrl, $zf_path . ZF_CACHEDIR . '/' . $zf_storedFile, $zf_feeds[$zf_crtFeedNr]['refreshtime']);
                } 
                if ($zf_scrMode != 'offrefresh') {
                    parseRssFile($zf_path . ZF_CACHEDIR . '/' . $zf_storedFile, $zf_feeds[$zf_crtFeedNr]['items'], $zf_feedUrl, $zf_crtFeedNr);
                } else {
                    echo $zf_reResult;
                } 
            } 
        } 
    } 
    if ($zf_scrMode != 'offrefresh') {
        program_end();
    } 
} else {
    echo ' No feeds.';
} 

?>
