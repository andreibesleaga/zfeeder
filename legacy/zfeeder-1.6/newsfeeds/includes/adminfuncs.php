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


function zfAuth()
{
	if (ZF_ADMINLOGGED == 'yes') {
		if (ZF_LOGINTYPE == 'server') {
			if ($_SERVER['PHP_AUTH_USER'] != ZF_ADMINNAME || md5($_SERVER['PHP_AUTH_PW']) != ZF_ADMINPASS) return false;
			else return true;
		} elseif (ZF_LOGINTYPE == 'session') {
			if ($_SESSION['admin_user'] != ZF_ADMINNAME || md5($_SESSION['admin_pass']) != ZF_ADMINPASS) return false;
			else return true;		
		}
	} else return false;	
}

function zfLoginFailed()
{
    echo "<html><head><title>Unauthorized Access</title><meta http-equiv=\"Content-Type\" content=\"text/html; charset=iso-8859-1\"></head>";
    echo "<body><table width=\"55%\" height=\"273\" border=\"0\" align=\"center\" style=\"border-style:dotted\">";
    echo "<tr><td height=\"59\" align=\"center\"><font size=\"2\" face=\"Verdana, Arial, Helvetica, sans-serif\"><img src=\"images/zflogo.png\" width=\"120\" height=\"60\"></font></td></tr>";
    echo "<tr><td height=\"49\" align=\"center\"><font color=\"#CC0000\" size=\"4\" face=\"Verdana, Arial, Helvetica, sans-serif\"><strong>ACCESS DENIED</strong></font></td></tr>";
    echo "<tr><td height=\"72\" align=\"center\"><table width=\"81%\" border=\"0\"><tr>";
    echo "<td><font color=\"#CC0000\" size=\"2\" face=\"Verdana, Arial, Helvetica, sans-serif\">Sorry, you have no access to administration area.</font></td></tr>";
    echo "</table></td></tr></table></body></html>";
    exit;
}

function zfLogout()
{
	if (ZF_LOGINTYPE == 'server') {
	    header("WWW-Authenticate: Basic realm=\"zFeeder Authentication\"");
	    header("HTTP/1.0 401 Unauthorized");
	} elseif (ZF_LOGINTYPE == 'session') {
		session_start();
		$_SESSION['logged_in'] = 0; // just in case
		$_SESSION['admin_user'] = '';
		$_SESSION['admin_pass'] = '';
		session_unset();			// kill all session globals
		session_destroy();			// kill everything
	} else {
		echo '<html><head><title>zFeeder Admin Panel - auth not set</title><body><h3>Authentication mechanism not configured !</h3></body></html>';
	}
	echo '<html><head><title>zFeeder admin logout</title><body><h3>You are logged out !</h3></body></html>';
	exit;	
}

function specialchars($input, $quotes = ENT_COMPAT)
{
    return htmlspecialchars($input, $quotes);
} 

function listCateg($category)
{	global $zfpath;
	$data='';
	$handle = opendir($zfpath.ZF_OPMLDIR); 
	while($dirfile = readdir($handle)) { 
		if (is_file($zfpath.ZF_OPMLDIR.'/'.$dirfile) && substr($dirfile,strlen($dirfile)-4,strlen($dirfile))=='opml' ) {
		 	$categf=substr($dirfile,0,strlen($dirfile)-5);
			if($category==$categf) $data .= "<option value=\"$categf\" selected>$categf</option><br>";
		  	else $data .= "<option value=\"$categf\">$categf</option><br>"; 
		}
	} 
	closedir($handle); 
	return $data;
}

function opml_startElement($parser, $name, $attrs)
{
    global $itemcount, $items;

    if ($attrs['POSITION'] != '') {
        $items[$itemcount]['position'] = $attrs['POSITION'];
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
        else $items[$itemcount]['showeditems'] = 0;
        if ($attrs['ISSUBSCRIBED'] != '') $items[$itemcount]['issubscribed'] = $attrs['ISSUBSCRIBED'];
        else $items[$itemcount]['issubscribed'] = 'no';
    } 
} 

function opml_endElement($parser, $name)
{
    global $itemcount;
    if ($name == 'OUTLINE') $itemcount++;
} 

function parse_opmlfile($filename)
{
    global $items, $itemcount;
    $items = array();
    $itemcount = 0;

    $xml_parser = xml_parser_create();
    xml_set_element_handler($xml_parser, "opml_startElement", "opml_endElement");

    @$fp = fopen($filename, "r");
    if ($fp) {
        $data = fread($fp, filesize($filename));
        fclose($fp);
        $xmlresult = xml_parse($xml_parser, $data);
        $xmlerror = xml_error_string(xml_get_error_code($xml_parser));
        $xmlcrtline = xml_get_current_line_number($xml_parser);
        xml_parser_free($xml_parser);
        if ($xmlresult) {
            return 'opmlok';
        } else {
            return "Error parsing subscriptions file $filename<br>error: $xmlerror at line: $xmlcrtline<br>";
        } 
    } else {
        return "Error opening subscriptions file $filename<br>";
    } 
} 

?>