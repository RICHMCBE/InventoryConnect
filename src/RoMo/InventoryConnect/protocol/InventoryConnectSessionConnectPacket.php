<?php

declare(strict_types=1);

namespace RoMo\InventoryConnect\protocol;

use alemiz\sga\protocol\StarGatePacket;
use alemiz\sga\protocol\types\PacketHelper;

class InventoryConnectSessionConnectPacket extends StarGatePacket{

    private string $sessionName;

    public function encodePayload() : void{
        PacketHelper::writeString($this, $this->sessionName);
    }
    public function decodePayload() : void{
        //NOTHING
    }

    public function getPacketId() : int{
        return 0x1b;
    }

    /**
     * @param string $sessionName
     */
    public function setSessionName(string $sessionName) : void{
        $this->sessionName = $sessionName;
    }
}