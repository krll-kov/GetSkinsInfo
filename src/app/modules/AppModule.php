<?php
namespace app\modules;

use windows;
use Exception;
use bundle\preg\Preg;
use std, gui, framework, app;


class AppModule extends AbstractModule
{
    private $skins = [];
    public $VDFParser;

    function __construct(){
        parent::__construct();
        new Thread(function(){while(!Application::isCreated()); $this->main();})->start();
        
        $this->VDFParser = new VDFParser;
    }
    
    /**
     * Parse line for weapon name
     * 
     * @param string $line Line to parsing
     * @param int $separate Array explode value
     * 
     * @return array Weapon tag data
     */
    private function tagWeapon($line, $separate = 2){
        return [
            # parse WEAPON_TITLE_NAME _ SKIN_NAME = LINK
            $weapon = implode( '_', ($count = count( $explode = explode( '_', $line[0]) )) > $separate ? array_slice($explode, 0, $separate) : $explode ),
            
            # if we know only weapon title, skin is NULL (default?)
            $count > $separate ? implode( '_',array_slice($explode, $separate) ) : $weapon,
            
            # link
            $line[1]
        ];
    }
    
    /**
     * @param string $pattern Skin pattern
     * @param string $names_en_en File data
     * @param string $identifiers File data
     * @param string &$id
     * @param string &$tag
     * 
     * @return bool 
     */
    private function matchData($pattern, $names_en, $names_ru, $names_ua, $identifiers, &$id, &$tag_ru, &$tag_en, &$tag_ua, &$rarity){

        if(
            ! preg_match("/\\\"([0-9]+)\\\"[^\\\"]+\\\"name\\\"\\s*?\\\"".preg_quote($pattern)."\\\"[^\\\"]+\\\"description_string\\\"\\s*?\\\"[^\\\"]+\\\"\\s*?\\\"description_tag\\\"\\s*?\\\"#([^\\\"]+)\\\"/uim", $identifiers, $kits) ||
            ! preg_match( "/^\\s*?\\\"".preg_quote($kits[2])."\\\"\\s*?\\\"([^\"]+)\\\"\\s*?$/uim", $names_en, $tags_en ) ||
            ! preg_match( "/^\\s*?\\\"".preg_quote($kits[2])."\\\"\\s*?\\\"([^\"]+)\\\"\\s*?$/uim", $names_ru, $tags_ru ) ||
            ! preg_match( "/^\\s*?\\\"".preg_quote($kits[2])."\\\"\\s*?\\\"([^\"]+)\\\"\\s*?$/uim", $names_ua, $tags_ua ) ||
            ! preg_match_all( "/^\\s*?\\\"".preg_quote($pattern)."\\\"\\s*?\\\"([^\"]+)\\\"\\s*?$/uim", $identifiers, $kitRarity );
        ) return false;
        
        
        $id = $kits[1];       
        $tag_ru = $tags_ru[1];
        $tag_en = $tags_en[1];
        $tag_ua = $tags_ua[1];
        $rarity = $kitRarity[1][1];
        
        return true;
    }
    
    private function main(){
        print "Loading files...\n";
        
        define("CSGO_PATH", $this->getGameWay());
        
        # load all files data
        $patterns    = $this->readFile( get_defined_constants()["CSGO_PATH"] . 'csgo\\scripts\\items\\items_game_cdn.txt' );
        $names_en    = str::decode( $this->readFile( get_defined_constants()["CSGO_PATH"] . 'csgo\\resource\\csgo_english.txt' ), 'UTF-16LE'); // with decode utf16le
        $names_ru    = str::encode( $this->readFile( get_defined_constants()["CSGO_PATH"] . 'csgo\\resource\\csgo_russian.txt' ), 'UTF-8'); // with encode utf8; file must be converted to UTF-8
        $names_ua    = str::encode( $this->readFile( get_defined_constants()["CSGO_PATH"] . 'csgo\\resource\\csgo_ukrainian.txt' ), 'UTF-8'); // with encode utf8; file must be converted to UTF-8
        
        $identifiers = $this->readFile( get_defined_constants()["CSGO_PATH"] . 'csgo\\scripts\\items\\items_game.txt' ); 

        print "Preparing information...\n";
        
        # very hard mocking of PHP D:
        foreach ( preg_match_all('/^([^#=]+)=(.+)$/uim', $patterns, $matches, PREG_SET_ORDER ) &&0?: $matches as $i=>$line ){
            $i = $i+1;
            
            $line = array_map( function($val){return trim($val);}, array_values( array_slice($line, 1) ) );

            for( $try=1; $try<=3; $try++ ){
                list( $weapon, $pattern, $link ) = $this->tagWeapon($line, $try+1);

                if($this->matchData($pattern, $names_en, $names_ru, $names_ua, $identifiers, $id, $tag_ru, $tag_en, $tag_ua, $rarity)){
                    $this->skins[$weapon][$id] = ["IMAGE"=>$link, "RARITY"=> $rarity, "TAG_RU"=>$tag_ru, "TAG_EU"=>$tag_en, "TAG_UA"=>$tag_ua];                           
                    print "[{$i}/".count($matches)."] id{$id} - {$tag_en} ({$weapon})" . ($try > 1 ? " try {$try}" : null ) . PHP_EOL;
                    continue 2;
                }
            }
            
            $errors[] = $line[0];
            print "(!) [{$i}/".count($matches)."] {$line[0]} has no identifiers -> {$weapon} (?) \n";
        }
        
        print "Saving to JSON...\n";
        
        # write skins data to weapons.json
        $stream = fopen("weapons.json", "w");
        fwrite($stream, json_encode($this->skins));
        fclose($stream);
        
        if( ! empty($errors) ){
            print "Done with ".count($errors)." errors.\n";
            $stream = fopen("errors.log", "w");
            fwrite($stream, implode("\n", $errors) );
            fclose($stream);
        }else "Done successful.\n";
    }
        
    /**
     * Read file and get contents
     * 
     * @param string $filename Path to file
     * @return string File content
     */
    public function readFile(string $filename){
        $handle = fopen($filename, "r");
        $contents = fread($handle, filesize($filename));
        fclose($handle);
        return $contents;
    }
    
    public function getGameWay() {
        $reg = Windows::getArch() == 'x64' ? 'HKEY_LOCAL_MACHINE\SOFTWARE\WOW6432Node\Valve\Steam' : 'HKEY_LOCAL_MACHINE\SOFTWARE\Valve\Steam'; 
        try { 
            $reg = new Registry($reg); 
            $steam = $reg->read('InstallPath')->getValue(); 
        } catch(Exception $e) { 
            $return=9; 
        }
        
        if(fs::exists($steam . "\\steamapps\\libraryfolders.vdf")) {
            $find_game_path = $this->VDFParser->parse(file_get_contents("{$steam}\\steamapps\\libraryfolders.vdf"));
            $game_way = $find_game_path[LibraryFolders][1] . "\\steamapps\\common\\Counter-Strike Global Offensive\\";
            if($find_game_path[LibraryFolders][1] and fs::exists($game_way . "csgo.exe"))
                $gameWay = str_replace("\\\\", "\\", $game_way);
        }
              
        if(fs::exists($steam . "\\config\\config.vdf")) {
            $find_game_path_add = $this->VDFParser->parse(file_get_contents("{$steam}\\\config\\config.vdf"));
            $game_way_add = $find_game_path_add[InstallConfigStore][Software][Valve][Steam][BaseInstallFolder_1] . "\\steamapps\\common\\Counter-Strike Global Offensive\\";
            if($find_game_path_add[InstallConfigStore][Software][Valve][Steam][BaseInstallFolder_1] and fs::exists($game_way_add . "csgo.exe"))
                $gameWay = str_replace("\\\\", "\\", $game_way_add);
        }
          
        if(!fs::exists($game_way . "csgo.exe") and !fs::exists($game_way_add . "csgo.exe")) {
            $gameWay = $steam . '\steamapps\common\Counter-Strike Global Offensive\\';
        }
          
        return $gameWay;
    }
}