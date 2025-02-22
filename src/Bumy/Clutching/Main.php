<?php

declare(strict_types = 1);

namespace Bumy\Clutching;

use pocketmine\plugin\PluginBase;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerCreationEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\player\PlayerExhaustEvent;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerChangeSkinEvent;
use pocketmine\event\entity\EntityDespawnEvent;

use pocketmine\Server;
use pocketmine\Player;
use pocketmine\entity\Entity;
use pocketmine\level\Position;
use pocketmine\level\Level;
use pocketmine\utils\Config;

use Bumy\Clutching\PlayerData;
use Bumy\Clutching\ArenaManager;
use Bumy\Clutching\entity\CustomNPC;
use Bumy\Clutching\task\WaitBetweenTask;
use Bumy\Clutching\task\DecayTask;

class Main extends PluginBase implements Listener {

    public $arenaManager;

    public $config;

    public $playerData = [];

    public function onEnable() {

        #if (is_null($this->getServer()->getPluginManager()->getPlugin("FormAPI"))) {
        #    $this->getLogger()->error("You need to have FormAPI installed to use this plugin!");
        #    $this->getServer()->getPluginManager()->disablePlugin($this);
        #    return;
        #}

        $this->saveResource("DefaultMap.zip");

        if(!is_file($this->getDataFolder() . "/config.yml")) {
            $this->saveResource("/config.yml");
        }

        $this->config = (new Config($this->getDataFolder() . "/config.yml", Config::YAML))->getAll();

        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $this->arenaManager = new ArenaManager($this);
        Entity::registerEntity(CustomNPC::class, true);

        foreach(glob($this->getServer()->getDataPath() . "worlds/*") as $world) {
            $world = str_replace($this->getServer()->getDataPath() . "worlds/", "", $world);
            if($this->getServer()->isLevelLoaded($world)){
                continue;
            }
            $this->getServer()->loadLevel($world);
        }
    }

    /**
     * @priority HIGHEST
     */
    public function onChangeSkin(PlayerChangeSkinEvent $event){
        $player = $event->getPlayer();
        $player->sendMessage("Unfortunately, you cannot change your skin here.");
        $event->setCancelled();

    }

    /**
     * @param PlayerJoinEvent $event
     * @return void
     */
    public function onPlayerJoin(PlayerJoinEvent $event) : void{
        $player = $event->getPlayer();
        $this->playerData[$player->getName()] = new PlayerData($this, $player->getName());
        $this->getArenaManager()->createGame($player);
        $this->getArenaManager()->giveItems($player, "stopped");
        $player->setGamemode(0);
    }

    /**
     * @param PlayerExhaustEvent $event
     * @return void
     */
    public function onExhaust(PlayerExhaustEvent $event){
        $event->setCancelled();
    }

    /**
     * @param PlayerQuitEvent $event 
     * @return void
     */
    public function onPlayerLeave(PlayerQuitEvent $event) : void{
        $player = $event->getPlayer();
        if($this->getServer()->getLevelByName($this->getPlayerData($player->getName())->getMap()."-".$player->getName()) instanceof Level){
            foreach($this->getServer()->getLevelByName($this->getPlayerData($player->getName())->getMap()."-".$player->getName())->getPlayers() as $p){
                $pos = new Position(100, 52, 100, $this->getServer()->getLevelByName($this->getPlayerData($p->getName())->getMap()."-".$p->getName()));
                $p->teleport($pos);
                $p->setGamemode(0);
                $this->getPlayerData($p->getName())->setSpectating(false);
                $p->sendMessage("§cTeleporting back to your island, because the player you were spectating has left the game!");
                $this->getArenaManager()->giveItems($p, "stopped");
            }

            //delete game and map
            $this->getPlayerData($player->getName())->setInGame(false);

            $this->deleteMap($player, $this->getPlayerData($player->getName())->getMap());
        }
    }

    /**
     * @param EntityDamageEvent $event
     * @return void
     */
    public function onEntityDamageEvent(EntityDamageEvent $event){
        //cancelling customnpc hit
        if($event instanceof EntityDamageByEntityEvent){
            if($event->getEntity() instanceof CustomNPC){
                $event->setCancelled();
            }
        }

        if($event->getCause() == EntityDamageEvent::CAUSE_FALL){
            $event->setCancelled();
        }
    }

    public function onInteractEvent(PlayerInteractEvent $event){
        $player = $event->getPlayer();
        $name = $event->getItem()->getCustomName();
        switch($name){
            case "§r§7Start the Game":
                $this->getPlayerData($player->getName())->setInGame(true);
                $this->getPlayerData($player->getName())->hitSession++;
                $this->getScheduler()->scheduleDelayedTask(new task\WaitBetweenTask($this, $player, $this->getPlayerData($player->getName())->hitSession), mt_rand(2 * 20, 3 * 20));
                $player->sendMessage("§aYou started the game!");
                $player->sendPopup("§aPrepare for the hits!");
                $this->getArenaManager()->giveItems($player, "game");
                break;

            case "§r§7Settings":
                //$player->sendMessage("§7Coming soon...");
                $this->getArenaManager()->openSettings($player);
                break;

            case "§r§7Stop the Game":
                $this->getPlayerData($player->getName())->setInGame(false);
                $player->sendMessage("§cYou stopped the game!");
                $this->getArenaManager()->giveItems($player, "stopped");
                $pos = new Position(100, 52, 100, $player->getLevel());
                $player->teleport($pos);
                $this->getArenaManager()->resetMap($player);
                break;

            case "§r§7Reset Map":
                $this->getArenaManager()->resetMap($player);
                $player->sendMessage("§cMap resetted!");
                break;

            case "§r§7Go back to hub":
                if($this->config["backToHub"]["disabled"] == false){
                    $player->transfer($this->config["backToHub"]["ip"], $this->config["backToHub"]["port"]);
                }
                break;

            case "§r§7Spectate Somebody Else":
            case "§r§7Spectate":
                if($this->config["canSpectate"]){
                    $this->getArenaManager()->openSpectatingList($player);
                }
                break;

            case "§r§7Go back to your island":
                $map = $this->getPlayerData($player->getName())->getMap();
                $pos = new Position(100, 52, 100, $this->getServer()->getLevelByName($map."-".$player->getName()));
                $player->teleport($pos);
                $player->setGamemode(0);
                $this->getPlayerData($player->getName())->setSpectating(false);
                $this->getArenaManager()->giveItems($player, "stopped");
                break;
        }
    }

    public function onPlayerMove(PlayerMoveEvent $event){
        $player = $event->getPlayer();
        if($player->getY() < 40) {
            $pos = new Position(100, 52, 100, $player->getLevel());
            $player->teleport($pos);
            if ($this->getPlayerData($player->getName())->getIngame()) {
                $this->getArenaManager()->resetMap($player);
                $this->getArenaManager()->giveItems($player, "game");
            }elseif($player->getIngame(false)){
                $this->getArenaManager()->giveItems($player, "stopped");
            }elseif($player->getSpectating(true)){
                $this->getArenaManager()->giveItems($player, "spectating");
            }
        }
    }

    public function decayTask(BlockPlaceEvent $e)
    {
        $block = $e->getBlock();
        $player = $e->getPlayer();
        $x = $block->getX();
        $y = $block->getY();
        $z = $block->getZ();

        if ($this->getPlayerData($player->getName())->getIngame(true)) {
            $this->getArenaManager()->getPlugin()->getScheduler()->scheduleDelayedTask(new DecayTask($player, $block, $x, $y, $z), 200);
        }
    }

    public function onBreak(BlockBreakEvent $event){
        $event->setCancelled();
    }

    public function getPlayerData(string $playerName){
        if(!isset($this->playerData[$playerName])){
            $this->playerData[$playerName] = new PlayerData($this, $playerName);
        }
        return $this->playerData[$playerName];
        
    }

    /**
     * @param $player
     * @param string $folderName
     * @return void
     */
    public function createMap($player, $folderName){
        $mapname = $folderName."-".$player->getName();
      
        $zipPath = $this->getServer()->getDataPath() . "plugin_data/ClutchCore/" .  $folderName . ".zip";

        if(file_exists($this->getServer()->getDataPath() . "worlds" . DIRECTORY_SEPARATOR . $mapname)){
            $this->deleteMap($player, $folderName);
        }
      
        $zipArchive = new \ZipArchive();
        if($zipArchive->open($zipPath) == true){
            $zipArchive->extractTo($this->getServer()->getDataPath() . "worlds");
            $zipArchive->close();
          $this->getLogger()->notice("Zip Object created!");
        } else {
          $this->getLogger()->notice("Couldn't create Zip Object!");
        }
        
        rename($this->getServer()->getDataPath() . "worlds" . DIRECTORY_SEPARATOR . $folderName, $this->getServer()->getDataPath() . "worlds" . DIRECTORY_SEPARATOR . $mapname);
        $this->getServer()->loadLevel($mapname);
        return $this->getServer()->getLevelByName($mapname);
    }
    
    /**
     * @param $player
     * @param string $folderName
     * @return void
     */            
    public function deleteMap($player, $folderName) : void{
        $mapName = $folderName."-".$player->getName();
        if(!$this->getServer()->isLevelGenerated($mapName)) {
            
            return;
        }

        if(!$this->getServer()->isLevelLoaded($mapName)) {
            
            return;
        }

        $this->getServer()->unloadLevel($this->getServer()->getLevelByName($mapName));
        $folderName = $this->getServer()->getDataPath() . "worlds" . DIRECTORY_SEPARATOR . $mapName;
        $this->removeDirectory($folderName);
        
         $this->getLogger()->notice("World has been deleted for player called ".$player->getName());
      
    }

    public function removeDirectory($path) {
        $files = glob($path . '/*');
        foreach ($files as $file) {
            is_dir($file) ? $this->removeDirectory($file) : unlink($file);
        }
        rmdir($path);
        return;
    }

    public function getArenaManager(){
        return $this->arenaManager;
    }
               
}
