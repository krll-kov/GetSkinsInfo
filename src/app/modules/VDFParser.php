<?php
namespace app\modules;

use app;

class VDFParser {

    function _symtostr($line, $offset, $token = null) {
        if ( $token === null ) {
            $token = "\"";
        }    
        
        $opening = $offset + 1;
        $closing = $opening;
        $ci = strpos(substr($line, $opening), $token);
    
        while($ci !== false and ! app()->isShutdown() ) {
            if($line[$ci] <> "\\") {
                $closing = $ci;
                break;
            }
            $ci = strpos($line, $token, $ci + 2);
        }
        return array(substr($line, $opening, $closing), $offset + $closing + strlen($token));
    }
     
    function _unquotedtostr($line, $i) {
        $ci = $i;
        $len = strlen($line);
    
        while ( $ci < $len and ! app()->isShutdown() ) {
            if ( $line[$ci] == " " or $line[$ci] == "\t" or $line[$ci] == "\r" or $line[$ci] == "\n" ) {
                break;
            }
            $ci++;
        }
        return array(substr($line, $i, $ci - $i), $ci);
    }
     
    function _parse($str, $offset = 0) {
        $str = trim($str);
        $len = strlen($str);
        $laststr = null;
        $lasttok = null;
        $lastbrk = null;
        $i = $offset;
        $next_is_value = false;
        $deserialized = array();
    
        while ( $i < $len and ! app()->isShutdown() ) {
            $char = $str[$i];
            if ( $char == " " or $char == "\t" or $char == "\r" or $char == "\n" ) {
                $i++;
            } else {
                switch ( $char ) {
                    case "{":
                        $next_is_value = false;
                        $parsed = $this->_parse($str, $i + 1);
                        $deserialized[$laststr] = $parsed[0];
                        $i = $parsed[1];
                    break;
                    case "}":
                        return array($deserialized, $i);
                    break;
                    case "[":
                        list($lastbrk, $i) = $this->_symtostr($str, $i, "]");
                    break;
                    case "/":
                        if ( isset($str[$i + 1]) ) {
                            if ( $str[$i + 1] == "/" ) {
                                while( $str[$i] <> "\n" and ! app()->isShutdown() ) $i++;
                            }
                        }    
                    break;
                    case "\r":
                    case "\n":
                        while ( ($str[$i] == "\r") or ($str[$i] == "\n") and ! app()->isShutdown() ) ++$i;
                    break;
                    default:
                        if ( $char <> " " && $char <> "\t" ) {
                            list($string, $i) = ($char == "\"" ? $this->_symtostr($str, $i) : $this->_unquotedtostr($str, $i));
    
                            if ( $lasttok == "\"" and $next_is_value ) {
                                if ( $deserialized[$laststr] && $lastbrk !== null ) {
                                    $lastbrk = null;
                                } else { 
                                    if ( $laststr[0] == "#" ) {
                                        $deserialized[substr($laststr,1)][] = $string;
                                    } else {
                                        $deserialized[$laststr] = $string;
                                    }    
                                }    
                            }
                            $char = "\"";
                            $laststr = $string;
                            $next_is_value = !$next_is_value;
                        } else {
                            $char = $lasttok;
                        }    
                }
                $lasttok = $char;
                $i++;
            }
        }
        return array($deserialized, $i);
    }
     
    function parse($string) {
        $_parsed = $this->_parse($string);
        $res = $_parsed[0];
        $ptr = $_parsed[1];
        return $res;
    }
}