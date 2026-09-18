<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><title>zFeeder WAP output</title>
<style>body{font-family:Verdana,Arial,Helvetica,sans-serif;font-size:12px;margin:14px;background:#fff}h3{color:#006699;background:#D0ECFD;padding:5px 8px;margin:0 0 10px 0;font-size:13px}pre{font:12px/1.4 monospace;background:#F7F7F7;border:1px solid #ddd;padding:10px;white-space:pre-wrap}</style></head><body>
<?php $q = isset($_GET['q']) ? $_GET['q'] : ''; $u = 'http://localhost/newsfeeds/wap.php' . ($q ? '?' . $q : ''); ?>
<h3>zFeeder 1.6 &mdash; WML (WAP) output of <?php echo htmlspecialchars('newsfeeds/wap.php' . ($q ? '?' . $q : '')); ?></h3>
<pre><?php echo htmlspecialchars(file_get_contents($u)); ?></pre>
</body></html>
