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

header("Content-type: text/vnd.wap.wml");
echo "<?xml version=\"1.0\"?>";

error_reporting (E_ALL ^ E_NOTICE);
$zf_path = str_replace("\\", "/", dirname(__FILE__)) . "/";
require_once($zf_path . 'config.php');

function listCategories()
{	$data='';
	$handle = opendir(ZF_OPMLDIR); 
	while($dirfile = readdir($handle)) { 
		if (is_file(ZF_OPMLDIR.'/'.$dirfile) && substr($dirfile,strlen($dirfile)-4,strlen($dirfile))=='opml' ) {
			$categf=substr($dirfile,0,strlen($dirfile)-5);
			echo "<a href=\"".$_SERVER['PHP_SELF']."?zfcategory=$categf\" title=\"$categf\">$categf</a><br/>";
		}
	} 
	closedir($handle); 
	return $data;
}
?>

<!DOCTYPE wml PUBLIC "-//WAPFORUM//DTD WML 1.1//EN" 
  "http://www.wapforum.org/DTD/wml_1.1.xml"> 
  <wml> 
	<?php if (isset($_GET['zfposition']) && $_GET['zfposition']!='') {

		$_GET['zftemplate']='wap_sources'; 
		$_GET['zf_link']='off';
		echo "<card id=\"zfchannel".$_GET['zfposition']."\" title=\"RSS News\">";
		include('zfeeder.php'); 

	} elseif (isset($_GET['zfcategory']) && $_GET['zfcategory']!='') { 

		$_GET['zftemplate']='wap_categ'; 
		$_GET['zf_link']='off';
		echo "<card id=\"".$_GET['zfcategory']."\" title=\"Category: ".$_GET['zfcategory']."\">";
		echo "<p>Select news source:</p>";
		include('zfeeder.php'); 

	} else { ?>

	<card id="zFeeder" title="RSS News">
	<p align="center">
		Select a news category:<br/>
		<?php listCategories();?>
		<br/><small>powered by <img align="middle" alt="zFeeder" src="images/zflogo.wbmp"/></small><br/>
	</p>
	<?php } ?>
	
	<do type="prev" label="Back"><prev/></do>
	</card>
  </wml>