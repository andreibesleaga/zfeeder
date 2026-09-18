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

function iopml_startElement($parser, $name, $attrs)
{
    global $itemcount, $items;

    if ($attrs['POSITION'] != '') $items[$itemcount]['position'] = $attrs['POSITION'];
    if ($attrs['TITLE'] != '') $items[$itemcount]['title'] = $attrs['TITLE'];
    elseif ($attrs['TEXT'] != '') $items[$itemcount]['title'] = $attrs['TEXT'];
    else $items[$itemcount]['title'] = '';
    if ($attrs['DESCRIPTION'] != '') $items[$itemcount]['description'] = $attrs['DESCRIPTION'];
    else $items[$itemcount]['description'] = '';
    if ($attrs['XMLURL'] != '') $items[$itemcount]['xmlurl'] = $attrs['XMLURL'];
    else $items[$itemcount]['xmlurl'] = '';
    if ($attrs['HTMLURL'] != '') $items[$itemcount]['htmlurl'] = $attrs['HTMLURL'];
    else $items[$itemcount]['htmlurl'] = '';
    if ($attrs['LANGUAGE'] != '') $items[$itemcount]['language'] = $attrs['LANGUAGE'];
    else $items[$itemcount]['language'] = '';
    if ($attrs['REFRESHTIME'] != '') $items[$itemcount]['refreshtime'] = $attrs['REFRESHTIME'];
    else $items[$itemcount]['refreshtime'] = 60;
    if ($attrs['SHOWEDITEMS'] != '') $items[$itemcount]['showeditems'] = $attrs['SHOWEDITEMS'];
    else $items[$itemcount]['showeditems'] = 1;
    if ($attrs['ISSUBSCRIBED'] != '') $items[$itemcount]['issubscribed'] = $attrs['ISSUBSCRIBED'];
    else $items[$itemcount]['issubscribed'] = 'yes';
} 

function parse_iopmlfile($filename, $opmlok = false)
{
    global $items, $itemcount;
    $items = array();
    $itemcount = 0;

    $xml_parser = xml_parser_create();
    xml_set_element_handler($xml_parser, "iopml_startElement", "opml_endElement");

    @$fp = fopen($filename, "r");
    $data = "";
    while (true) {
        @$datas = fread($fp, 4096);
        if (strlen($datas) == 0) {
            break;
        } 
        $data .= $datas;
    } 
    @fclose($fp);

    if ($data != '') {
        $xmlresult = xml_parse($xml_parser, $data);
        $xmlerror = xml_error_string(xml_get_error_code($xml_parser));
        $xmlcrtline = xml_get_current_line_number($xml_parser);
        xml_parser_free($xml_parser);
        if ($xmlresult) {
            if ($opmlok == false) return displaydata();
            else return 'opmlok';
        } else {
            return "Error parsing subscriptions file $filename<br>error: $xmlerror at line: $xmlcrtline";
        } 
    } else {
        return "Error opening subscriptions file $filename";
    } 
} 

function displaydata()
{
    global $items, $itemcount;

    if ($itemcount > 0) {
        $htmldata = <<<EOD
		<tr align="center" valign="top" bgcolor="#F3F3F3"> 
		  <td height="51"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif"> 
			<input type="checkbox" name="addbox{i}" value="checkbox" style="border-style:groove;"></input> 
			</font></td>
		  <td> 
			<select name="subscribed{i}" style="border-style:groove;">
			  <option value="yes" {issubscribed}>yes</option>
			  <option value="no" {notsubscribed}>no</option>
			</select></td>
		  <td> 
			<table width="100%" border="0" cellpadding="0" cellspacing="0">
			  <tr> 
				<td width="402"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif"><a href="javascript:open('{htmlurl}')" onclick="window.open('{htmlurl}'); return false;"><img src="images/globe.png" border="0"/> {chantitle}</a></font></td>
				<td align="right" width="35"><font size="1" face="Verdana, Arial, Helvetica, sans-serif">{lang}</font></td>
			  </tr>
			  <tr> 
				<td><font color="#999999" size="2" face="Verdana, Arial, Helvetica, sans-serif">
				XML URL : <i>{xmlurl}</i><br>
				HTML URL : <i>{htmlurl}</i>
				</font></td>
				<td align="right"><font size="2" face="Verdana, Arial, Helvetica, sans-serif"><a href="javascript:open('{xmlurl}')" onclick="window.open('{xmlurl}'); return false;"><img src="images/xml.png" border="0" alt="RSS feed"></a></font></td>
			  </tr>
			  <tr> 
				<td><font size="2" face="Verdana, Arial, Helvetica, sans-serif" ><p>{description}</p></font></td>
				<td><font size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp;</font></td>
			  </tr>
			</table></td>
		  <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif"> 
			<input name="refreshtime{i}" type="text" size="4" value="{refreshtime}" style="border-style:groove;"></input> 
			</font></td>
		  <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif"> 
			<input name="showeditems{i}" type="text" size="4" value="{showeditems}" style="border-style:groove;"></input>
			</font></td>
		</tr>
			<input type="hidden" name="language{i}" value="{lang}" />
			<input type="hidden" name="description{i}" value="{description}" />		
			<input type="hidden" name="chantitle{i}" value="{chantitle}" />		
			<input type="hidden" name="xmlurl{i}" value="{xmlurl}" />		
			<input type="hidden" name="htmlurl{i}" value="{htmlurl}" />
EOD;

        $returndata = '';
        $i = 0;
        foreach($items as $key => $item) {
            $tempdata = '';
            $tempdata = str_replace("{i}", $i, $htmldata);
            $tempdata = str_replace("{chantitle}", $item['title'], $tempdata);
            $tempdata = str_replace("{htmlurl}", $item['htmlurl'], $tempdata);
            $tempdata = str_replace("{description}", $item['description'], $tempdata);
            $tempdata = str_replace("{lang}", $item['language'], $tempdata);
            $tempdata = str_replace("{xmlurl}", $item['xmlurl'], $tempdata);
            $tempdata = str_replace("{position}", $item['position'], $tempdata);
            $tempdata = str_replace("{refreshtime}", $item['refreshtime'], $tempdata);
            $tempdata = str_replace("{showeditems}", $item['showeditems'], $tempdata);
            if ($item['issubscribed'] == 'yes') {
                $tempdata = str_replace("{issubscribed}", "selected", $tempdata);
                $tempdata = str_replace("{notsubscribed}", "", $tempdata);
            } else {
                $tempdata = str_replace("{issubscribed}", "", $tempdata);
                $tempdata = str_replace("{notsubscribed}", "selected", $tempdata);
            } 
            $returndata .= $tempdata;
            $i++;
        } 
    } else $returndata = "no subscriptions in subscriptions list";
    return $returndata;
} 
// main
$opmlurl = $_REQUEST['opmlurl'];
if (isset($_REQUEST['opmlurl']) && $opmlurl != '') {
    $opmldata = parse_iopmlfile($opmlurl);

?>
	  <br>
	  <script language="JavaScript">
		function doNow()
		{
		  void(d=document);
		  void(el=d.getElementsByTagName('INPUT'));
		  for(i=0;i<el.length;i++)
			{ if(document.subscriptionsform.checkboxall.checked==1) 
				{ void(el[i].checked=1); }
			  else 
				{ void(el[i].checked=0); }
			}
		}
		</script>
	  <form name="subscriptionsform" action="<?php echo $_SERVER['PHP_SELF'] . '?zfaction=importlist';?>" method="post">
	  <table width="740" align="center" border="0" cellspacing="3" cellpadding="0">
		<tr align="center" bgcolor="#E1E3E8"> 
		  <td width="24" valign="middle"><input type="checkbox" name="checkboxall" value="checkbox" title="check/uncheck all" onClick="Javascript:doNow()" style="border-style:groove"></td>
		  <td width="70"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">subscribed</font></td>
		  <td width="436"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">channel</font></td>
		  <td width="71"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">refresh 
			<font color="#006699">(minutes)</font> </font></td>
		  <td width="55"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">showed 
			news </font></td>
		</tr>
		<?php echo $opmldata;?> 
		<tr bgcolor="#E1E3E8"> 
		  <td colspan="5">&nbsp;</td>
		</tr>
		<tr align="center" bgcolor="#E1F0FF"> 
		  <td colspan="5"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif"> &nbsp; category: 
			<select name="zfcategory" style="border-style:groove">
			<?php
				echo listCateg(ZF_CATEGORY);
			?>
			</select> 
			&nbsp;   
			<input name="save" type="submit" id="save" value="add selected feeds" style="border-style:groove;"></input> 
			</font></td>
		</tr>
	  </table>
	</form>
<?php } elseif ($_POST['save'] == 'add selected feeds') {
    $opml_result = parse_iopmlfile($opmlfilename, true);
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

            $i = 0;
            $lastpos++;
            foreach($_POST as $key => $value) {
                if ($key == "xmlurl" . $i && $value != '' && $_POST["addbox" . $i] == 'checkbox') {
                    $xmlurl = stripslashes($_POST["xmlurl" . $i]);
                    $htmlurl = stripslashes($_POST["htmlurl" . $i]);
                    $chanlang = stripslashes($_POST["language" . $i]);
                    $chantitle = stripslashes($_POST["chantitle" . $i]);
                    $chandesc = stripslashes($_POST["description" . $i]);
                    $refreshtime = $_POST["refreshtime" . $i];
                    $showeditems = $_POST["showeditems" . $i];
                    $issubscribed = $_POST["subscribed" . $i];
                    if ($refreshtime < 0) $refreshtime = "60";
                    if ($showeditems < 0) $showeditems = "0";
                    fwrite($fp, "\t\t<outline type=\"rss\" position=\"" . $lastpos . "\" text=\"" . specialchars(strip_tags($chantitle)) . "\" title=\"" . specialchars(strip_tags($chantitle)) . "\" description=\"" . specialchars($chandesc) . "\" xmlUrl=\"" . specialchars(strip_tags($xmlurl)) . "\" htmlUrl=\"" . specialchars(strip_tags($htmlurl)) . "\" refreshTime=\"" . $refreshtime . "\" showedItems=\"" . $showeditems . "\" isSubscribed=\"" . $issubscribed . "\" language=\"" . $chanlang . "\" />\n");
                    $i++;
                    $lastpos++;
                } elseif ($key == "xmlurl" . $i && $value != '' && $_POST["addbox" . $i] != 'checkbox') $i++;
            } 
            fwrite($fp, "\t</body>\n</opml>");
            echo "Added to subscription list (" . $opmlfilename . "). Please go to subscriptions panel and customize.";
            fclose($fp);
        } else {
            echo "Error opening the subscription list for writing !";
        } 
    } else {
        echo $opmlresult . "\nFeeds were NOT added.";
    } 
} else {
?>

<form method="post" name="subopml" action="<?php echo $_SERVER['PHP_SELF'] . '?zfaction=importlist';?>">
<table width="500" border="0" align="center" cellpadding="0" cellspacing="0">
  <tr> 
    <td align="center"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif"><br>
		<strong>Import OPML subscriptions</strong><br><br><br>
      Local path or remote URL of a opml subscription list<br>
      <input name="opmlurl" type="text" size="50" style="border-style:groove">
      <input name="fetchopml" type="submit" id="fetchopml" value=" go " style="border-style:groove">
      <br>
      <font color="#006699">( ex: http://zvonnews.sf.net/newsfeeds/subscriptions.opml 
      )</font><br>
      <br><br>
      (you can export your subscriptions from your desktop aggregator to opml 
      - if it supports, or you can give an url address of such subscription list)<br>
      </font></td>
  </tr>
</table>
<br><br>
</form>
<?php } ?>
