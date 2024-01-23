<?php

declare(strict_types=1);

namespace RoMo\InventoryConnect\protocol;

use alemiz\sga\codec\StarGatePacketHandler;
use alemiz\sga\protocol\StarGatePacket;
use alemiz\sga\protocol\types\PacketHelper;

class InventorySavePacket extends StarGatePacket{

    const START = 0;
    const END = 1;

    /** @var int */
    private int $status;
    private int $xuid;

    public function encodePayload() : void{
        PacketHelper::writeInt($this, $this->status);
        PacketHelper::writeLong($this, $this->xuid);
    }
    public function decodePayload() : void{
        $this->status = PacketHelper::readInt($this);
        $this->xuid = PacketHelper::readLong($this);
    }
    public function getPacketId() : int{
        return 0x0e;
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
        var_dump($this->status);
        var_dump($this->xuid);
        return true;
    }
}