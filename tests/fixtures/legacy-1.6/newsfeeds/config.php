<?php
// zFeeder 1.6 - copyright (c) 2004 Andrei Besleaga
// configuration file


// general configuration options //

define("ZF_LOGINTYPE","server"); // server - server HTTP auth; session - PHP sessions auth
define("ZF_URL",""); // URL to zFeeder newsfeeds directory installation; ends with /
define("ZF_ADMINNAME",""); // admin username
define("ZF_ADMINPASS","d41d8cd98f00b204e9800998ecf8427e"); // crypted admin password, default is none (empty)


// feeds options //

define("ZF_REFRESHKEY",""); // if empty the refresh will be online (default), otherwise this is the keyword if you want to refresh offline by passing 'zfrefresh=yourkeyword' as an argument to the GET request
define("ZF_USEOPML","yes"); // if yes the subscription file will be used, else the manual feed configuration
define("ZF_OPMLDIR","categories"); // name of the directory where the subscriptions files are kept
define("ZF_CATEGORY","zfeeder"); // name of the default OPML file in the subscriptions directory which holds the subscriptions data
define("ZF_CACHEDIR","cache"); // cache directory
define("ZF_OWNERNAME",""); // owner name which will appear in the OPML file (optional)
define("ZF_OWNEREMAIL",""); // owner email which will appear in the OPML file (optional)


// general display options //

define("ZF_TEMPLATE","templates/bluelogos"); // the default templates used to display the news (subdirectory name from templates directory)
define("ZF_DISPLAYERROR","no"); // if yes then when a feed cannot be read (or has errors) formatted error message shows in {description}
define("ZF_CHANLOCATION","top"); // top or bottom. If something else then channel (channel template) will not be loaded and displayed
define("ZF_CHANONEBAR","yes"); // if yes then only one channel bar is shown for all it's news (not for each news)


//////END OF CONFIGURATION///////////////////////////////////////////////////

define("ZF_VER","1.6"); // current zFeeder version - do not modify
?>