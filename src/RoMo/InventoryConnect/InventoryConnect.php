<?php

declare(strict_types=1);

namespace RoMo\InventoryConnect;

use alemiz\sga\events\ClientAuthenticatedEvent;
use alemiz\sga\StarGateAtlantis;
use pocketmine\data\bedrock\item\SavedItemStackData;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\item\Item;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\SingletonTrait;
use poggit\libasynql\DataConnector;
use poggit\libasynql\libasynql;
use RoMo\InventoryConnect\protocol\InventoryConnectSessionConnectPacket;
use RoMo\InventoryConnect\protocol\InventorySavePacket;

class InventoryConnect extends PluginBase implements Listener{

    const INVENTORY = "inventory";
    const ARMOR_INVENTORY = "armor_inventory";
    const OFF_HAND_INVENTORY = "off_hand_inventory";
    const HELD_INDEX = "held_index";
    const ENDER_CHEST_INVENTORY = "ender_chest_inventory";

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
            $nbt = (new LittleEndianNbtSerializer())->read(zlib_decode($rows[0]["inventoryData"]))->mustGetCompoundTag();;
            /*$inventoryItems = [];
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
            }*/

            $inventory = $player->getInventory();
            $inventoryTag = $nbt->getListTag(self::INVENTORY);
            if($inventoryTag !== null){
                $inventoryItems = [];
                /** @var CompoundTag $item */
                foreach($inventoryTag as $item){
                    $slot = $item->getByte(SavedItemStackData::TAG_SLOT);
                    $inventoryItems[$slot] = Item::nbtDeserialize($item);
                }
                $inventory->setContents($inventoryItems);
            }

            $armorInventoryTag = $nbt->getListTag(self::ARMOR_INVENTORY);
            if($armorInventoryTag !== null){
                $armorInventoryItems = [];
                /** @var CompoundTag $item */
                foreach($armorInventoryTag as $item){
                    $slot = $item->getByte(SavedItemStackData::TAG_SLOT);
                    $armorInventoryItems[$slot] = Item::nbtDeserialize($item);
                }
                $player->getArmorInventory()->setContents($armorInventoryItems);
            }

            $offHand = $nbt->getCompoundTag(self::OFF_HAND_INVENTORY);
            if($offHand !== null){
                $player->getOffHandInventory()->setItem(0, Item::nbtDeserialize($offHand));
            }

            $inventory->setHeldItemIndex($nbt->getInt(self::HELD_INDEX));

            $enderInventoryTag = $nbt->getListTag(self::ENDER_CHEST_INVENTORY);
            if($enderInventoryTag !== null){
                $enderInventoryItems = [];
                /** @var CompoundTag $item */
                foreach($enderInventoryTag as $item){
                    $slot = $item->getByte(SavedItemStackData::TAG_SLOT);
                    $enderInventoryItems[$slot] = Item::nbtDeserialize($item);
                }
                $player->getEnderInventory()->setContents($enderInventoryItems);
            }

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


        $inventory = $player->getInventory();
        $armorInventory = $player->getArmorInventory();
        $enderInventory = $player->getEnderInventory();

        $nbt = CompoundTag::create();
        $inventoryTag = new ListTag([], NBT::TAG_Compound);
        $armorInventoryTag = new ListTag([], NBT::TAG_Compound);
        $enderInventoryTag = new ListTag([], NBT::TAG_Compound);
        $nbt->setTag(self::INVENTORY, $inventoryTag);
        $nbt->setTag(self::ARMOR_INVENTORY, $armorInventoryTag);
        $nbt->setTag(self::ENDER_CHEST_INVENTORY, $enderInventoryTag);

        //NORMAL
        $slotCount = $inventory->getSize();
        for($slot = 0; $slot < $slotCount; ++$slot){
            $item = $inventory->getItem($slot);
            if(!$item->isNull()){
                $inventoryTag->push($item->nbtSerialize($slot));
            }
        }

        //ARMOR
        for($slot = 0; $slot < 4; ++$slot){
            $item = $armorInventory->getItem($slot);
            if(!$item->isNull()){
                $armorInventoryTag->push($item->nbtSerialize($slot));
            }
        }

        //OFF_HAND
        $offHandItem = $player->getOffHandInventory()->getItem(0);
        if(!$offHandItem->isNull()){
            $nbt->setTag(self::OFF_HAND_INVENTORY, $offHandItem->nbtSerialize());
        }

        //HELD_INDEX
        $nbt->setInt(self::HELD_INDEX, $inventory->getHeldItemIndex());

        //ENDER_CHEST
        $slotCount = $enderInventory->getSize();
        for($slot = 0; $slot < $slotCount; ++$slot){
            $item = $enderInventory->getItem($slot);
            if(!$item->isNull()){
                $enderInventoryTag->push($item->nbtSerialize($slot));
            }
        }

        $convertData = zlib_encode((new LittleEndianNbtSerializer())->write(new TreeRoot($nbt)), ZLIB_ENCODING_GZIP);

        $this->database->executeInsert("inventory.save", [
            "xuid" => (int) $player->getXuid(),
            "inventoryData" => $convertData
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
        $this->database->close();
    }

    public static function getDataByItem(Item $item) : string{
        return (new LittleEndianNbtSerializer())->write(new TreeRoot($item->nbtSerialize()));
    }

    public static function getItemByData(string $data) : Item{
        return Item::nbtDeserialize((new LittleEndianNbtSerializer())->read($data)->mustGetCompoundTag());
    }

}