<?php 
// zFeeder 1.6 - copyright (c) 2003-2004 Andrei Besleaga
// http://zvonnews.sourceforge.net
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this program; if not, write to the Free Software
// Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.

require(dirname(__FILE__) . '/config.php');
require(dirname(__FILE__) . '/includes/adminfuncs.php');
error_reporting (E_ALL ^ E_NOTICE);
if ($_GET['zfaction'] == 'logout') zfLogout();

if(ZF_LOGINTYPE=='server')
{
	if ($_SERVER['PHP_AUTH_USER'] != ZF_ADMINNAME || md5($_SERVER['PHP_AUTH_PW']) != ZF_ADMINPASS) {
	    header("WWW-Authenticate: Basic realm=\"zFeeder Authentication\"");
	    header("HTTP/1.0 401 Unauthorized");
		zfLoginFailed();	
	} else {
	    define(ZF_ADMINLOGGED, "yes");
	}
}
elseif(ZF_LOGINTYPE=='session') 
{
	session_start(); // needed if authentication mechanism is session
	if ($_POST['submit_login'] == 'Log In!')
	{
		if ($_POST['admin_user'] != ZF_ADMINNAME || md5($_POST['admin_pass']) != ZF_ADMINPASS)
		{ 
			zfLoginFailed();	
		} else	{ 
			$_SESSION['admin_user'] = $_POST['admin_user'];	// set username
			$_SESSION['admin_pass'] = $_POST['admin_pass'];	// set password
			$_SESSION['logged_in'] = 1;
		}
	 }
	if ($_SESSION['logged_in'] != 1)
	{
		echo "<html><head><title>zFeeder Authentication</title></head><body>";
		echo "<div align=\"center\"><form action={$_SERVER['PHP_SELF']} method=\"post\">";
		echo "Username: <input type=\"text\" name=\"admin_user\"> <br/>";
		echo "Password: <input type=\"password\" name=\"admin_pass\"> <br/>";
		echo "<input type=\"submit\" name=\"submit_login\" value=\"Log In!\">";
		echo "</form></div></body></html>";
		exit;
	} else {
	    define(ZF_ADMINLOGGED, "yes");
	}
} else {
	echo '<html><head><title>zFeeder Admin Panel - auth not set</title><body><h3>Authentication mechanism not configured !</h3></body></html>';
	exit;
}

$zfpath = str_replace("\\", "/", dirname(__FILE__)) . "/";
if (isset($_POST['zfcategory']) && $_POST['zfcategory']!='' && file_exists($zf_path . ZF_OPMLDIR . '/' . $_POST['zfcategory'] . '.opml')) {
	$crtcategory=$_POST['zfcategory'];
	$opmlfilename=$zfpath . ZF_OPMLDIR . '/' . $_POST['zfcategory'] . '.opml';
} else {
	$crtcategory=ZF_CATEGORY;
	$opmlfilename=$zfpath . ZF_OPMLDIR . '/' . ZF_CATEGORY . '.opml';
}
 
ini_set("user_agent","zfeeder/".ZF_VER." (http://zvonnews.sf.net)");
?>

	<html>
	<head>
	<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1">
	<style type="text/css">
	a:link {color: blue}
	a:visited {color: #5F5FB8}
	a:hover {color: #EE7A06}
	a:active {color: blue}
    </style>
	</head>
	<title>zFeeder admin panel</title>
	<body>
	<table width="334" align="center">
	  <tr> 
    <td width="90" height="18" align="center"><font color="#006699" size="2" face="Verdana, Arial, Helvetica, sans-serif"><a href="http://zvonnews.sourceforge.net"><img src="images/zflogo.png" border="0"></a></font></td>
    <td width="229" align="left" valign="middle"><font color="#006699" size="2" face="Verdana, Arial, Helvetica, sans-serif"><strong>zFeeder 
      administration panel</strong></font></td>
	  </tr>
	</table>
	
<table width="760" border="0" align="center">
  <tr> 
    <td height="22" align="center" bgcolor="#D0ECFD"><font size="2" face="Verdana, Arial, Helvetica, sans-serif"> 
      	<a href="<?php echo $_SERVER['PHP_SELF'];?>">main</a>
	   <?php if (ZF_USEOPML == 'yes') echo " :: <a href=\"" . $_SERVER['PHP_SELF'] . "?zfaction=addnew\">add new</a>";?>
	   <?php if (ZF_USEOPML == 'yes') echo " :: <a href=\"" . $_SERVER['PHP_SELF'] . "?zfaction=subscriptions\">subscriptions</a>";?>
       :: <a href="<?php echo $_SERVER['PHP_SELF'] . '?zfaction=config';?>">config</a> 
	   <?php if (ZF_USEOPML == 'yes') echo " :: <a href=\"" . $_SERVER['PHP_SELF'] . "?zfaction=importlist\">import feed list</a>";?>
	   :: <a href="<?php echo $_SERVER['PHP_SELF'] . '?zfaction=updates';?>">updates</a>
	   :: <a href="<?php echo $_SERVER['PHP_SELF'] . '?zfaction=logout';?>">logout</a>	   
	  </font></td>
  </tr>
  <tr> 
    <td bgcolor="#F9F9F9"> 
<?php
	if ($_GET['zfaction'] == 'subscriptions') include('includes/subscriptions.php');
	elseif ($_GET['zfaction'] == 'addnew') include('includes/addnewfeed.php');
	elseif ($_GET['zfaction'] == 'importlist') include('includes/importlist.php');
	elseif ($_GET['zfaction'] == 'config') include('includes/changeconfig.php');
	elseif ($_GET['zfaction'] == 'updates') {
?>
      <table width="400" border="0" align="center">
		<tr align="left" valign="top"> 
			  <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">
<?php
    echo "<br><br>Your zFeeder version: <font color=\"#CC3300\"> " . ZF_VER . " </font><br><br>";
    @$update = readfile('http://zvonnews.sourceforge.net/latest.php');
    if (!$update) echo "Error: could not open update file.<br><br>You can check it manually at: <a href=\"http://zvonnews.sourceforge.net/latest.php\">http://zvonnews.sourceforge.net/latest.php</a>.";
    echo '<br><br>';

?>
			  <br>
			  </td>
		</tr>
	</table>
<?php } else { ?>
      <table width="450" border="0" align="center" cellpadding="0" cellspacing="5">
        <tr> 
          <td height="50" colspan="2" align="center" valign="middle"><br>
              <font size="2" face="Verdana, Arial, Helvetica, sans-serif" color="#009900">welcome <?php echo ZF_ADMINNAME;?> !</font>
              <br><br>
          </td>
        </tr>
        <tr valign="middle" bgcolor="#F3F3F3"> 
          <td width="149" height="16" align="right"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">
		  <?php if (ZF_USEOPML == 'yes') echo "<a href=\"" . $_SERVER['PHP_SELF'] . "?zfaction=addnew\">add new</a>"; else echo 'add new';?>
		   </font></td>
          <td align="left"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">- 
            add new feeds (channels)</font></td>
        </tr>
        <tr valign="middle" bgcolor="#F3F3F3"> 
          <td align="right"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">
	   <?php if (ZF_USEOPML == 'yes') echo "<a href=\"" . $_SERVER['PHP_SELF'] . "?zfaction=subscriptions\">subscriptions</a>"; else echo 'subscriptions';?>
		  </font></td>
          <td width="336" align="left"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">- 
            modify / delete news feeds</font></td>
        </tr>
        <tr valign="middle" bgcolor="#F3F3F3"> 
          <td align="right"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif"><a href="<?php echo $_SERVER['PHP_SELF'] . '?zfaction=config';?>">config</a></font></td>
          <td width="336" align="left"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">- 
            change zFeeder configuration</font></td>
        </tr>
        <tr valign="middle" bgcolor="#F3F3F3"> 
          <td height="16" align="right"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">
	 	   <?php if (ZF_USEOPML == 'yes') echo "<a href=\"" . $_SERVER['PHP_SELF'] . "?zfaction=importlist\">import feed list</a>";  else echo 'import feed list'; ?>
		  </font></td>
          <td width="336" align="left"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif"> 
            - import feeds from opml feedlist</font></td>
        </tr>
        <tr valign="middle" bgcolor="#F3F3F3"> 
          <td height="16" align="right"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif"><a href="<?php echo $_SERVER['PHP_SELF'] . '?zfaction=updates'; ?>">updates</a></font></td>
          <td width="336" align="left"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif"> 
            - check of updates on zFeeder site</font></td>
        </tr>
      </table>
      <br>
<?php } ?>
	</td>
  </tr>
  <tr>
  	<td height="20" align="center" valign="middle" bgcolor="#D0ECFD"><font size="2" face="Verdana, Arial, Helvetica, sans-serif"> 
      powered by <a href="http://zvonnews.sourceforge.net">zFeeder <?php echo ZF_VER;?></a> 
      - &copy;2003-2004 by Andrei Besleaga </font></td>
  </tr>
	</table>
</body>
</html>