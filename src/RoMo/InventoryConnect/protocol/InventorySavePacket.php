<?php

declare(strict_types=1);

namespace RoMo\InventoryConnect\protocol;

use alemiz\sga\codec\StarGatePacketHandler;
use alemiz\sga\protocol\StarGatePacket;
use alemiz\sga\protocol\types\PacketHelper;
use RoMo\InventoryConnect\InventoryConnect;

class InventorySavePacket extends StarGatePacket{

    const START = 0;
    const END = 1;

    /** @var string */
    private string $clientName;

    /** @var int */
    private int $status;
    private int $xuid;

    public function encodePayload() : void{
        PacketHelper::writeString($this, $this->clientName);
        PacketHelper::writeInt($this, $this->status);
        PacketHelper::writeLong($this, $this->xuid);
    }
    public function decodePayload() : void{
        $this->clientName = PacketHelper::readString($this);
        $this->status = PacketHelper::readInt($this);
        $this->xuid = PacketHelper::readLong($this);
    }
    public function getPacketId() : int{
        return 0x1a;
    }

    /**
     * @return string
     */
    public function getClientName() : string{
        return $this->clientName;
    }

    /**
     * @param string $clientName
     */
    public function setClientName(string $clientName) : void{
        $this->clientName = $clientName;
    }

    /**
     * @return int
     */
    public function getStatus() : int{
        return $this->status;
    }

    /**
     * @param int $status
     */
    public function setStatus(int $status) : void{
        $this->status = $status;
    }

    /**
     * @return int
     */
    public function getXuid() : int{
        return $this->xuid;
    }

    /**
     * @param int $xuid
     */
    public function setXuid(int $xuid) : void{
        $this->xuid = $xuid;
    }

    public function handle(StarGatePacketHandler $handler) : bool{
        if($this->status === self::START){
            InventoryConnect::getInstance()->onSavingInventoryStart($this->xuid);
        }elseif($this->status === self::END){
            InventoryConnect::getInstance()->onSavingInventoryEnd($this->xuid);
        }
        return true;
    }
}