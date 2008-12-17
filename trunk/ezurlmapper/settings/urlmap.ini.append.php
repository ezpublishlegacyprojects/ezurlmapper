<?php /* #?ini charset="utf-8"?

[URLMigrationSettings]

##
#  OneToOneRedirections[<source-url>]=nodeID|url
#    * source-url : the string representing the source URL in the original system
#    * nodeID     : numeric value representing the target nodeID
#    * url        : string representing the target URL in eZ Publish. It can be a url alias or a system URL
#
# WARNING : the order of the following elements matters here.
#           they must be created in this order, to keep the same precedence in the target URL-redirection system.
OneToOneRedirections[]
OneToOneRedirections[news]=7373

##
#  WildcardRedirections[<source-url>]=nodeID|url
#    * source-url : the string representing the source URL in the original system
#    * nodeID     : numeric value representing the target nodeID
#    * url        : string representing the target URL in eZ Publish. It can be a url alias or a system URL, re-using the wildcards pinpointed in the source-url
#
# WARNING : the order of the following elements matters here.
#           they must be created in this order, to keep the same precedence in the target URL-redirection system.
WildcardRedirections[]

WildcardRedirections[news/event*]=7410

WildcardRedirections[products*]=60
WildcardRedirections[products/category/hardware*]=17163

WildcardRedirections[site/map*]=content/view/sitemap

# Useful for eZ Find :)
WildcardRedirections[searchsomecontents/*]=content/search?SearchText={1}

*/ ?>
