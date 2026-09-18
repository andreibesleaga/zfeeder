<?php 
// zFeeder 1.6 - copyright (c) 2004 Andrei Besleaga
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
//
// You should have received a copy of the GNU General Public License
// along with this program; if not, write to the Free Software
// Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.

if (zfAuth()==false) exit;

function zfurl()
{
    $refer = $_SERVER['HTTP_REFERER'];
    if (isset($refer) && $refer != '') {
        if (strpos($refer, "?") > 0) {
            return substr($refer, 0, strpos($refer, "?"));
        } else {
            return $refer;
        } 
    } else {
        return false;
    } 
} 

function parse_feed($feedurl, $htmldata)
{
    global $tag, $chantitle, $chanlink, $chandesc, $rss, $tag, $imgtitle, $imglink, $imgurl, $chanlang;
    global $rssver, $chancopy, $chanwebmaster, $chanlastbuild, $chanttl, $chanlicense;
    $chantitle = "";
    $chanlink = "";
    $chandesc = "";
    $imgtitle = "";
    $imglink = "";
    $imgurl = "";
    $chanlang = "";
    $tag = "";
    $data = "";
    $rss = "";
    $rssver = "";
    $chancopy = "";
    $chanwebmaster = "";
    $chanlastbuild = "";

    $xml_parser = xml_parser_create();
    xml_set_element_handler($xml_parser, "startElement", "endElement");
    xml_set_character_data_handler($xml_parser, "characterData");

    @$fp = fopen($feedurl, "r");
    if ($fp) {
        $data = "";
        while (true) {
            @$datas = fread($fp, 4096);
            if (strlen($datas) == 0) {
                break;
            } 
            $data .= $datas;
        } 
        @fclose($fp);
        $xmlresult = xml_parse($xml_parser, $data);
        $xmlerror = xml_error_string(xml_get_error_code($xml_parser));
        $xmlcrtline = xml_get_current_line_number($xml_parser);
        xml_parser_free($xml_parser);
        if ($xmlresult) {
            if ($imgurl != '') $chanimg = "<a href=\"$imglink\"><img src=\"$imgurl\" title=\"$imgtitle\" border=\"0\" /></a>";
            else $chanimg = '';
            $htmldata = str_replace("{formaction}", $_SERVER['PHP_SELF'] . '?zfaction=addnew', $htmldata);
            $htmldata = str_replace("{feedurl}", $feedurl, $htmldata);
            $htmldata = str_replace("{htmlurl}", $chanlink, $htmldata);
            $htmldata = str_replace("{chanimg}", $chanimg, $htmldata);
            $htmldata = str_replace("{chantitle}", $chantitle, $htmldata);
            $htmldata = str_replace("{chanlicense}", $chanlicense, $htmldata);
            $htmldata = str_replace("{chandesc}", $chandesc, $htmldata);
            $htmldata = str_replace("{chanlink}", $chanlink, $htmldata);
            $htmldata = str_replace("{chanlang}", $chanlang, $htmldata);
            $htmldata = str_replace("{rssver}", $rssver, $htmldata);
            $htmldata = str_replace("{chancopy}", $chancopy, $htmldata);
            $htmldata = str_replace("{chanwebmaster}", $chanwebmaster, $htmldata);
            $htmldata = str_replace("{chanlastbuild}", $chanlastbuild, $htmldata);
            if ($chanttl != '') $htmldata = str_replace("{chanttl}", $chanttl, $htmldata);
            else $htmldata = str_replace("{chanttl}", "60", $htmldata);
            return $htmldata;
        } else {
            return "Error parsing feed $feedurl.<br>error: $xmlerror at line: $xmlcrtline.";
        } 
    } else {
        return "Error reading the remote feed $feedurl...";
    } 
} 


function startElement($parser, $name, $attrs)
{
    global $tag, $rss, $rssver;
    if ($name == 'RSS') {
        $rss = '^RSS';
        $rssver = $attrs['VERSION'];
    } elseif ($name == 'RDF:RDF') {
        $rss = '^RDF:RDF';
        $rssver = "1.0 (RDF)";
    } 
    $tag .= '^' . $name;
} 

function endElement($parser, $name)
{
    global $tag;
    $tagpos = strrpos($tag, '^');
    $tag = substr($tag, 0, $tagpos);
} 

function characterData($parser, $data)
{
    global $tag, $chantitle, $chanlink, $chandesc, $rss, $imgtitle, $imglink, $imgurl, $chanlang;
    global $chancopy, $chanwebmaster, $chanlastbuild, $chanttl, $chanlicense;
    $rsschannel = "";
    if ($data) {
        if ($tag == $rss . '^CHANNEL^TITLE') {
            $chantitle .= $data;
        } elseif ($tag == $rss . '^CHANNEL^LINK') {
            $chanlink .= $data;
        } elseif ($tag == $rss . '^CHANNEL^DESCRIPTION') {
            $chandesc .= $data;
        } elseif ($tag == $rss . '^CHANNEL^LANGUAGE' || $tag == $rss . '^CHANNEL^DC:LANGUAGE') {
            $chanlang .= $data;
        } elseif ($tag == $rss . '^CHANNEL^COPYRIGHT' || $tag == $rss . '^CHANNEL^DC:RIGHTS') {
            $chancopy .= $data;
        } elseif ($tag == $rss . '^CHANNEL^WEBMASTER') {
            $chanwebmaster .= $data;
        } elseif ($tag == $rss . '^CHANNEL^LASTBUILDDATE' || $tag == $rss . '^CHANNEL^DC:DATE') {
            $chanlastbuild .= $data;
        } elseif ($tag == $rss . '^CHANNEL^TTL') {
            $chanttl .= $data;
        } elseif ($tag == $rss . '^CHANNEL^CREATIVECOMMONS:LICENSE') {
            $chanlicense .= $data;
        } 

        if ($rss == '^RSS') $rsschannel = '^CHANNEL';
        if ($tag == $rss . $rsschannel . "^IMAGE^TITLE") {
            $imgtitle .= $data;
        } elseif ($tag == $rss . $rsschannel . "^IMAGE^LINK") {
            $imglink .= $data;
        } elseif ($tag == $rss . $rsschannel . "^IMAGE^URL") {
            $imgurl .= $data;
        } 
    } 
} 

function getRSSLocation($html, $location)
{
    if (!$html or !$location) {
        return false;
    } else {
        preg_match_all('/<link\s+(.*?)\s*\/?>/si', $html, $matches);
        $links = $matches[1];
        $final_links = array();
        $link_count = count($links);
        for($n = 0; $n < $link_count; $n++) {
            $attributes = preg_split('/\s+/s', $links[$n]);
            foreach($attributes as $attribute) {
                $att = preg_split('/\s*=\s*/s', $attribute, 2);
                if (isset($att[1])) {
                    $att[1] = preg_replace('/([\'"]?)(.*)\1/', '$2', $att[1]);
                    $final_link[strtolower($att[0])] = $att[1];
                } 
            } 
            $final_links[$n] = $final_link;
        } 
        for($n = 0; $n < $link_count; $n++) {
            if (strtolower($final_links[$n]['rel']) == 'alternate') {
                if (strtolower($final_links[$n]['type']) == 'application/rss+xml') {
                    $href = $final_links[$n]['href'];
                } 
                if (!$href and strtolower($final_links[$n]['type']) == 'text/xml') {
                    $href = $final_links[$n]['href'];
                } 
                if ($href) {
                    if (strstr($href, "http://") !== false) {
                        $full_url = $href;
                    } else {
                        $url_parts = parse_url($location);
                        $full_url = 'http://' . $url_parts['host'];
                        if (isset($url_parts['port'])) {
                            $full_url .= ':' . $url_parts['port'];
                        } 
                        if ($href{0} != '/') {
                            $full_url .= dirname($url_parts['path']);
                            if (substr($full_url, -1) != '/') {
                                $full_url .= '/';
                            } 
                        } 
                        $full_url .= $href;
                    } 
                    return $full_url;
                } 
            } 
        } 
        return false;
    } 
} 

$siteurl = $_REQUEST['siteurl'];
$feedurl = $_REQUEST['feedurl'];

$htmldata = <<<EOD
<br><br>
<form name="subform" action="{formaction}" method="post">
  <table width="600" border="0" align="center" cellpadding="0" cellspacing="0">
    <tr align="left" valign="top"> 
      <td ><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">Feed 
        URL :</font></td>
      <td height="16"><font size="2" face="Verdana, Arial, Helvetica, sans-serif"><a href="{feedurl}">{feedurl}</a></font> 
        <input type="hidden" name="feedurl" value="{feedurl}" /></td>
      <td>{chanimg}</td>
    </tr>
    <tr align="left" valign="top"> 
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">Site 
        URL :</font></td>
      <td colspan="2"><font size="2" face="Verdana, Arial, Helvetica, sans-serif"><a href="{chanlink}">{chanlink}</a></font> 
        <input type="hidden" name="htmlurl" value="{htmlurl}" /></td>
    </tr>
    <tr align="left" valign="top"> 
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">Language 
        :</font></td>
      <td colspan="2"><font size="2" face="Verdana, Arial, Helvetica, sans-serif">- 
        {chanlang}</font> <input type="hidden" name="chanlang" value="{chanlang}" /></td>
    </tr>
    <tr align="left" valign="top"> 
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">RSS 
        version :</font></td>
      <td colspan="2"><font size="2" face="Verdana, Arial, Helvetica, sans-serif">- 
        {rssver}</font></td>
    </tr>
    <tr align="left" valign="top"> 
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">Copyright 
        :</font></td>
      <td colspan="2"><font size="2" face="Verdana, Arial, Helvetica, sans-serif">- 
        {chancopy}</font></td>
    </tr>
    <tr align="left" valign="top"> 
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">License 
        : </font></td>
      <td colspan="2"><font size="2" face="Verdana, Arial, Helvetica, sans-serif">- 
        {chanlicense} </font></td>
    </tr>
    <tr align="left" valign="top"> 
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">WebmasterEmail :</font></td>
      <td colspan="2"><font size="2" face="Verdana, Arial, Helvetica, sans-serif">- 
        {chanwebmaster}</font></td>
    </tr>
    <tr align="left" valign="top"> 
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">Last 
        build date :</font></td>
      <td colspan="2"><font size="2" face="Verdana, Arial, Helvetica, sans-serif">- 
        {chanlastbuild}</font></td>
    </tr>
    <tr align="left" valign="top"> 
      <td>&nbsp;</td>
      <td colspan="2">&nbsp;</td>
    </tr>
    <tr align="left" valign="top"> 
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">Title 
        :</font></td>
      <td colspan="2"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif"> 
        <input name="chantitle" type="text" id="chantitle" size="60" value="{chantitle}" style="border-style:groove">
        </font></td>
    </tr>
    <tr align="left" valign="top"> 
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">Description 
        :</font></td>
      <td colspan="2"> <input name="chandesc" type="text" id="chandesc" size="60" value="{chandesc}" style="border-style:groove"></td>
    </tr>
    <tr align="left" valign="top"> 
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp;</font></td>
      <td colspan="2"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp;</font></td>
    </tr>
    <tr align="left" valign="top"> 
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">Refresh 
        time :</font></td>
      <td colspan="2"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif"> 
        <input name="refreshtime" type="text" id="refreshtime" size="4" value="{chanttl}" style="border-style:groove">
        <font color="#006699">(minutes) </font></font></td>
    </tr>
    <tr align="left" valign="top"> 
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">Showed 
        news :</font></td>
      <td colspan="2"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif"> 
        <input name="showednews" type="text" id="showednews" size="4" value="1" style="border-style:groove">
        </font></td>
    </tr>
    <tr align="left" valign="top"> 
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">Enabled :</font></td>
      <td colspan="2"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif"> 
        <select name="issubscribed" id="issubscribed" style="border-style:groove">
          <option value="yes" selected>yes</option>
          <option value="no">no</option>
        </select>
        </font></td>
    </tr>
    <tr align="left" valign="top"> 
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp;</font></td>
      <td colspan="2"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp;</font></td>
    </tr>
    <tr align="center" valign="top"> 
      <td colspan="3"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp;</font><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp; 
		<a href="http://feedvalidator.org/check.cgi?url={feedurl}" target="_blank">Validate feed at feedvalidator.org</a>
        </font></td>
    </tr>
    <tr align="left" valign="top"> 
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp;</font></td>
      <td colspan="2"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp;</font></td>
    </tr>
    <tr align="center" valign="top"> 
      <td colspan="3"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp;</font><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp; category: 
        <select name="zfcategory" style="border-style:groove">
EOD;
$htmldata .= listCateg(ZF_CATEGORY);
$htmldata .= <<<EOD
        </select> &nbsp;   
        <input name="subscribe" type="submit" id="subscribe" value="add to subscription list" style="border-style:groove">
        </font></td>
    </tr>
  </table>
</form>
<br><br>
EOD;


if (isset($_POST['subscribe']) && $_POST['subscribe'] == 'add to subscription list') {
    $xmlurl = stripslashes($_POST['feedurl']);
    $htmlurl = stripslashes($_POST['htmlurl']);
    $chanlang = stripslashes($_POST['chanlang']);
    $chantitle = stripslashes($_POST['chantitle']);
    $chandesc = stripslashes($_POST['chandesc']);
    $refreshtime = $_POST['refreshtime'];
    $showeditems = $_POST['showednews'];
    $issubscribed = $_POST['issubscribed'];
    if ($refreshtime < 0) $refreshtime = "60";
    if ($showeditems < 0) $showeditems = "0";
    $opml_result = parse_opmlfile($opmlfilename);
    if ($opml_result == 'opmlok') {
        set_magic_quotes_runtime(0);
        @$fp = fopen($opmlfilename, "w");
        if ($fp) {
            if (ZF_OWNERNAME != '') $ownername = "\n\t\t<ownerName>" . specialchars(ZF_OWNERNAME) . "</ownerName>\n";
            else $ownername = '';
            if (ZF_OWNEREMAIL != '') $owneremail = "\n\t\t<ownerEmail>" . specialchars(ZF_OWNEREMAIL) . "</ownerEmail>\n";
            else $ownername = '';
            $dateModified = "\n\t\t<dateModified>" . gmdate("D, d M Y H:i:s \G\M\T") . "</dateModified>\n";

            fwrite($fp, "<?xml version=\"1.0\"?>\n<!-- subscription list generated by zFeeder " . ZF_VER . " on " . gmdate("D, d M Y H:i:s \G\M\T") . " -->\n");
            fwrite($fp, "<opml version=\"1.0\">\n\t<head>\n\t\t<title>" . $crtcategory . "</title>" . $dateModified . $ownername . $owneremail . "\t</head>\n");
            fwrite($fp, "\t<body>\n");
            $lastpos = 0;
            foreach($items as $i => $item) {
                if ($item['position'] > $lastpos) $lastpos = $item['position'];
                fwrite($fp, "\t\t<outline type=\"rss\" position=\"" . $item['position'] . "\" text=\"" . specialchars($item['title']) . "\" title=\"" . specialchars($item['title']) . "\" description=\"" . specialchars($item['description']) . "\" xmlUrl=\"" . specialchars($item['xmlurl']) . "\" htmlUrl=\"" . specialchars($item['htmlurl']) . "\" refreshTime=\"" . $item['refreshtime'] . "\" showedItems=\"" . $item['showeditems'] . "\" isSubscribed=\"" . $item['issubscribed'] . "\" language=\"" . $item['language'] . "\" />\n");
            } 
            fwrite($fp, "\t\t<outline type=\"rss\" position=\"" . ($lastpos + 1) . "\" text=\"" . specialchars(strip_tags($chantitle)) . "\" title=\"" . specialchars(strip_tags($chantitle)) . "\" description=\"" . specialchars($chandesc) . "\" xmlUrl=\"" . specialchars(strip_tags($xmlurl)) . "\" htmlUrl=\"" . specialchars(strip_tags($htmlurl)) . "\" refreshTime=\"" . $refreshtime . "\" showedItems=\"" . $showeditems . "\" isSubscribed=\"" . $issubscribed . "\" language=\"" . $chanlang . "\" />\n");
            fwrite($fp, "\t</body>\n</opml>");
            echo "Added to subscription list (" . $opmlfilename . ")";
            fclose($fp);
        } else {
            echo "Error opening the subscription list for writing !";
        } 
    } else {
        echo "Error parsing the subscriptions list : " . $opml_result . "\nFeed was NOT added.";
    } 
} elseif (isset($siteurl) && $siteurl != '') {
    @$fp = fopen($siteurl, "r");
    $sitehtmldata = "";
    while (true) {
        @$datas = fread($fp, 4096);
        if (strlen($datas) == 0) {
            break;
        } 
        $sitehtmldata .= $datas;
    } 
    @fclose($fp);
    if ($sitehtmldata != '') {
        $rssloc = getRSSlocation($sitehtmldata, $siteurl);
        if ($rssloc != false) echo parse_feed($rssloc,$htmldata);
        else echo "Autodiscovery didn't detected any feeds. If the site has them add them manually from the Feed URL form.";
    } else echo "Error: could not read the specified URL";
} elseif (isset($feedurl) && $feedurl != '') {
    $tempfeedurl = explode("|", $feedurl);
    foreach($tempfeedurl as $xmlurl) echo parse_feed($xmlurl,$htmldata);
} else {
?>

<table width="600" height="284" border="0" align="center" cellpadding="0" cellspacing="0">
  <tr> 
    <td height="284" align="center" valign="top"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif"><br>
      <br>
      <strong>RSS Autodiscovery</strong><br>
      <br>
<form name="form1" action="<?php echo $_SERVER['PHP_SELF'] . '?zfaction=addnew'?>" method="post">
      Site URL : 
      <input name="siteurl" type="text" size="40" style="border-style:groove">
      <input type="submit" name="submitsiteurl" value="go" style="border-style:groove">
      </form>
      </font><font face="Verdana, Arial, Helvetica, sans-serif"><font color="#006699" size="2">(ex: 
      http://zvonnews.sf.net)</font><br>
      <br>
      </font>
      <hr align="center" width="400" size="1" noshade>
      <font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif"> 
      <br>
      <strong>or RSS address</strong><br>
      <br>
      <form name="form2" action="<?php echo $_SERVER['PHP_SELF'] . '?zfaction=addnew'?>" method="post">
        Feed URL : 
        <input name="feedurl" type="text" size="40" style="border-style:groove">
        <input type="submit" name="submitfeedurl" value="go" style="border-style:groove">
      </form>
      <font color="#006699">(ex: http://zvonnews.sf.net/news/rss.php)</font><br>
      <br>
      </font>
      <hr align="center" width="400" size="1" noshade>
      <font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif"><br>
<?php
    if (zfurl() != false) {
        echo "<strong>or drag this bookmarklet : </strong><a href=\"javascript:els=document.getElementsByTagName('link');feeds='';for(i=0;i<els.length;i++){ty=(els[i].getAttribute('type')||'').toLowerCase();url=els[i].getAttribute('href');if(url&&(ty=='application/rss+xml'||ty=='text/xml')){if(window.confirm('Add : '+url+' to your zFeeder enabled site ?')){feeds+=url+'|';}}};if(!feeds){window.alert('No subscriptions.');}else{feeds=feeds.substr(0,feeds.length-1);window.location='" . zfurl() . "?zfaction=addnew&feedurl='+feeds;}\" title=\"Subscribe with zFeeder\">ShowOnMySite</a> to your browser links toolbar.<br>";
        echo "<br>Whenever you visit a site and you want to add it's syndicated content to your site,<br>click &quot;ShowOnMySite&quot; button on your links toolbar, and it will try to find the site's RSS feed and auto-subscribe your site to it.";
        echo "<br><br>(note: whenever changing the location of zFeeder on your webhost you must delete &quot;ShowOnMySite&quot; bookmark from your toolbar and come here and drag and drop again)</font><br><br><br>";
    } 

?> 
      </font></td>
  </tr>
</table>

<?php } ?>