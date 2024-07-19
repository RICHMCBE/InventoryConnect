<?php

declare(strict_types=1);

namespace RoMo\InventoryConnect\event;

use pocketmine\event\player\PlayerEvent;
use pocketmine\player\Player;

class CreateNewInventoryEvent extends PlayerEvent{
    public function __construct(Player $player){
        $this->player = $player;
    }
}