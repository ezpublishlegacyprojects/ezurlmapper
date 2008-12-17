#!/usr/bin/env php
<?php
//
// Created on: <15-Dec-2008 16:51:17 nfrp, jr>
//
// SOFTWARE NAME: eZ Publish
// SOFTWARE RELEASE: 4.0.1rc1
// BUILD VERSION: 21995
// COPYRIGHT NOTICE: Copyright (C) 1999-2008 eZ Systems AS
// SOFTWARE LICENSE: GNU General Public License v2.0
// NOTICE: >
//   This program is free software; you can redistribute it and/or
//   modify it under the terms of version 2.0  of the GNU General
//   Public License as published by the Free Software Foundation.
//
//   This program is distributed in the hope that it will be useful,
//   but WITHOUT ANY WARRANTY; without even the implied warranty of
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//   GNU General Public License for more details.
//
//   You should have received a copy of version 2.0 of the GNU General
//   Public License along with this program; if not, write to the Free
//   Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
//   MA 02110-1301, USA.
//
//

//include_once( 'lib/ezutils/classes/ezcli.php' );
//include_once( 'kernel/classes/ezscript.php' );
require 'autoload.php';

$cli = eZCLI::instance();
$script = eZScript::instance( array( 'description'       => ( "RBA. Create the various URL redirections required.\n" .
                                                              "This script maps part of the former website's URLs to new ones.\n" .
                                                              "\n" .
                                                              "./extension/rba/bin/php/create_url_redirections.php" ),
                                     'use-session'       => true,
                                     'use-modules'       => true,
                                     'use-extensions'    => true ) );

try
{
    $urlMapper = new URLMapper( $script, $cli );
    $urlMapper->run();
    // var_dump( $urlMapper );
}
catch ( Exception $e )
{
    $cli->error( $e->getMessage() );
}

$script->shutdown();

class URLMapper
{
    private $script, $cli, $urlINI;
    private $rules;

    public function __construct( eZScript $script, eZCLI $cli )
    {
        $this->script = $script;
        $this->script->startup();
        $this->Options = $this->script->getOptions( "[only-for-apache][only-for-ez]",
                                                    "",
                                                    array( 'only-for-apache' => "Only creates the Apache rewrite rules, does not create the redirection rules in eZ Publish.",
                                                           'only-for-ez'     => "Only create the redirection rules in eZ Publish, does not create the Apache rewrite rules."
                                                         ));
        $this->script->initialize();
        $this->cli = $cli;
        $this->urlINI = eZINI::instance( 'urlmap.ini' );
        $this->getRules();
    }

    private function getRules()
    {
        $this->rules['one-to-one'] = $this->urlINI->hasVariable( 'URLMigrationSettings', 'OneToOneRedirections' ) ? $this->urlINI->variable( 'URLMigrationSettings', 'OneToOneRedirections' ) : array();
        $this->rules['wildcard'] = $this->urlINI->hasVariable( 'URLMigrationSettings', 'WildcardRedirections' ) ? $this->urlINI->variable( 'URLMigrationSettings', 'WildcardRedirections' ) : array();

        foreach( array_keys( $this->rules ) as $type )
        {
            foreach( array_keys( $this->rules[$type] ) as $origURL )
            {
                $target = $this->rules[$type][$origURL];
                if ( is_numeric( $target ) )
                {
                    if ( ( $node = eZContentObjectTreeNode::fetch( $target ) ) !== null )
                    {
                        $this->rules[$type][$origURL] = $node->attribute( 'url_alias' );
                    }
                    else
                    {
                        // the Node ID was invalid
                        unset( $this->rules[$type][$origURL] );
                    }
                }
            }
        }
    }

    public function run()
    {
        if ( $this->Options['only-for-apache'] )
        {
            $this->runForApache(); // run Yakari ?!
            return;
        }

        if ( $this->Options['only-for-ez'] )
        {
            $this->runForeZ(); // run Forrest ?!
            return;
        }

        $this->runForApache();
        $this->runForeZ();
    }

    private function runForApache()
    {
        $this->cli->warning( "Generating RewriteRules for Apache." );

        $siteAccessURL = '';
        $ini = eZINI::instance();
        if( $ini->variable( 'SiteAccessSettings', 'MatchOrder' ) == 'uri' )
        {
            // adding the correct siteacces name
            $siteAccessURL = $this->Options['siteaccess'] . '/';
        }

        $rrSuffix   = ' [R=301,L]';

        $rrList   = array();

        foreach( $this->rules['one-to-one'] as $originURL => $targetURL )
        {
            $rrList[] = 'RewriteRule ^/' . $siteAccessURL . $originURL . '$ /' . $siteAccessURL . $targetURL . $rrSuffix;
        }

        foreach( $this->rules['wildcard']   as $originURL => $targetURL )
        {
            $appendBackwardReference = false;
            if( preg_match( "#\*#", $originURL ) )
            {
                // yes, it can handle only 1 backward reference, but this
                // is enough for our needs
                $originURL = str_replace( '*', '(.*)', $originURL );
                $appendBackwardReference = true;
            }

            $currentRr = 'RewriteRule ^/' . $siteAccessURL . $originURL . '$ /' . $siteAccessURL . $targetURL;

            $pattern = '#{(\d)}#';
            if( preg_match( $pattern, $currentRr ) )
            {
                // only useful for search engine in our case
                $currentRr = preg_replace( $pattern, '$\\1', $currentRr );
                $appendBackwardReference = false;
            }

            if( $appendBackwardReference )
            {
                $currentRr .= '$1';
            }

            $currentRr .= $rrSuffix;

            $rrList[] = $currentRr;
        }

        $this->cli->output( '+' . str_repeat( '-', 40 )                                   , false );
        $this->cli->output( $this->cli->stylize( 'green', ' Apache Rewrite Rules Start ' ), false );
        $this->cli->output( str_repeat( '-', 40 ) . '+'                                   , true );

        $rrString = join( chr( 10 ), $rrList );
        $this->cli->output( $rrString );

        $this->cli->output( '+' . str_repeat( '-', 40 )                                 , false );
        $this->cli->output( $this->cli->stylize( 'green', ' Apache Rewrite Rules End ' ), false );
        $this->cli->output( str_repeat( '-', 40 ) . '+'                                 , true );

        $this->cli->warning( "Done. \n" );
    }

    private function runForeZ()
    {
        $this->cli->warning( "Generating the Redirection Rules in eZ..." );

        $isAlwaysAvailable = true;
        $languages = eZContentLanguage::prioritizedLanguages();
        $language = $languages[0];
        $aliasRedirects  = true;
        $infoCode = 'no-errors'; // This will be modified if info/warning is given to user.
        $infoData = array();     // Extra parameters can be added to this array

        foreach( $this->rules['one-to-one'] as $originURL => $targetURL )
        {
            $this->cli->notice( "\tMapping '$originURL' to '$targetURL' :   " );

            $aliasText = trim( $originURL );
            $aliasDestinationTextUnmodified = $targetURL;
            $aliasDestinationText = trim( $aliasDestinationTextUnmodified, " \t\r\n\0\x0B/" );

            if ( !$language )
            {
                $infoCode = "error-invalid-language";
                $infoData['language'] = $languageCode;
            }
            else if ( strlen( $aliasText ) == 0 )
            {
                $infoCode = "error-no-alias-text";
            }
            else if ( strlen( trim( $aliasDestinationTextUnmodified ) ) == 0 )
            {
                $infoCode = "error-no-alias-destination-text";
            }
            else
            {
                $parentID = 0; // Start from the top
                $linkID   = 0;
                $mask = $language->attribute( 'id' );
                if ( $isAlwaysAvailable )
                    $mask |= 1;

                $action = eZURLAliasML::urlToAction( $aliasDestinationText );
                if ( !$action )
                {
                    $elements = eZURLAliasML::fetchByPath( $aliasDestinationText );
                    if ( count( $elements ) > 0 )
                    {
                        $action = $elements[0]->attribute( 'action' );
                        $linkID = $elements[0]->attribute( 'link' );
                    }
                }
                if ( !$action )
                {
                    $infoCode = "error-action-invalid";
                    $infoData['aliasText'] = $aliasDestinationText;
                }
                else
                {
                    $origAliasText = $aliasText;
                    if ( $linkID == 0 )
                        $linkID = true;
                    $result = eZURLAliasML::storePath( $aliasText, $action,
                                                       $language, $linkID, $isAlwaysAvailable, $parentID,
                                                       true, false, false, $aliasRedirects );
                    if ( $result['status'] === eZURLAliasML::LINK_ALREADY_TAKEN )
                    {
                        $lastElements = eZURLAliasML::fetchByPath( $result['path'] );
                        if ( count ( $lastElements ) > 0 )
                        {
                            $lastElement  = $lastElements[0];
                            $infoCode = "feedback-alias-exists";
                            $infoData['new_alias'] = $aliasText;
                            $infoData['url'] = $lastElement->attribute( 'path' );
                            $infoData['action_url'] = $lastElement->actionURL();
                            $aliasText = $origAliasText;
                        }
                    }
                    else if ( $result['status'] === true )
                    {
                        $aliasText = $result['path'];
                        if ( strcmp( $aliasText, $origAliasText ) != 0 )
                        {
                            $infoCode = "feedback-alias-cleanup";
                            $infoData['orig_alias']  = $origAliasText;
                            $infoData['new_alias'] = $aliasText;
                        }
                        else
                        {
                            $infoData['new_alias'] = $aliasText;
                        }
                        if ( $infoCode == 'no-errors' )
                        {
                            $infoCode = "feedback-alias-created";
                        }
                        $aliasText = false;
                        $aliasOutputText = false;
                        $aliasOutputDestinationText = false;
                    }
                    if ( preg_match( "#^eznode:(.+)$#", $action, $matches ) )
                    {
                        $infoData['node_id'] = $matches[1];
                    }
                }
            }

            $this->cli->notice( "\t\t" . $this->cli->stylize( 'emphasize', $infoCode ) );
        }

        $this->cli->notice( "\t------------------------------------------------------------------\n" );

        $wildcardType = true;
        $infoCode = 'no-errors'; // This will be modified if info/warning is given to user.
        $infoData = array();     // Extra parameters can be added to this array

        foreach( $this->rules['wildcard'] as $originURL => $targetURL )
        {
            $this->cli->notice( "\tMapping '$originURL' to '$targetURL' :   " );

            $wildcardSrcText = trim( $originURL );
            $wildcardDstText = trim( $targetURL );

            if ( strlen( $wildcardSrcText ) == 0 )
            {
                $infoCode = "error-no-wildcard-text";
            }
            else if ( strlen( $wildcardDstText ) == 0 )
            {
                $infoCode = "error-no-wildcard-destination-text";
            }
            else
            {
                $wildcard = eZURLWildcard::fetchBySourceURL( $wildcardSrcText, false );
                if ( $wildcard )
                {
                    $infoCode = "feedback-wildcard-exists";

                    $infoData['wildcard_src_url'] = $wildcardSrcText;
                    $infoData['wildcard_dst_url'] = $wildcardDstText;
                }
                else
                {
                    $row = array(
                        'source_url'      => $wildcardSrcText,
                        'destination_url' => $wildcardDstText,
                        'type'            => $wildcardType ? eZURLWildcard::EZ_URLWILDCARD_TYPE_FORWARD : eZURLWildcard::EZ_URLWILDCARD_TYPE_DIRECT );

                    $wildcard = new eZURLWildcard( $row );
                    $wildcard->store();

                    $infoData['wildcard_src_url'] = $wildcardSrcText;
                    $infoData['wildcard_dst_url'] = $wildcardDstText;

                    $infoCode = "feedback-wildcard-created";
                }
            }

            $this->cli->notice( "\t\t" . $this->cli->stylize( 'emphasize', $infoCode ) );
        }
        eZURLWildcard::expireCache();

        $this->cli->warning( "Done. \n" );
    }
}

?>
