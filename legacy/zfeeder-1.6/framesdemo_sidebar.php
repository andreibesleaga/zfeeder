<?php
function listCategories()
{	
	global $cat;

	$dir=opendir('newsfeeds/categories');
	while (($file = readdir($dir)) !== false) {
		$dirlist[] = $file;
	}

	closedir($dir);
	sort($dirlist);

	echo "<div id=\"catselect\"><div id=\"sidehead\"><img src=\"newsfeeds/images/zflogo.png\" style=\"border: none; vertical-align: text-bottom;\"> the aggregator</div><div align=\"right\" style=\"font-size: 8px;\"><a target=\"_blank\" title=\"Admin Panel\" href=\"newsfeeds/admin.php\">[admin]</a></div><form name=\"categories\" method=\"get\" action=\"".$_SERVER['PHP_SELF']."\">";
	echo "<select name=\"cat\"  onChange=\"gotoCategory(this.value);\">";
	while (list($key, $val) = each($dirlist)){
		if ($val != "." && $val != "..") {
					$categf=substr($val,0,strlen($val)-5);
					if ($categf == $cat) {
					echo "<option value=\"$categf\" selected=\"selected\">$categf</option>";
					}
					else {
					echo "<option value=\"$categf\">$categf</option>";
					}
		}
	}
	echo "</select></form></div>";
}
?> 

<html>

<head>
	<title>zFeeder News</title>
	<META NAME="ROBOTS" CONTENT="NOINDEX, NOFOLLOW">
	<link href="newsfeeds/templates/css/sidebar.css" rel="stylesheet">
	<script language="JavaScript" type="text/javascript">
	<!--
	function gotoCategory(value) {
	parent.mainframe.location.href = 'framesdemo_mainframe.php?zfcategory='+value;
	document.categories.submit()
	}
	//-->
	</script>
</head>

<body scroll="no">

<?php
require_once('newsfeeds/config.php');
if (!isset($_GET['cat']) || $_GET['cat'] == '') {$cat=ZF_CATEGORY;} else {$cat=$_GET['cat'];}
listCategories(); 
$_GET['zftemplate']='sidebar'; 
$_GET['zfcategory']=$cat; 
include('newsfeeds/zfeeder.php'); 
?>


</body>

</html>