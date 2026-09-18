<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html>
<head>
<title>Untitled Document</title>
<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1">
</head>

<body>
<table width="90%"  border="1" align="center" cellpadding="1" cellspacing="5">
  <tr align="center" valign="top">
    <td width="33%"><?php $_GET['zftemplate']='simplegray'; $_GET['zfcategory']='zfeeder'; include('newsfeeds/zfeeder.php'); ?></td>
    <td width="33%"><?php $_GET['zftemplate']='ampheta'; $_GET['zfcategory']='general'; include('newsfeeds/zfeeder.php'); ?></td>
    <td width="33%"><?php $_GET['zftemplate']='simpleblue'; $_GET['zfcategory']='rss'; include('newsfeeds/zfeeder.php'); ?></td>
  </tr>
</table>
</body>
</html>
