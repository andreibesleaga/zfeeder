<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<title>zFeeder Demo</title>
<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1" />
<style type="text/css">
<!--
a:link {color: blue}
a:visited {color: #5F5FB8}
a:hover {color: #EE7A06}
a:active {color: blue}

.style2 {
	font-size: small;
	font-family: Verdana, Arial, Helvetica, sans-serif;
	color: #00CC00;
}
.style3 {color: #FF9900}
.style4 {
	font-size: x-small;
	font-family: Verdana, Arial, Helvetica, sans-serif;
	font-style: italic;
	color: #006699;
}
.style5 {color: #009900}
-->
</style>
</head>

<body>
<div id="Layer1" style="position:absolute; left:95px; top:27px; width:312px; height:38px; z-index:1"><span class="style2"><span class="style5">Welcome
  to</span> <span class="style3">zFeeder</span> <span class="style5">Demo Page</span></span><br />
<span class="style4">just a preview of what you can do ...&nbsp;&nbsp;</span><a href="newsfeeds/admin.php">admin</a></div>
<div id="Layer2" style="position:absolute; left:42px; top:98px; width:400px; height:209px; z-index:2"><?php $_GET['zfposition']='p1,p2'; $_GET['zftemplate']='greenlogos'; include('newsfeeds/zfeeder.php');?></div>
<div id="Layer3" style="position:absolute; left:450px; top:57px; width:277px; height:102px; z-index:3"><?php $_GET['zfposition']='p4'; $_GET['zftemplate']='simplegray'; $_GET['zf_link']='off'; include('newsfeeds/zfeeder.php');?></div>
<div id="Layer4" style="position:absolute; left:432px; top:284px; width:216px; height:112px; z-index:4"><?php $_GET['zfposition']='p3'; $_GET['zftemplate']='headlinebox'; $_GET['zf_link']='off'; include('newsfeeds/zfeeder.php');?></div>
</body>
</html>
