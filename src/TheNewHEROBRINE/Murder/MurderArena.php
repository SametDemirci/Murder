<?php

namespace TheNewHEROBRINE\Murder;

use pocketmine\entity\Entity;
use pocketmine\entity\Human;
use pocketmine\item\Item;
use pocketmine\level\Level;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\Player;
use TheNewHEROBRINE\Murder\projectile\MurderKnifeProjectile;

class MurderArena {

    const GAME_IDLE = 0;
    const GAME_STARTING = 1;
    const GAME_RUNNING = 2;

    /** @var MurderMain $plugin */
    private $plugin;

    /** @var string $name */
    private $name;

    /** @var int $countdown */
    private $countdown;

    /** @var int $maxTime */
    private $maxTime;

    /** @var int $status */
    private $state = self::GAME_IDLE;

    /** @var Player[] $players */
    private $players = array();

    /** @var array */
    private $skins = array();

    /** @var Player $murderer */
    private $murderer;

    /** @var Player[] $bystanders */
    private $bystanders;

    /** @var array $spawns */
    private $spawns;

    /** @var array $espawns */
    private $espawns;

    /** @var Level $world */
    private $world;

    /** @var int $spawnEmerald */
    private $spawnEmerald = 10;

    /**
     * MurderArena constructor.
     * @param MurderMain $plugin
     * @param string $name
     * @param array $spawns
     * @param array $espawns
     */
    public function __construct(MurderMain $plugin, string $name, array $spawns, array $espawns) {
        $this->spawns = $spawns;
        $this->espawns = $espawns;
        $this->plugin = $plugin;
        $this->name = $name;
        $this->world = $this->plugin->getServer()->getLevelByName($name);
        $this->countdown = $this->plugin->getConfig()->get("countdown", 90);
        $this->maxTime = $this->plugin->getConfig()->get("maxGameTime", 1200);
    }

    public function join(Player $player) {
        if (!$this->isRunning() && !$this->inArena($player)) {
            $this->players[] = $player;
            $player->getInventory()->clearAll();
            $player->getInventory()->sendContents($player);
            $hub = $this->plugin->getServer()->getLevelByName($this->plugin->getConfig()->get("hub"));
            $player->teleport($hub->getSpawnLocation());
            $this->broadcastMessage(str_replace("{player}", $player->getName(), $this->plugin->getConfig()->get("join")));
            if (count($this->players) >= 2 && $this->isIdle())
                $this->state = self::GAME_STARTING;
        }
    }

    /**
     * @return int
     */
    public function isRunning(): int {
        return $this->state == 2;
    }

    /**
     * @param Player $player
     * @return bool
     */
    public function inArena(Player $player) {
        return in_array($player, $this->players);
    }

    /**
     * @param string $msg
     */
    public function broadcastMessage(string $msg) {
        $this->plugin->broadcastMessage($msg, $this->getPlayers());
    }

    /**
     * @return Player[]
     */
    public function getPlayers(): array {
        return $this->players;
    }

    /**
     * @return int
     */
    public function isIdle(): int {
        return $this->state == 0;
    }

    public function tick() {
        if ($this->isStarting()) {
            if ($this->countdown == 0) {
                $this->start();
                $this->broadcastMessage("La partita è iniziata!");
            } elseif ($this->countdown > 10 && $this->countdown % 10 == 0) {
                $this->broadcastMessage("La partita inizierà tra {$this->countdown} secondi");
            } elseif ($this->countdown <= 10) {
                $this->broadcastMessage("La partita inizierà tra {$this->countdown}...");
            }
            $this->countdown--;
        }

        if ($this->isRunning()) {
            foreach ($this->murderer->level->getNearbyEntities($this->murderer->boundingBox->grow(1, 0.5, 1), $this->murderer) as $entity) {
                if ($entity instanceof MurderKnifeProjectile) {
                    $this->murderer->getInventory()->addItem(Item::get(Item::WOODEN_SWORD)->setCustomName("Coltello"));
                    $entity->kill();
                }
            }
            if ($this->spawnEmerald == 0) {
                $this->spawnEmerald($this->espawns[array_rand($this->espawns)]);
                $this->spawnEmerald = 10;
            }
            $this->spawnEmerald--;
        }
    }

    /**
     * @return int
     */
    public function isStarting(): int {
        return $this->state == 1;
    }

    public function start() {
        $this->state = self::GAME_RUNNING;
        $skins = array();
        foreach ($this->players as $player) {
            $skins[$player->getName()] = $player->getSkinData();
        }
        $this->skins = $skins;
        if (count(array_unique($this->skins)) > 1)
            do {
                shuffle($skins);
            } while (array_values($this->skins) == $skins);
        $players = $this->players;
        do {
            shuffle($players);
        } while ($this->players == $players);
        foreach ($this->players as $player) {
            $player->setSkin(array_shift($skins), $player->getSkinId());
            $name = array_shift($players)->getName();
            $player->setDisplayName($name);
            $player->setNameTag($name);
            $player->setNameTagAlwaysVisible(false);
        }
        $random = array_rand($this->players, 2);
        shuffle($random);
        $this->murderer = $this->getPlayers()[$random[0]];
        $this->bystanders[] = $this->getPlayers()[$random[1]];
        $this->murderer->getInventory()->setItem(0, Item::get(Item::WOODEN_SWORD)->setCustomName("Coltello"));
        $this->plugin->sendMessage("Sei l'assassino!", $this->murderer);
        $this->bystanders[0]->getInventory()->setItem(0, Item::get(Item::WOODEN_HOE)->setCustomName("Pistola"));
        $this->plugin->sendMessage("Sei quello con l'arma!", $this->bystanders[0]);
        $this->bystanders[0]->setFood(6);
        $spawns = $this->spawns;
        shuffle($spawns);
        foreach ($this->players as $player) {
            $player->setGamemode($player::ADVENTURE);
            if ($player !== $this->getMurderer() && $player != $this->bystanders[0]) {
                $this->bystanders[] = $player;
                $player->setFood(6);
            }
            $spawn = array_shift($spawns);
            $player->teleport(new Position($spawn[0], $spawn[1], $spawn[2], $this->plugin->getServer()->getLevelByName($this)));
        }
        foreach ($this->espawns as $espawn) {
            $this->spawnEmerald($espawn);
        }
    }

    /**
     * @return Player
     */
    public function getMurderer() {
        return $this->murderer;
    }

    public function spawnEmerald(array $espawn) {
        $this->world->dropItem(new Vector3($espawn[0], $espawn[1], $espawn[2]), Item::get(Item::EMERALD));
    }


    /**
     * @param Player $player
     * @param bool $silent
     */
    public function quit(Player $player, bool $silent = false) {
        if ($this->inArena($player)) {
            if ($this->isRunning()) {
                $player->setNameTagAlwaysVisible(true);
                $player->setNameTag($player->getName());
                $player->setDisplayName($player->getName());
                $player->setSkin($this->skins[$player->getName()], $player->getSkinId());
                $player->getInventory()->clearAll();
                $player->getInventory()->sendContents($player);
                if ($this->isBystander($player) and count($this->players) == 2) {
                    $this->plugin->broadcastMessage("L'assassino ha vinto la partita su " . $this);
                    $this->stop();
                }
                elseif ($this->isMurderer($player)){
                    $this->plugin->broadcastMessage("Gli innocenti hanno vinto la partita su " . $this);
                    $this->stop();
                }
            }
            unset($this->players[array_search($player, $this->players)]);
                if (!$silent)
                    $this->broadcastMessage(str_replace("{player}", $player->getName(), $this->plugin->getConfig()->get("quit")));
                $player->teleport($this->plugin->getServer()->getDefaultLevel()->getSpawnLocation());
                if (count($this->players) < 2 && $this->isStarting())
                    $this->state = self::GAME_IDLE;
        }
    }

    /**
     * @param Player $player
     */
    public function onDeath(Player $player){
            $nbt = new CompoundTag("", [
                "Pos" => new ListTag("Pos", [
                    new DoubleTag("", $player->x),
                    new DoubleTag("", $player->y),
                    new DoubleTag("", $player->z)
                ]),
                "Motion" => new ListTag("Motion", [
                    new DoubleTag("", 0),
                    new DoubleTag("", 0),
                    new DoubleTag("", 0)
                ]),
                "Rotation" => new ListTag("Rotation", [
                    new FloatTag("", $player->yaw),
                    new FloatTag("", $player->pitch)
                ]),
            ]);
            $player->saveNBT();
            $nbt->Inventory = clone $player->namedtag->Inventory;
            $nbt->Skin = new CompoundTag("Skin", ["Data" => new StringTag("Data", $player->getSkinData()), "Name" => new StringTag("Name", $player->getSkinId())]);
            $corpse = Entity::createEntity("Human", $player->getLevel(), $nbt);
            $corpse->spawnToAll();
            $corpse->setDataProperty(Human::DATA_PLAYER_BED_POSITION, Human::DATA_TYPE_POS, [(int)$player->x, (int)$player->y, (int)$player->z]);
            $corpse->setDataFlag(Human::DATA_PLAYER_FLAGS, Human::DATA_PLAYER_FLAG_SLEEP, true, Human::DATA_TYPE_BYTE);
            $corpse->respawnToAll();
            $this->quit($player, true);
    }

    public function stop(){
        foreach ($this->getPlayers() as $player){
            if ($player->isOnline()){
                $player->setNameTagAlwaysVisible(true);
                $player->setNameTag($player->getName());
                $player->setDisplayName($player->getName());
                $player->setSkin($this->skins[$player->getName()], $player->getSkinId());
                $player->getInventory()->clearAll();
                $player->getInventory()->sendContents($player);
                $player->teleport($this->plugin->getServer()->getDefaultLevel()->getSpawnLocation());
            }
            $this->players = [];
            $this->skin = [];
            $this->countdown = $this->plugin->getConfig()->get("countdown", 90);
            $this->bystanders = [];
            $this->murderer = null;
            $this->spawnEmerald = 0;
            $this->state = self::GAME_IDLE;
            foreach ($this->getWorld()->getEntities() as $entity){
                $entity->setHealth(0);
            }
        }
    }

    /**
     * @return Level
     */
    public function getWorld(): Level {
        return $this->world;
    }

    /**
     * @return Player[]
     */
    public function getBystanders() {
        return $this->bystanders;
    }

    /**
     * @param Player $player
     */
    public function isMurderer(Player $player) {
        return $this->murderer === $player;
    }

    public function isBystander(Player $player) {
        return in_array($player, $this->bystanders);
    }

    /**
     * @return string
     */
    public function __toString(): string {
        return $this->getName();
    }

    /**
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }
}
