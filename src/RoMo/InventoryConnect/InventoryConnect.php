<?php

declare(strict_types=1);

namespace RoMo\InventoryConnect;

use alemiz\sga\events\ClientAuthenticatedEvent;
use alemiz\sga\StarGateAtlantis;
use kim\present\sqlcore\SqlCore;
use pocketmine\data\bedrock\item\SavedItemStackData;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
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
use RoMo\InventoryConnect\event\CreateNewInventoryEvent;
use RoMo\InventoryConnect\protocol\InventoryConnectSessionConnectPacket;
use RoMo\InventoryConnect\protocol\InventorySavePacket;
use RoMo\XuidCore\XuidCore;

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

    private int $savingInventoryCountInLocal = 0;

    protected function onLoad() : void{
        self::$instance = $this;
    }

    protected function onEnable() : void{
        $this->saveDefaultConfig();

        if(class_exists(SqlCore::class)){
            $this->database = libasynql::create($this, SqlCore::getSqlConfig(), [
                "mysql" => "mysql.sql"
            ]);
        }else{
            $this->database = libasynql::create($this, $this->getConfig()->get("database"), [
                "mysql" => "mysql.sql"
            ]);
        }

        $this->database->executeGeneric("inventory.initialization");

        $this->getServer()->getPluginManager()->registerEvents($this, $this);

    }

    public function loadInventory(Player $player) : void{
        $this->database->executeSelect("inventory.load", [
            "xuid" => (int) $player->getXuid()
        ], function(array $rows) use ($player) : void{
            if(!$player->isConnected()){
                return;
            }
            if(!isset($rows[0])){
                $ev = new CreateNewInventoryEvent($player);
                $ev->call();
                $this->loadedXuid[(int) $player->getXuid()] = true;
                return;
            }
            $nbt = (new LittleEndianNbtSerializer())->read(zlib_decode($rows[0]["inventoryData"]))->mustGetCompoundTag();

            $inventory = $player->getInventory();
            $inventoryTag = $nbt->getListTag(self::INVENTORY);
            if($inventoryTag !== null){
                $inventoryItems = [];
                /** @var CompoundTag $item */
                foreach($inventoryTag as $item){
                    $slot = $item->getByte(SavedItemStackData::TAG_SLOT);
                    try{
                        $inventoryItems[$slot] = Item::nbtDeserialize($item);
                    }catch(SavedDataLoadingException $e){
                        $player->disconnect("인벤토리 동기화에 실패하였습니다. 서버 관리자에게 문의해주세요!");
                        $this->getLogger()->error("플레이어 {$player->getName()}(xuid: {$player->getXuid()})의 인벤토리 데이터에 알 수 없는 아이템: {$item->toString()}");
                        return;
                    }
                }
                $inventory->setContents($inventoryItems);
            }

            $armorInventoryTag = $nbt->getListTag(self::ARMOR_INVENTORY);
            if($armorInventoryTag !== null){
                $armorInventoryItems = [];
                /** @var CompoundTag $item */
                foreach($armorInventoryTag as $item){
                    $slot = $item->getByte(SavedItemStackData::TAG_SLOT);
                    try{
                        $armorInventoryItems[$slot] = Item::nbtDeserialize($item);
                    }catch(SavedDataLoadingException $e){
                        $player->disconnect("인벤토리 동기화에 실패하였습니다. 서버 관리자에게 문의해주세요!");
                        $this->getLogger()->error("플레이어 {$player->getName()}(xuid: {$player->getXuid()})의 인벤토리 데이터에 알 수 없는 아이템: {$item->toString()}");
                        return;
                    }
                }
                $player->getArmorInventory()->setContents($armorInventoryItems);
            }

            $offHand = $nbt->getCompoundTag(self::OFF_HAND_INVENTORY);
            if($offHand !== null){
                try{
                    $player->getOffHandInventory()->setItem(0, Item::nbtDeserialize($offHand));
                }catch(SavedDataLoadingException $e){
                    $player->disconnect("인벤토리 동기화에 실패하였습니다. 서버 관리자에게 문의해주세요!");
                    $this->getLogger()->error("플레이어 {$player->getName()}(xuid: {$player->getXuid()})의 인벤토리 데이터에 알 수 없는 아이템: {$item->toString()}");
                    return;
                }
            }else{
                $player->getOffHandInventory()->setItem(0, VanillaItems::AIR());
            }

            $inventory->setHeldItemIndex($nbt->getInt(self::HELD_INDEX));

            $enderInventoryTag = $nbt->getListTag(self::ENDER_CHEST_INVENTORY);
            if($enderInventoryTag !== null){
                $enderInventoryItems = [];
                /** @var CompoundTag $item */
                foreach($enderInventoryTag as $item){
                    $slot = $item->getByte(SavedItemStackData::TAG_SLOT);
                    try{
                        $enderInventoryItems[$slot] = Item::nbtDeserialize($item);
                    }catch(SavedDataLoadingException $e){
                        $player->disconnect("인벤토리 동기화에 실패하였습니다. 서버 관리자에게 문의해주세요!");
                        $this->getLogger()->error("플레이어 {$player->getName()}(xuid: {$player->getXuid()})의 인벤토리 데이터에 알 수 없는 아이템: {$item->toString()}");
                        return;
                    }
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

        if($unload){
            $this->savingXuid[(int) $player->getXuid()] = true;
        }else{
            $this->savingXuid[(int) $player->getXuid()] = $player;
        }
        $this->savingInventoryCountInLocal++;

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
            $xuid = (int) $player->getXuid();
            $packet = new InventorySavePacket();
            $packet->setClientName(StarGateAtlantis::getInstance()->getDefaultClient()->getClientName());
            $packet->setStatus(InventorySavePacket::END);
            $packet->setXuid($xuid);
            StarGateAtlantis::getInstance()->getDefaultClient()->sendPacket($packet);

            if(isset($this->savingXuid[$xuid])){
                unset($this->savingXuid[$xuid]);
            }
            $this->savingInventoryCountInLocal--;
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
        $this->saveInventory($event->getPlayer(), true);
    }

    public function onClientAuthenticated(ClientAuthenticatedEvent $event) : void{
        $client = $event->getClient();
        $codec = $client->getProtocolCodec();
        foreach([
            new InventorySavePacket(),
            new InventoryConnectSessionConnectPacket()
        ] as $packet){
            $codec->registerPacket($packet->getPacketId(), $packet);
        }

        $packet = new InventoryConnectSessionConnectPacket();
        $packet->setSessionName($client->getClientName());
        $client->sendPacket($packet);
    }

    public function onSavingInventoryStart(int $xuid) : void{
        if(($player = XuidCore::getInstance()->getPlayer($xuid)) instanceof Player){
            $this->savingXuid[$xuid] = $player;
        }else{
            $this->savingXuid[$xuid] = true;
        }
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
        while($this->savingInventoryCountInLocal > 0){
            //NOTHING
        }
        $this->database->close();
    }

}