<?php
	function listCategories()
	{	$data='';
		$handle = opendir('newsfeeds/categories'); 
		while($dirfile = readdir($handle)) { 
			if (is_file('newsfeeds/categories/'.$dirfile) && substr($dirfile,strlen($dirfile)-4,strlen($dirfile))=='opml' ) {
				$categf=substr($dirfile,0,strlen($dirfile)-5);
				if(isset($_GET['zfcategory']) && $categf==$_GET['zfcategory']) echo "<a href=\"".$_SERVER['PHP_SELF']."?zfcategory=$categf\"><span class=\"style1\">$categf</span></a><br>";
				else echo "<a href=\"".$_SERVER['PHP_SELF']."?zfcategory=$categf\">$categf</a><br>";
			}
		} 
		closedir($handle); 
		return $data;
	}
?>

<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html>
<head>
<title>zFeeder multiple categories demo</title>
<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1">
<link rel="stylesheet" href="newsfeeds/templates/css/infojunkie.css" type="text/css">
<style type="text/css">
a:link {color: blue; text-decoration:none; font-family:Geneva, Arial, Helvetica, sans-serif}
a:visited {color: #5F5FB8; text-decoration:none; font-family:Geneva, Arial, Helvetica, sans-serif}
a:hover {color: #EE7A06; text-decoration:underline; font-family:Geneva, Arial, Helvetica, sans-serif}
a:active {color:#EE7A06; text-decoration:none; font-family:Geneva, Arial, Helvetica, sans-serif}
.style1 {
	color:#336699;
	font-family: Geneva, Arial, Helvetica, sans-serif;
	font-weight: bold;
}
</style>
</head>

<body>
<table width="90%"  border="0" cellspacing="10">
  <tr align="center">
    <td colspan="2" class="style1">zFeeder - your personal web aggregator</td>
  </tr>
  <tr>
    <td width="16%" align="right" valign="top">
	<?php
		listCategories();
	?>
	</td>
    <td width="84%" align="left" valign="top">
	<?php
		$_GET['zftemplate']='infojunkie';
		include('newsfeeds/zfeeder.php');
	?>
	</td>
  </tr>
  <tr>
    <td>&nbsp;</td>
    <td>&nbsp;</td>
  </tr>
</table>
</body>
<?php 
	echo "<script language=\"javascript\">";
	echo "SwitchChannel({$_GET['zfmore']});";
	echo "</script>";
?>
</html>
