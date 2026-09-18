#!/usr/bin/env bash
# Re-create the zFeeder 1.6 demo used for the screenshots (original code, unpatched, on PHP 5.6 + Apache in Docker).
set -e
cd "$(dirname "$0")"
curl -sL -o zfeeder-1.6.zip https://github.com/andreibesleaga/old-projects/raw/main/zfeeder-1.6.zip
rm -rf zfrun && mkdir zfrun && unzip -q zfeeder-1.6.zip -d zfrun && mv zfrun/zfeeder-1.6/* zfrun/ && rmdir zfrun/zfeeder-1.6
cp newsfeeds/config.php zfrun/newsfeeds/config.php            # session login admin/demo2004, ZF_URL set
cp newsfeeds/categories/*.opml zfrun/newsfeeds/categories/    # live 2026 feeds instead of the dead 2004 ones
rm -f zfrun/newsfeeds/categories/lockergnome.opml
cp demo_template.php wap_source.php zfrun/                    # helper pages: template gallery + WML source viewer
chmod -R a+rwX zfrun
docker rm -f zfeeder56 >/dev/null 2>&1 || true
docker run -d --name zfeeder56 -p 127.0.0.1:8090:80 -v "$PWD/zfrun":/var/www/html php:5.6-apache
sleep 3
docker exec zfeeder56 sh -c 'printf "error_reporting = E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_STRICT\ndisplay_errors = Off\n" > /usr/local/etc/php/conf.d/zf.ini'
docker restart zfeeder56 >/dev/null
echo "zFeeder 1.6 is at http://127.0.0.1:8090/demo.php  (admin: http://127.0.0.1:8090/newsfeeds/admin.php  user admin / pass demo2004)"
echo "Screenshots: NODE_PATH=<dir with playwright> node capture.js <out-dir> <abs path to 03-project-site-2004-archive>"
