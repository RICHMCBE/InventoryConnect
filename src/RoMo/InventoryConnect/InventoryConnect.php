<?php

declare(strict_types=1);

namespace RoMo\InventoryConnect;

use alemiz\sga\events\ClientAuthenticatedEvent;
use alemiz\sga\StarGateAtlantis;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\item\Item;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\TreeRoot;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\SingletonTrait;
use poggit\libasynql\DataConnector;
use poggit\libasynql\libasynql;
use RoMo\InventoryConnect\protocol\InventoryConnectSessionConnectPacket;
use RoMo\InventoryConnect\protocol\InventorySavePacket;

class InventoryConnect extends PluginBase implements Listener{

    use SingletonTrait;

    private DataConnector $database;

    private array $savingXuid = [];
    private array $loadedXuid = [];

    protected function onLoad() : void{
        self::$instance = $this;
    }

    protected function onEnable() : void{
        $this->saveDefaultConfig();
        $this->database = libasynql::create($this, $this->getConfig()->get("database"), [
            "mysql" => "mysql.sql"
        ]);
        $this->database->executeGeneric("inventory.initialization");

        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        foreach([
            new InventorySavePacket(),
            new InventoryConnectSessionConnectPacket()
        ] as $packet){
            StarGateAtlantis::getInstance()->getDefaultClient()->getProtocolCodec()->registerPacket($packet->getPacketId(), $packet);
        }
    }

    public function loadInventory(Player $player) : void{
        $this->database->executeSelect("inventory.load", [
            "xuid" => (int) $player->getXuid()
        ], function(array $rows) use ($player) : void{
            if(!$player->isConnected()){
                return;
            }
            if(!isset($rows[0])){
                $this->loadedXuid[(int) $player->getXuid()] = true;
                return;
            }
            $data = json_decode(gzinflate(base64_decode($rows[0]["inventoryData"])), true);
            $inventoryItems = [];
            $armorInventoryItems = [];
            $enderInventoryItems = [];
            $offHandInventory = null;
            if(isset($data["inventory"])){
                foreach($data["inventory"] as $slot => $itemData){
                    $inventoryItems[$slot] = self::getItemByData($itemData);
                }
            }
            if(isset($data["armorInventory"])){
                foreach($data["armorInventory"] as $slot => $itemData){
                    $armorInventoryItems[$slot] = self::getItemByData($itemData);
                }
            }
            if(isset($data["enderInventory"])){
                foreach($data["enderInventory"] as $slot => $itemData){
                    $enderInventoryItems[$slot] = self::getItemByData($itemData);
                }
            }
            if(isset($data["offHandInventory"])){
                $player->getOffHandInventory()->setItem(0, self::getItemByData($data["offHandInventory"]));
            }


            $player->getInventory()->setContents($inventoryItems);
            $player->getArmorInventory()->setContents($armorInventoryItems);
            $player->getEnderInventory()->setContents($enderInventoryItems);
            $player->getInventory()->setHeldItemIndex($data["heldItemIndex"] ?? 0);

            $this->loadedXuid[(int) $player->getXuid()] = true;
        });
    }

    public function saveInventory(Player $player, bool $unload = false) : void{
        if(empty($player->getXuid())){
            return;
        }
        if(!isset($this->loadedXuid[(int) $player->getXuid()])){
            return;
        }
        $packet = new InventorySavePacket();
        $packet->setClientName(StarGateAtlantis::getInstance()->getDefaultClient()->getClientName());
        $packet->setStatus(InventorySavePacket::START);
        $packet->setXuid((int) $player->getXuid());
        StarGateAtlantis::getInstance()->getDefaultClient()->sendPacket($packet);

        $inventoryData = [
            "inventory" => [],
            "armorInventory" => [],
            "enderInventory" => []
        ];
        foreach($player->getInventory()->getContents() as $slot => $item){
            $inventoryData["inventory"][$slot] = self::getDataByItem($item);
        }
        foreach($player->getArmorInventory()->getContents() as $slot => $item){
            $inventoryData["armorInventory"][$slot] = self::getDataByItem($item);
        }
        foreach($player->getEnderInventory()->getContents() as $slot => $item){
            $inventoryData["enderInventory"][$slot] = self::getDataByItem($item);
        }

        $offHandItem = $player->getOffHandInventory()->getItem(0);
        if($offHandItem->getCount() < 1){
            $inventoryData["offHandInventory"] = null;
        }else{
            $inventoryData["offHandInventory"] = self::getDataByItem($offHandItem);
        }
        $inventoryData["heldItemIndex"] = $player->getInventory()->getHeldItemIndex();

        $this->database->executeInsert("inventory.save", [
            "xuid" => (int) $player->getXuid(),
            "inventoryData" => base64_encode(gzdeflate(json_encode($inventoryData, JSON_UNESCAPED_UNICODE)))
        ], function() use ($player, $unload) : void{
            $packet = new InventorySavePacket();
            $packet->setClientName(StarGateAtlantis::getInstance()->getDefaultClient()->getClientName());
            $packet->setStatus(InventorySavePacket::END);
            $packet->setXuid((int) $player->getXuid());
            StarGateAtlantis::getInstance()->getDefaultClient()->sendPacket($packet);

            if($unload){
                if(isset($this->loadedXuid[(int) $player->getXuid()])){
                    unset($this->loadedXuid[(int) $player->getXuid()]);
                }
            }
        });
    }

    public function onLogin(PlayerLoginEvent $event) : void{
        $player = $event->getPlayer();
        if(empty($player->getXuid())){
            return;
        }
        if(isset($this->savingXuid[(int) $player->getXuid()])){
            $this->savingXuid[(int) $player->getXuid()] = $event->getPlayer();
            return;
        }
        $this->loadInventory($player);
    }

    public function onQuit(PlayerQuitEvent $event) : void{
        if(isset($this->savingXuid[(int) $event->getPlayer()->getXuid()])){
            $this->savingXuid[(int) $event->getPlayer()->getXuid()] = true;
        }
        $this->saveInventory($event->getPlayer(), true);
    }

    public function onClientAuthenticated(ClientAuthenticatedEvent $event) : void{
        $packet = new InventoryConnectSessionConnectPacket();
        $packet->setSessionName($event->getClient()->getClientName());
        StarGateAtlantis::getInstance()->getDefaultClient()->sendPacket($packet);
    }

    public function onSavingInventoryStart(int $xuid) : void{
        $this->savingXuid[$xuid] = true;
    }

    public function onSavingInventoryEnd(int $xuid) : void{
        if(!isset($this->savingXuid[$xuid])){
            return;
        }
        if($this->savingXuid[$xuid] instanceof Player){
            $this->loadInventory($this->savingXuid[$xuid]);
        }
        unset($this->savingXuid[$xuid]);
    }

    protected function onDisable() : void{
        foreach($this->getServer()->getOnlinePlayers() as $player){
            $this->saveInventory($player);
        }
    }

    public static function getDataByItem(Item $item) : string{
        return (new LittleEndianNbtSerializer())->write(new TreeRoot($item->nbtSerialize()));
    }

    public static function getItemByData(string $data) : Item{
        return Item::nbtDeserialize((new LittleEndianNbtSerializer())->read($data)->mustGetCompoundTag());
    }

}