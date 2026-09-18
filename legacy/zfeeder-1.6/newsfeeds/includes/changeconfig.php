<?php 
// zFeeder 1.6 - copyright (c) 2004 Andrei Besleaga
// http://zvonnews.sourceforge.net

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


if(zfAuth()==false) exit;
elseif(!is_writable($zfpath.'config.php')) echo "<font size=\"2\" face=\"Verdana, Arial, Helvetica, sans-serif\"><br><center>config.php is not writable (you cannot save changes) !</center></font>";

function zfurl()
{
	$refer=$_SERVER['HTTP_REFERER'];
	if( isset($refer) && $refer!='') {
				return substr($refer,0,strrpos($refer,"/")+1);
	} else {
		return false; 
	}
}

if($_POST['dosave']=='Save settings')
{
	@$fp=fopen($zfpath.'config.php','w');
	if($fp) {	
		if($_POST['newpassword']==$_POST['confirmpassword'] && $_POST['newpassword']!='') $adminpassword=md5($_POST['newpassword']); else $adminpassword=ZF_ADMINPASS;
		fwrite($fp,"<?php\n// zFeeder ".ZF_VER." - copyright (c) 2004 Andrei Besleaga\n");
		fwrite($fp,"// configuration file\n\n\n");
		fwrite($fp,"// general configuration options //\n\n");
		fwrite($fp,"define(\"ZF_LOGINTYPE\",\"".$_POST['zflogintype']."\"); // server - server HTTP auth; session - PHP sessions auth\n");
		fwrite($fp,"define(\"ZF_URL\",\"".$_POST['zfurl']."\"); // URL to zFeeder directory installation; ends with /\n");
		fwrite($fp,"define(\"ZF_ADMINNAME\",\"".$_POST['adminname']."\"); // admin username\n");
		fwrite($fp,"define(\"ZF_ADMINPASS\",\"".$adminpassword."\"); // crypted admin password, default is none (empty)\n");
		fwrite($fp,"\n\n// feeds options //\n\n");
		fwrite($fp,"define(\"ZF_REFRESHKEY\",\"".$_POST['refreshkey']."\"); // if empty the refresh will be online (default), otherwise this is the keyword if you want to refresh offline by passing 'zfrefresh=yourkeyword' as an argument to the GET request\n");
		fwrite($fp,"define(\"ZF_USEOPML\",\"".$_POST['usesubs']."\"); // if yes the subscription file will be used, else the manual feed configuration\n");
		fwrite($fp,"define(\"ZF_OPMLDIR\",\"".ZF_OPMLDIR."\"); // // name of the directory where the subscriptions files are kept\n");		
		fwrite($fp,"define(\"ZF_CATEGORY\",\"".$_POST['subfilename']."\"); // name of the default OPML file in the subscriptions directory which holds the subscriptions data\n");
		fwrite($fp,"define(\"ZF_CACHEDIR\",\"".$_POST['cachedir']."\"); // cache directory\n");
		fwrite($fp,"define(\"ZF_OWNERNAME\",\"".$_POST['ownername']."\"); // owner name which will appear in the OPML file (optional)\n");
		fwrite($fp,"define(\"ZF_OWNEREMAIL\",\"".$_POST['owneremail']."\"); // owner email which will appear in the OPML file (optional)\n");
		fwrite($fp,"\n\n// general display options //\n\n");
		fwrite($fp,"define(\"ZF_TEMPLATE\",\"templates/".$_POST['template']."\"); // the default templates used to display the news (subdirectory name from templates directory)\n");
		fwrite($fp,"define(\"ZF_DISPLAYERROR\",\"".$_POST['displayerror']."\"); // if yes then when a feed cannot be read (or has errors) formatted error message shows in {description}\n");
		fwrite($fp,"define(\"ZF_CHANLOCATION\",\"".$_POST['channellocation']."\"); // top or bottom. If something else then channel (channel template) will not be loaded and displayed\n");
		fwrite($fp,"define(\"ZF_CHANONEBAR\",\"".$_POST['channelbar']."\"); // if yes then only one channel bar is shown for all it's news (not for each news)\n");
		fwrite($fp,"\n\n//////END OF CONFIGURATION///////////////////////////////////////////////////\n\n");
		fwrite($fp,"define(\"ZF_VER\",\"".ZF_VER."\"); // current zFeeder version - do not modify\n?>");
		fclose($fp);
		echo "<font size=\"2\" face=\"Verdana, Arial, Helvetica, sans-serif\"><br><center>settings saved ...</center><br></font>";
	}
}
else
{
?>

<table width="700" border="0" align="center" cellpadding="0" cellspacing="5">
  <form name="configform" action="<?php echo $_SERVER['PHP_SELF'].'?zfaction=config';?>" method="post">
    <tr valign="top"> 
      <td colspan="2" align="center"><font color="#CC3300" size="2" face="Verdana, Arial, Helvetica, sans-serif"><br>
        notes:</font><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif"> 
        - take care when changing options (don't change what you don't know for 
        sure)<br>
        <strong><font color="#000000">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</font></strong>- 
        fields marked with * are required, anything else is optional and can be 
        empty</font></td>
    </tr>
    <tr valign="top"> 
      <td colspan="2" align="center">&nbsp;</td>
    </tr>
    <tr valign="top"> 
      <td colspan="2" align="center"><strong><font color="#000000" size="2" face="Verdana, Arial, Helvetica, sans-serif">General 
        configuration</font></strong></td>
    </tr>
    <tr valign="top"> 
      <td colspan="2" align="center"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp; 
        </font></td>
    </tr>
    <tr valign="top"> 
      <td align="right"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">* 
        zFeeder URL :</font></td>
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp; 
        <input name="zfurl" type="text" size="50" value="<?php if(zfurl()==false) {if(ZFURL!='') echo ZFURL;} else echo zfurl(); ?>" style="border-style:groove;" />
        </font></td>
    </tr>
    <tr valign="top"> 
      <td align="right"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">admin 
        username :</font></td>
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp; 
        <input name="adminname" type="text" id="adminname" style="border-style:groove;"  value="<?php echo ZF_ADMINNAME;?>"></input> 
        </font></td>
    </tr>
    <tr valign="top"> 
      <td height="22" align="right"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">admin 
        new password :<br>
        </font></td>
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp; 
        <input type="password" name="newpassword" id="newpassword" style="border-style:groove;">
        </input> 
        <font color="#006699" size="1">(leave empty if you don't want to change 
        pass)</font></font></td>
    </tr>
    <tr valign="top"> 
      <td align="right"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif"> 
        admin new password confirm :</font></td>
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp; 
        <input type="password" name="confirmpassword" id="confirmpassword" style="border-style:groove;">
        </input> 
        </font></td>
    </tr>
    <tr valign="top"> 
      <td align="right"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">* 
        Admin panel login mechanism :</font></td>
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp;
        <select name="zflogintype" id="zflogintype"  style="border-style:groove;">
          <option value="server" <?php if(ZF_LOGINTYPE=='server') echo 'selected';?>>server</option>
          <option value="session" <?php if(ZF_LOGINTYPE=='session') echo 'selected';?>>session</option>
          <option value="disabled" <?php if(ZF_LOGINTYPE!='session' && ZF_LOGINTYPE!='server') echo 'selected';?>>no panel</option>
        </select>
        </font></td>
    </tr>
    <tr valign="top"> 
      <td colspan="2" align="right"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp;</font><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp;</font></td>
    </tr>
    <tr valign="top"> 
      <td colspan="2" align="center"><strong><font color="#000000" size="2" face="Verdana, Arial, Helvetica, sans-serif">News 
        feeds options</font></strong></td>
    </tr>
    <tr valign="top">
      <td align="right">&nbsp;</td>
      <td>&nbsp;</td>
    </tr>
    <tr valign="top"> 
      <td align="right"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">* 
        Use subscription file :</font></td>
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp;
        <select name="usesubs" id="usesubs"  style="border-style:groove;">
          <option value="yes" selected <?php if(ZF_USEOPML=='yes') echo 'selected';?>>yes</option>
          <option value="no" <?php if(ZF_USEOPML!='yes') echo 'selected';?>>no</option>
        </select>
        </font></td>
    </tr>
    <tr valign="top"> 
      <td height="22" align="right"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">*
           default subscription filename :<br>
        </font></td>
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp; 
          <select name="subfilename" id="subfilename"  style="border-style:groove;">
			<?php
				echo listCateg(ZF_CATEGORY);
			?>
        </select>        
        </input> 
        <font color="#006699" size="1">(the default subscriptions category)</font></font></td>
    </tr>
    <tr valign="top"> 
      <td height="22" align="right"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">* 
        cache dirname :<br>
        </font></td>
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp; 
        <input name="cachedir" type="text" id="cachedir" value="<?php echo ZF_CACHEDIR;?>" style="border-style:groove;"></input> 
        <font color="#006699" size="1">(where RSS files are kept)</font> </font></td>
    </tr>
    <tr valign="top">
      <td align="right"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">refresh key :</font></td>
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp;
            <input name="refreshkey" id="refreshkey" type="text" style="border-style:groove;" value="<?php echo ZF_REFRESHKEY;?>"></input>
            <font color="#006699" size="1">(if empty the feeds will be refreshed online)</font>        </font></td>
    </tr>
    <tr valign="top"> 
      <td align="right"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">feed 
        list owner name :</font></td>
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp; 
        <input name="ownername" type="text" id="ownername" style="border-style:groove;"></input> 
        </font></td>
    </tr>
    <tr valign="top"> 
      <td align="right"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif"> 
        feed list owner email :</font></td>
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp; 
        <input name="owneremail" type="text" id="owneremail" style="border-style:groove;"></input> 
        </font></td>
    </tr>
    <tr valign="top"> 
      <td height="18" align="right"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp;</font></td>
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp;</font></td>
    </tr>
    <tr valign="top"> 
      <td colspan="2" align="center"><strong><font color="#000000" size="2" face="Verdana, Arial, Helvetica, sans-serif">General 
        display options</font></strong></td>
    </tr>
    <tr valign="top"> 
      <td align="right"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp;</font></td>
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp;</font></td>
    </tr>
    <tr valign="top"> 
      <td align="right"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">* 
        template used to display news :</font></td>
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp; 
        <select name="template" id="template" style="border-style:groove;">
          <?php		
				$handle = opendir($zfpath."templates"); 
				while($dirfile = readdir($handle)) { 
					if( is_file($zfpath.'templates/'.$dirfile) && substr($dirfile,strlen($dirfile)-4,strlen($dirfile))=='html' && substr($dirfile,0,4)!='wap_' ) {
					 	$templatef=substr($dirfile,0,strlen($dirfile)-5);
						if(ZF_TEMPLATE=='templates/'.$templatef) echo "<option value=\"$templatef\" selected>$templatef</option>";
					  	else echo "<option value=\"$templatef\">$templatef</option>"; 
					}
				} 
				closedir($handle); 
		 ?>
        </select>
        </font></td>
    </tr>
    <tr valign="top"> 
      <td align="right"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">channel 
        bar location :</font></td>
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp; 
        <select name="channellocation" id="channellocation" style="border-style:groove;">
          <option value="top" <?php if(ZF_CHANLOCATION=='top') echo 'selected';?>>top</option>
          <option value="bottom" <?php if(ZF_CHANLOCATION=='bottom') echo 'selected';?>>bottom</option>
          <option value="invisible" <?php if(ZF_CHANLOCATION=='invisible') echo 'selected';?>>invisible</option>
        </select>
        </font></td>
    </tr>
    <tr valign="top"> 
      <td align="right"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">one 
        channel bar per feed :</font></td>
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp; 
        <select name="channelbar" id="channelbar" style="border-style:groove;">
          <option value="yes" <?php if(ZF_CHANONEBAR=='yes') echo 'selected';?>>yes</option>
          <option value="no" <?php if(ZF_CHANONEBAR!='yes') echo 'selected';?>>no</option>
        </select>
        </font></td>
    </tr>
    <tr valign="top"> 
      <td height="24" align="right"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">display 
        error :<font color="#006699"><br>
        </font></font></td>
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp; 
        <select name="displayerror" id="displayerror" style="border-style:groove;">
          <option value="yes" <?php if(ZF_DISPLAYERROR=='yes') echo 'selected';?>>yes</option>
          <option value="no" <?php if(ZF_DISPLAYERROR!='yes') echo 'selected';?>>no</option>
        </select>
        <font color="#006699"> <font size="1">(if feed cannot be retrived)</font></font> 
        </font></td>
    </tr>
    <tr valign="top"> 
      <td align="right">&nbsp;</td>
      <td>&nbsp;</td>
    </tr>
    <tr valign="top"> 
      <td colspan="2" align="center"> <font size="2" face="Verdana, Arial, Helvetica, sans-serif"> 
        <input type="submit" name="dosave" id="dosave" value="Save settings" style="border-style:groove;"></input> 
        </font></td>
    </tr>
    <tr valign="top"> 
      <td align="right"><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp;</font></td>
      <td><font color="#000066" size="2" face="Verdana, Arial, Helvetica, sans-serif">&nbsp;</font></td>
    </tr>
  </form>
</table>
<br>
<?php } ?>