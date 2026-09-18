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

function lastupdated($feedurl)
{
    global $zfpath;
    $storedfile = $zfpath . ZF_CACHEDIR . '/' . ereg_replace("[^[:alnum:]]", "_", $feedurl) . '.xml';
    if (file_exists($storedfile)) {
        return gmdate("D, d M Y H:i:s \G\M\T", filemtime($storedfile));
    } else {
        return 'not cached yet';
    } 
} 

function displaydata()
{
    global $items, $itemcount;

    if ($itemcount > 0) {
        $htmldata = <<<EOD
		<tr align="center" valign="top" bgcolor="#F3F3F3"> 
		  <td height="51"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif"> 
			<input type="checkbox" name="deletebox{i}" value="checkbox" style="border-style:groove;"></input> 
			</font></td>
		  <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif"> 
			<input name="position{i}" type="text" size="3" value="{position}" style="border-style:groove;"></input> 
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
				<td><font color="#999999" size="2" face="Verdana, Arial, Helvetica, sans-serif">last 
				  updated on : 
				  <i>{lastupdate}</i></font></td>
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
        foreach($items as $i => $item) {
            if ($item['xmlurl'] != '') {
                $sorteditems[$item['position']] = $item;
            } 
        } 
        sort($sorteditems);
		$items = $sorteditems;

        $returndata = '';
        $i = 0;
        foreach($sorteditems as $key => $item) {
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
            $tempdata = str_replace("{lastupdate}", lastupdated($item['xmlurl']), $tempdata);
            $returndata .= $tempdata;
            $i++;
        } 
    } else $returndata = "no subscriptions in subscriptions list";
    return $returndata;
} 

// main
$opmldata = parse_opmlfile($opmlfilename);
if($opmldata=='opmlok') $opmldata=displaydata();
if ($_POST['save'] == 'save changes') {
    if (strstr($opmldata, "Error parsing subscriptions file") == false && strstr($opmldata, "Error opening subscriptions file") == false) {
        set_magic_quotes_runtime(0);
        @$fp = fopen($opmlfilename, "r");
        if ($fp) {
            $totalpos = 0;
            $temparr1 = array();
            $temparr2 = array();
            foreach($_POST as $name => $value) {
                if (substr($name, 0, 8) == 'position' && is_numeric($value)) {
                    $totalpos++;
                    $temparr1[] = $value;
                } 
            } 
            fclose($fp);
            $temparr2 = array_unique($temparr1);
            if ($temparr1 != $temparr2) {
                die("Error: duplicate positions. please go back and fix it.");
            } else {
                unset($temparr1, $temparr2);
            } 
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
                for($i = 0;$i < $totalpos;$i++) {
                    if ($_POST["position$i"] > 0 && is_numeric($_POST["position$i"])) $temppos = $_POST["position$i"];
                    else $temppos = 0;
                    if ($_POST["subscribed$i"] == 'yes' || $_POST["subscribed$i"] == 'no') $tempsub = $_POST["subscribed$i"];
                    else $tempsub = 'no';
                    if (is_numeric($_POST["refreshtime$i"])) $temprefresh = $_POST["refreshtime$i"];
                    else $temprefresh = '1440';
                    if (is_numeric($_POST["showeditems$i"]) && $_POST["showeditems$i"] > -1) $tempshowed = $_POST["showeditems$i"];
                    else $tempshowed = 0;
                    $temptitle = stripslashes($_POST["chantitle$i"]);
                    $tempdesc = stripslashes($_POST["description$i"]);
                    $templang = stripslashes($_POST["language$i"]);
                    $temphtmlurl = stripslashes($_POST["htmlurl$i"]);
                    $tempxmlurl = stripslashes($_POST["xmlurl$i"]);
                    fwrite($fp, "\t\t<outline type=\"rss\" position=\"" . $temppos . "\" text=\"" . specialchars($temptitle) . "\" title=\"" . specialchars($temptitle) . "\" description=\"" . specialchars($tempdesc) . "\" xmlUrl=\"" . specialchars($tempxmlurl) . "\" htmlUrl=\"" . specialchars($temphtmlurl) . "\" refreshTime=\"" . $temprefresh . "\" showedItems=\"" . $tempshowed . "\" isSubscribed=\"" . $tempsub . "\" language=\"" . $templang . "\" />\n");
                } 
                fwrite($fp, "\t</body>\n</opml>");
                echo "Subscription list modified.";
                fclose($fp);
            } else {
                echo "Error opening the subscription list for writing !";
            } 
        } else {
            echo "Error opening the subscription list for reading !";
        } 
    } 
} elseif ($_POST['delete'] == 'delete selected') {
    if (strstr($opmldata, "Error parsing subscriptions file") == false && strstr($opmldata, "Error opening subscriptions file") == false) {
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
            for($i = 0;$i < $itemcount;$i++) {
                if ($_POST["deletebox$i"] != 'checkbox') {
                    $item = $items[$i];
                    fwrite($fp, "\t\t<outline type=\"rss\" position=\"" . $item['position'] . "\" text=\"" . specialchars($item['title']) . "\" title=\"" . specialchars($item['title']) . "\" description=\"" . specialchars($item['description']) . "\" xmlUrl=\"" . specialchars($item['xmlurl']) . "\" htmlUrl=\"" . specialchars($item['htmlurl']) . "\" refreshTime=\"" . $item['refreshtime'] . "\" showedItems=\"" . $item['showeditems'] . "\" isSubscribed=\"" . $item['issubscribed'] . "\" language=\"" . $item['language'] . "\" />\n");
                } 
            } 
            fwrite($fp, "\t</body>\n</opml>");
            echo "Deleted selected items from subscription list (" . $opmlfilename . ")";
            fclose($fp);
        } else {
            echo "Error opening the subscription list for writing !";
        } 
    } 
} else {
?>
	  <br>
	  <form name="zfcategories" action="<?php echo $_SERVER['PHP_SELF'] . '?zfaction=subscriptions';?>" method="post">
	  <table width="740" align="center" border="0" cellspacing="3" cellpadding="0">
		<tr align="center" bgcolor="#E1E3E8"> 
			<td>
				<font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp; 
				Category: &nbsp;
    		    <select name="zfcategory" style="border-style:groove">
				<?php
					echo listCateg($crtcategory);
				?>
		        </select> 
        		&nbsp;   
		        <input name="changecateg" type="submit" id="changecateg" value="select" style="border-style:groove">
				</font>
			</td>
		</tr>
	</table>
	</form>
<?php
    if ($itemcount > 0) {
?>
		<script language="JavaScript">
		function doNow()
		{
		  void(d=document);
		  void(el=d.getElementsByTagName('INPUT'));
		  for(i=0;i<el.length;i++)
			{ if(document.subscriptionsform.checkboxall.checked==1) void(el[i].checked=1)
			else void(el[i].checked=0)
			}
		}
		</script>
	  <form name="subscriptionsform" action="<?php echo $_SERVER['PHP_SELF'] . '?zfaction=subscriptions';?>" method="post">
	  <table width="740" align="center" border="0" cellspacing="3" cellpadding="0">
		<tr align="center" bgcolor="#E1E3E8"> 
		  <td width="24">&nbsp;<input type="checkbox" name="checkboxall" value="checkbox" title="check/uncheck all" onClick="Javascript:doNow()" style="border-style:groove"></td>
		  <td width="23"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">position</font></td>
		  <td width="70"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">subscribed</font></td>
		  <td width="436"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">channel</font></td>
		  <td width="71"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">refresh 
			<font color="#006699">(minutes)</font> </font></td>
		  <td width="55"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">showed 
			news </font></td>
		</tr>
		<?php echo $opmldata;?>
		<tr bgcolor="#E1E3E8"> 
		  <td colspan="6">&nbsp;</td>
		</tr>
		<tr bgcolor="#E1F0FF"> 
		  <td colspan="6"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif"> 
			<input name="delete" type="submit" id="delete" value="delete selected" style="border-style:groove;"></input> 
			&nbsp; 
			<input name="save" type="submit" id="save" value="save changes" style="border-style:groove;"></input> 
			<input name="zfcategory" type="hidden" id="zfcategory" value="<?php echo $_POST['zfcategory'];?>"></input> 
			</font></td>
		</tr>
	  </table>
	</form>
<?php } else echo $opmldata;
} ?>
