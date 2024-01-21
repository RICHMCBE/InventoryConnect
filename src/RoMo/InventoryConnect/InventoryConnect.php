<?php

declare(strict_types=1);

namespace RoMo\InventoryConnect;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\item\Item;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\TreeRoot;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use poggit\libasynql\DataConnector;
use poggit\libasynql\libasynql;

class InventoryConnect extends PluginBase implements Listener{

    private DataConnector $database;

    protected function onEnable() : void{
        $this->saveDefaultConfig();
        $this->database = libasynql::create($this, $this->getConfig()->get("database"), [
            "mysql" => "mysql.sql"
        ]);
        $this->database->executeGeneric("inventory.initialization");

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function saveInventory(Player $player) : void{
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
        $inventoryData["heldItemIndex"] = $player->getInventory()->getHeldItemIndex();
        $this->database->executeInsert("inventory.save", [
            "xuid" => (int) $player->getXuid(),
            "inventoryData" => base64_encode(gzdeflate(json_encode($inventoryData, JSON_UNESCAPED_UNICODE)))
        ]);
    }

    public function onLogin(PlayerLoginEvent $event) : void{
        $player = $event->getPlayer();
        $this->database->executeSelect("inventory.load", [
            "xuid" => (int) $player->getXuid()
        ], function(array $rows) use ($player) : void{
            if(!isset($rows[0])){
                return;
            }
            $data = json_decode(gzinflate(base64_decode($rows[0]["inventoryData"])), true);
            $inventoryItems = [];
            $armorInventoryItems = [];
            $enderInventoryItems = [];
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

            $player->getInventory()->setContents($inventoryItems);
            $player->getArmorInventory()->setContents($armorInventoryItems);
            $player->getEnderInventory()->setContents($enderInventoryItems);
            $player->getInventory()->setHeldItemIndex($data["heldItemIndex"] ?? 0);
        });
    }

    public function onQuit(PlayerQuitEvent $event) : void{
        $this->saveInventory($event->getPlayer());
    }

    protected function onDisable() : void{
        foreach($this->getServer()->getOnlinePlayers() as $player){
            $this->saveInventory($player);
        }
    }

    public static function getDataByItem(Item $item) : string{
        return base64_encode((new LittleEndianNbtSerializer())->write(new TreeRoot($item->nbtSerialize())));
    }

    public static function getItemByData(string $data) : Item{
        return Item::nbtDeserialize((new LittleEndianNbtSerializer())->read(base64_decode($data))->mustGetCompoundTag());
    }
}