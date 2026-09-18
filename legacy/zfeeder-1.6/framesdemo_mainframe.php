<html>

<head>
	<title>zFeeder News</title>
	<META NAME="ROBOTS" CONTENT="NOINDEX, NOFOLLOW">
	<link href="newsfeeds/templates/css/mainframe.css" rel="stylesheet">
</head>

<body>

	<?php
	require_once('newsfeeds/config.php');
	if (!isset($_GET['zfposition']) || $_GET['zfposition'] == '') {$zfposition = 1;} else {$zfposition=$_GET['zfposition'];}
	if (!isset($_GET['zfcategory']) || $_GET['zfcategory'] == '') {$zfcategory=ZF_CATEGORY;} else {$zfcategory=$_GET['zfcategory'];}
	$_GET['zfposition']=$zfposition; 
	$_GET['zfcategory']=$zfcategory; 
	$_GET['zftemplate']="mainframe"; 
	include('newsfeeds/zfeeder.php'); 
	?>

</body>

</html>