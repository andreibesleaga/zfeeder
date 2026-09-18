# zFeeder — short history

zFeeder is a PHP RSS aggregator written by Andrei N. Besleaga, started in 2003 under the name zvonFeeds and
renamed zFeeder for its 1.0 release. Hosted on SourceForge as project `zvonnews` (registered 27 June 2003),
it reached version 1.6 on 25 April 2004, where development stopped. At a time when RSS came in several
incompatible versions and there was no standard aggregator, zFeeder let any PHP page show someone else's
feed content with a single `include` line. It stored subscriptions as OPML instead of a database — shared
hosting rarely offered MySQL then — cached feeds to flat files, and rendered them through swappable
templates. It spread through SourceForge, a three-page article (with cover-CD inclusion) in the German
magazine INTERNET PROFESSIONELL in August 2004, and third-party modules for WordPress, PHP-Nuke, XHP and
MovableType. By the author's own account, zFeeder ran on more than 20,000 websites over its lifetime — his
own recollection, not an audited number. The project site eventually went offline; the SourceForge listing
and the original source survive. It is now being rebuilt as zFeeder 2.0 in this repository: the same
architecture and feature set, on a current PHP stack, with the 2004-era security holes closed.
