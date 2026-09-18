<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><title>zFeeder template: <?php echo htmlspecialchars($_GET['zftemplate']); ?></title>
<style>body{font-family:Verdana,Arial,Helvetica,sans-serif;font-size:12px;margin:14px;background:#fff}h3{color:#006699;background:#D0ECFD;padding:5px 8px;margin:0 0 10px 0;font-size:13px}</style></head><body>
<h3>zFeeder 1.6 &mdash; template &laquo;<?php echo htmlspecialchars($_GET['zftemplate']); ?>&raquo;, category &laquo;<?php echo htmlspecialchars(isset($_GET['zfcategory'])?$_GET['zfcategory']:'zfeeder'); ?>&raquo;</h3>
<?php include('newsfeeds/zfeeder.php'); ?>
</body></html>
