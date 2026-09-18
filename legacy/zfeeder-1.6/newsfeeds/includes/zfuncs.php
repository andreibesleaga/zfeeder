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


// zFeeder functions

function opmlStartElement($parser, $name, $zf_attrs)
{
    global $zf_opmlCount, $zf_opmlItems;
    if (isset($zf_attrs['POSITION']) && $zf_attrs['POSITION'] != '') {
        $zf_opmlItems[$zf_opmlCount]['position'] = $zf_attrs['POSITION'];
        $zf_opmlItems[$zf_opmlCount]['xmlurl'] = ($zf_attrs['XMLURL'] != '')?$zf_attrs['XMLURL']:'';
        $zf_opmlItems[$zf_opmlCount]['refreshtime'] = ($zf_attrs['REFRESHTIME'] != '')?$zf_attrs['REFRESHTIME']:60;
        $zf_opmlItems[$zf_opmlCount]['showeditems'] = ($zf_attrs['SHOWEDITEMS'] != '')?$zf_attrs['SHOWEDITEMS']:0;
        $zf_opmlItems[$zf_opmlCount]['issubscribed'] = ($zf_attrs['ISSUBSCRIBED'] != '')?$zf_attrs['ISSUBSCRIBED']:'no';
    } 
} 

function opmlEndElement($parser, $name)
{
    global $zf_opmlCount;
    if ($name == 'OUTLINE') $zf_opmlCount++;
} 

function parseOpmlFile($filename)
{
    global $zf_opmlItems, $zf_opmlCount;
    $zf_opmlItems = array();
    $zf_opmlCount = 0;
    $xml_parser = xml_parser_create();
    xml_set_element_handler($xml_parser, "opmlStartElement", "opmlEndElement");
    @$fp = fopen($filename, "r");
    if ($fp) {
        $data = fread($fp, filesize($filename));
        fclose($fp);
        $xmlResult = xml_parse($xml_parser, $data);
        $xmlError = xml_error_string(xml_get_error_code($xml_parser));
        $xmlCrtline = xml_get_current_line_number($xml_parser);
        xml_parser_free($xml_parser);
        if ($xmlResult) {
            return('opmlok');
        } else {
            return("Error parsing subscriptions file <br />error: $xmlError at line: $xmlCrtline");
        } 
    } else {
        return('Error opening subscriptions file !');
    } 
} 

function rssStartElement($parser, $name, $zf_attrs)
{
    global $zf_tag, $zf_rss;
    if ($name == 'RSS') {
        $zf_rss = '^RSS';
    } elseif ($name == 'RDF:RDF') {
        $zf_rss = '^RDF:RDF';
    } 
    $zf_tag .= '^' . $name;
} 

function rssEndElement($parser, $name)
{
    global $zf_tag, $zf_itemCount, $zf_items;
    if ($name == 'ITEM') {
        $zf_itemCount++;
        if (!isset($zf_items[$zf_itemCount])) $zf_items[$zf_itemCount] = array('title' => '', 'link' => '', 'desc' => '', 'pubdate' => '');
    } 
    $tagpos = strrpos($zf_tag, '^');
    $zf_tag = substr($zf_tag, 0, $tagpos);
} 

function rssCharacterData($parser, $data)
{
    global $zf_tag, $zf_chanTitle, $zf_chanLink, $zf_chanDesc, $zf_rss, $zf_imgTitle, $zf_imgLink, $zf_imgUrl;
    global $zf_items, $zf_itemCount;
    $rssChannel = '';
    if ($data) {
        if ($zf_tag == $zf_rss . '^CHANNEL^TITLE') {
            $zf_chanTitle .= $data;
        } elseif ($zf_tag == $zf_rss . '^CHANNEL^LINK') {
            $zf_chanLink .= $data;
        } elseif ($zf_tag == $zf_rss . '^CHANNEL^DESCRIPTION') {
            $zf_chanDesc .= $data;
        } 
        if ($zf_rss == '^RSS') $rssChannel = '^CHANNEL';

        if ($zf_tag == $zf_rss . $rssChannel . '^ITEM^TITLE') {
            $zf_items[$zf_itemCount]['title'] .= $data;
        } elseif ($zf_tag == $zf_rss . $rssChannel . '^ITEM^LINK') {
            $zf_items[$zf_itemCount]['link'] .= $data;
        } elseif ($zf_tag == $zf_rss . $rssChannel . '^ITEM^DESCRIPTION' || $zf_tag == $zf_rss . $rssChannel . '^ITEM^CONTENT:ENCODED') {
            $zf_items[$zf_itemCount]['desc'] .= $data;
        } elseif ($zf_tag == $zf_rss . $rssChannel . '^ITEM^PUBDATE' || $zf_tag == $zf_rss . $rssChannel . '^ITEM^DC:DATE') {
            $zf_items[$zf_itemCount]['pubdate'] .= $data;
        } elseif ($zf_tag == $zf_rss . $rssChannel . '^IMAGE^TITLE') {
            $zf_imgTitle .= $data;
        } elseif ($zf_tag == $zf_rss . $rssChannel . '^IMAGE^LINK') {
            $zf_imgLink .= $data;
        } elseif ($zf_tag == $zf_rss . $rssChannel . '^IMAGE^URL') {
            $zf_imgUrl .= $data;
        } 
    } 
} 

function parseRssFile($filename, $showedItems, $rssUrl, $crtFeed = -1)
{
    global $zf_tag, $zf_chanTitle, $zf_chanLink, $zf_chanDesc, $zf_rss, $zf_items, $zf_itemCount, $zf_imgTitle, $zf_imgLink, $zf_imgUrl;
    $zf_chanTitle = '';
    $zf_chanLink = '';
    $zf_chanDesc = '';
    $zf_imgTitle = '';
    $zf_imgLink = '';
    $zf_imgUrl = '';
    $zf_tag = '';
    $zf_rss = '';
    $data = '';
    $zf_itemCount = 0;
    $zf_items = array(0 => array('title' => '', 'link' => '', 'desc' => '', 'pubdate' => ''));

    $xml_parser = xml_parser_create();
    xml_set_element_handler($xml_parser, "rssStartElement", "rssEndElement");
    xml_set_character_data_handler($xml_parser, "rssCharacterData");

    @$fp = fopen($filename, "r");
    if ($fp) {
        $data = fread($fp, filesize($filename));
        fclose($fp);
        $xmlResult = xml_parse($xml_parser, $data);
        $xmlError = xml_error_string(xml_get_error_code($xml_parser));
        $xmlCrtline = xml_get_current_line_number($xml_parser);
        xml_parser_free($xml_parser);
        if ($xmlResult) {
            displayData($showedItems, $rssUrl, $crtFeed);
        } elseif (ZF_DISPLAYERROR == 'yes') {
            $zf_chanTitle = $rssUrl;
            $zf_chanLink = $rssUrl;
            $zf_items[0]['desc'] = "Error parsing this feed !<br />Error: $xmlError , at line: $xmlCrtline";
            displayData(1, $rssUrl);
        } 
    } elseif (ZF_DISPLAYERROR == 'yes') {
        $zf_chanTitle = $rssUrl;
        $zf_chanLink = $rssUrl;
        $zf_items[0]['desc'] = 'Error while retriving/saving this feed !';
        displayData(1, $rssUrl);
    } 
} 

function templateExists($templateFile, $echoExit = false)
{
    if (!file_exists($templateFile)) {
        $x = false;
        if ($echoExit == true) {
            echo '<strong>Error: template file could not be read.<br />Make sure template exist and is readable and define it in the script ...</strong>';
            exit;
        } 
    } else {
        $x = true;
    } 
    return($x);
} 

function loadTemplate($templateFile)
{
    $html = '';
    $htmlData = '';
    $temp = file($templateFile);
    foreach($temp as $i => $htmlData) {
        $html .= $htmlData;
    } 
    return($html);
} 

function splitTemplate($html, $delimiter)
{
    $startPos = strpos($html, '<!-- ' . $delimiter . ' -->');
    $endPos = strpos($html, '<!-- END' . $delimiter . ' -->');
    if ($startPos == false || $endPos == false) {
        echo '<strong>Error: template file could not be formatted.<br />Make sure the template respects the zFeeder format and no comments were deleted...</strong>';
        exit;
    } 
    return(substr($html, $startPos, ($endPos - $startPos)));
} 

function parseTemplate($html, $feedUrl, $i = -1)
{
    global $zf_items, $zf_chanTitle, $zf_chanLink, $zf_chanDesc, $zf_imgTitle, $zf_imgLink, $zf_imgUrl, $zf_showedPositions, $zf_path, $zf_category;

    $feedFile = url2file($feedUrl);
    $lastUpdated = gmdate("D, d M Y H:i:s \G\M\T", filemtime($zf_path . ZF_CACHEDIR . '/' . $feedFile));
    $hideUrl = '';
    $moreUrl = '';

    if (isset($_GET['zfposition']) && $_GET['zfposition'] != '') $zf_showedPositions = $_GET['zfposition'];
	$hideUrl = $_SERVER['PHP_SELF'] . '?zfposition=' . str_replace("p$i,", '', $zf_showedPositions);
    $moreUrl = $_SERVER['PHP_SELF'] . '?zfmore=' . $i;  //$feedFile;
    if (isset($_GET['zftemplate']) && $_GET['zftemplate']!='') {
        $moreUrl .= '&amp;zftemplate=' . $_GET['zftemplate'];
        $hideUrl .= '&amp;zftemplate=' . $_GET['zftemplate'];
    } 
    if (isset($_GET['zfcategory']) && $_GET['zfcategory']!='') {
	    $moreUrl .= '&amp;zfcategory='.$zf_category;
	    $hideUrl .= '&amp;zfcategory='.$zf_category;
	}
	$moreUrl .= '&amp;zfposition=' . $zf_showedPositions . '#zfchannel' . $i;

    if ($zf_imgUrl != '') {
        $html = str_replace('{chanlogo}', "<a href=\"" . $zf_imgLink . "\"><img src=\"" . $zf_imgUrl . "\" border=\"0\" alt=\"[logo]\" title=\"" . $zf_imgTitle . "\" /></a>", $html);
    } else {
        $html = str_replace('{chanlogo}', '', $html);
    } 
    $html = str_replace('{chanlink}', $zf_chanLink, $html);
    $html = str_replace('{chandesc}', $zf_chanDesc, $html);
    $html = str_replace('{chantitle}', $zf_chanTitle, $html);
    $html = str_replace('{feedurl}', $feedUrl, $html);
    $html = str_replace('{lastupdated}', $lastUpdated, $html);
    $html = str_replace('{scripturl}', ZF_URL, $html);
    $html = str_replace('{hideurl}', $hideUrl, $html);
    $html = str_replace('{category}', $zf_category, $html);
    if ($i != -1) {
        $html = str_replace('{id}', $i, $html);
        $html = str_replace('{link}', htmlspecialchars($zf_items[$i]['link']), $html);
        $html = str_replace('{pubdate}', $zf_items[$i]['pubdate'], $html);
        $html = str_replace('{title}', $zf_items[$i]['title'], $html);
        $html = str_replace('{description}', $zf_items[$i]['desc'], $html);
	    $html = str_replace('{moreurl}', $moreUrl, $html);
    } 
    return($html);
} 

function displayData($showedItems, $feedAddr, $crtFeed = -1)
{
    global $zf_items, $zf_html;

    $htmlHeader = splitTemplate($zf_html, 'header');
    $htmlChannel = splitTemplate($zf_html, 'channel');
    $htmlNews = splitTemplate($zf_html, 'news');
    $htmlFooter = splitTemplate($zf_html, 'footer');
    $htmlBetween = splitTemplate($zf_html, 'between');

    // echo "<div id=\"zfchannel" . $crtFeed . "\" class=\"zfchannel\">";
    echo $htmlHeader;
    if (ZF_CHANONEBAR == 'yes' && ZF_CHANLOCATION == 'top') {
        echo parseTemplate($htmlChannel, $feedAddr, $crtFeed);
    } 
    for($i = 0;$i < count($zf_items)-1;$i++) {
        if (ZF_CHANLOCATION == 'top' && ZF_CHANONEBAR != 'yes') {
            echo parseTemplate($htmlChannel, $feedAddr);
        } 
        echo parseTemplate($htmlNews, $feedAddr, $i);
        if (ZF_CHANLOCATION == 'bottom' && ZF_CHANONEBAR != 'yes') {
            echo parseTemplate($htmlChannel, $feedAddr);
        } 
        if (($i + 2 > $showedItems) && (!isset($_GET['zfmore']) || $_GET['zfmore'] != $crtFeed )) {  //url2file($feedAddr))) {
            break;
        } 
    } 
    if (ZF_CHANONEBAR == 'yes' && ZF_CHANLOCATION == 'bottom') {
        echo parseTemplate($htmlChannel, $feedAddr, $crtFeed);
    } 
    echo $htmlFooter;
    echo $htmlBetween;
    // echo '</div>';
} 

function fetchFeed($from, $to)
{
    @ini_set("user_agent","zfeeder/".ZF_VER." (http://zvonnews.sf.net)");
    @$fp = fopen($from, "r");
    $data = '';
    while (true) {
        @$datas = fread($fp, 4096);
        if (strlen($datas) == 0) {
            break;
        } 
        $data .= $datas;
    } 
    @fclose($fp);

    if ($data != '') {
        $fp = fopen($to, "w");
        fwrite($fp, $data);
        fclose($fp);
        @chmod($to, 0766);
        return($from . " - cached <br />\n");
    } else {
        return($from . " - NOT cached; check connection <br />\n");
    } 
} 

function url2file($url)
{
    return(ereg_replace("[^[:alnum:]]", "_", $url) . '.xml');
} 

function timeExpired($file, $refreshTime)
{
    if ($refreshTime < 1) $refreshTime = 1;
    $futime = filemtime($file);
    if (time() > ($futime + ($refreshTime * 60))) {
        return(true);
    } else {
        return(false);
    } 
} 

function refreshFeed($feedUrl, $feedPath, $refreshTime)
{
    if (!file_exists($feedPath) || timeExpired($feedPath, $refreshTime) == true) {
        $result = fetchFeed($feedUrl, $feedPath);
        return($result);
    } else {
        return($feedUrl . " - not expired yet <br />\n");
    } 
} 

function program_end()
{
	if (!isset($_GET['zf_link']) || $_GET['zf_link']!='off') {
	    echo "<span style=\"font-family: Verdana, Arial, Helvetica, sans-serif; font-size: xx-small;\">powered by <a href=\"http://zvonnews.sourceforge.net\">zFeeder</a></span>";	    	}
} 

function addFeed($feedUrl, $showedItems, $refreshTime)
{
    global $zf_feeds;
    $i = count($zf_feeds);
    $zf_feeds[$i]['url'] = $feedUrl;
    $zf_feeds[$i]['items'] = $showedItems;
    $zf_feeds[$i]['refreshtime'] = $refreshTime;
} 

?>