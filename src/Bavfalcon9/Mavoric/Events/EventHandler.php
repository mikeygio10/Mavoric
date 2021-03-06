<?php
/***
 *      __  __                       _      
 *     |  \/  |                     (_)     
 *     | \  / | __ ___   _____  _ __ _  ___ 
 *     | |\/| |/ _` \ \ / / _ \| '__| |/ __|
 *     | |  | | (_| |\ V / (_) | |  | | (__ 
 *     |_|  |_|\__,_| \_/ \___/|_|  |_|\___|
 *                                          
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Lesser General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 * 
 *  @author Bavfalcon9
 *  @link https://github.com/Olybear9/Mavoric                                  
 */

namespace Bavfalcon9\Mavoric\Events;

use pocketmine\event\Listener;
use pocketmine\Player;

use pocketmine\entity\projectile\Projectile;
use pocketmine\item\TieredTool;
use Bavfalcon9\Mavoric\Mavoric;
use pocketmine\math\Vector3;
use pocketmine\event\{
    server\DataPacketSendEvent,
    server\DataPacketReceiveEvent,
    block\BlockBreakEvent,
    entity\EntityDamageByEntityEvent,
    entity\EntityDamageByChildEntityEvent,
    entity\EntityTeleportEvent,
    entity\ProjectileLaunchEvent,
    inventory\InventoryTransactionEvent,
    player\PlayerInteractEvent,
    player\PlayerMoveEvent
};
use pocketmine\network\mcpe\protocol\{
    DataPacket,
    BatchPacket,
    InventoryTransactionPacket,
    LevelSoundEventPacket,
    PacketPool,
    PlayerActionPacket
};
use Bavfalcon9\Mavoric\Events\{
    player\InventoryTransaction,
    player\PlayerAttack,
    player\PlayerDamage,
    player\PlayerBreakBlock,
    player\PlayerClick,
    player\PlayerMove,
    player\PlayerTeleport,
    packet\PacketRecieve,
    packet\PacketSend
};

class EventHandler implements Listener {
    private $mavoric;
    private $plugin;
    private $breakTimes = [];

    public function __construct(Mavoric $mavoric) {
        $this->mavoric = $mavoric;
        $this->plugin = $mavoric->getPlugin();
        $mavoric->getServer()->getPluginManager()->registerEvents($this, $this->plugin);
    }

    /** 
     * PLAYER
     */

    /** @deprecated - Broken */
    public function onInteract(PlayerInteractEvent $event): void {
        if ($event->getAction() === PlayerInteractEvent::LEFT_CLICK_BLOCK) {
            $this->breakTimes[$event->getPlayer()->getName()] = floor(microtime(true) * 20);
        }
        $e = new PlayerClick($event, $this->mavoric, $event->getPlayer(), $event->getAction(), $event->getItem(), $event->getBlock(), $event->getTouchVector(), $event->getFace());
        $this->mavoric->broadcastEvent($e);
        return;
    }

    public function onTransaction(InventoryTransactionEvent $event): void {
        $transaction = $event->getTransaction();
        $player = $transaction->getSource();

        if (!$player instanceof Player) return;
        $e = new InventoryTransaction($event, $this->mavoric, $player, $transaction);
        $this->mavoric->broadcastEvent($e);
    }

    /**
     * PACKETS
     */

    public function onRecievePacket(DataPacketReceiveEvent $event): void {
        if ($event->getPacket() instanceof BatchPacket) {
            foreach ($event->getPacket()->getPackets() as $buf) {
                try {
                    $pk = PacketPool::getPacket($buf);
                    $this->mavoric->broadcastEvent(new PacketRecieve($event, $this->mavoric, $event->getPlayer(), $pk, true));
                } catch (\Throwable $e) {
                    continue;
                }
            }
            return;
        } else {
            $this->mavoric->broadcastEvent(new PacketRecieve($event, $this->mavoric, $event->getPlayer(), $event->getPacket()));
            return;
        }
    }

    public function onSendpacket(DataPacketSendEvent $event): void {
        try {
            if ($event->getPacket() instanceof BatchPacket) {
                try {
                    foreach ($event->getPacket()->getPackets() as $buf) {
                        try {
                            $pk = PacketPool::getPacket($buf);
                            $this->mavoric->broadcastEvent(new PacketSend($event, $this->mavoric, $event->getPlayer(), $pk, true));
                        } catch (\Throwable $e) {
                            continue;
                        }
                    }
                    return;
                } catch (\Throwable $e) {
                    return;
                }
            } else {
                $this->mavoric->broadcastEvent(new PacketSend($event, $this->mavoric, $event->getPlayer(), $event->getPacket()));
                return;
            }
        } catch (\Throwable $e) {
            return;
        }
    }

    /**
     * @author Zedstar16 - (ClickChecks)
     * To Do: Add more Events.
     */
    public function onClickCheck(DataPacketReceiveEvent $event): void {
        if ($event->getPacket()::NETWORK_ID === InventoryTransactionPacket::NETWORK_ID) {
            if ($event->getPacket()->transactionType === InventoryTransactionPacket::TYPE_USE_ITEM_ON_ENTITY) {
                $this->makeClickEvent($event, $event->getPlayer(), false, false, true);
            }
        } else if ($event->getPacket()::NETWORK_ID === LevelSoundEventPacket::NETWORK_ID) {
            if ($event->getPacket()->sound === LevelSoundEventPacket::SOUND_ATTACK_NODAMAGE) {
                $this->makeClickEvent($event, $event->getPlayer(), true, false, true);
            }
        } 
    }

    private function makeClickEvent($event, Player $player, $leftClick, $break, $entity, $touch = null, $face = 0): void {
        $type = 0;
        if ($leftClick && $break) $type = 0;
        if ($leftClick && !$break) $type = 2;
        if (!$entity && !$leftClick) $type = 3;
        if (!$leftClick && $entity) $type = 4;

        if ($touch === null) {
            $touch = new Vector3(abs($player->x), abs($player->y), abs($player->z));
        }
        $item = $player->getInventory()->getItemInHand();
        $block = $player->getLevel()->getBlock($touch);
        $e = new PlayerClick($event, $this->mavoric, $player, $type, $item, $block, $touch, $face);
        $this->mavoric->broadcastEvent($e);
    }

    public function onPlayerMove(PlayerMoveEvent $event): void {
        $this->mavoric->broadcastEvent(new PlayerMove($event, $this->mavoric, $event->getPlayer(), $event->getFrom(), $event->getTo()));
        return;
    }
    
    public function onPlayerTeleport(EntityTeleportEvent $event): void {
        if (!$event->getEntity() instanceof Player) {
            return;
        }
        
        $this->mavoric->broadcastEvent(new PlayerTeleport($event, $this->mavoric, $event->getEntity(), $event->getFrom(), $event->getTo()));
        return;
    }


    /**
     * ENTITIES
     */
    public function onEntityDamage(EntityDamageByEntityEvent $event): void {
        $damager = $event->getDamager();
        $entity = $event->getEntity();
        $isProjectile = ($entity instanceof Projectile);

        if ($damager instanceof Player) {
            if (!$event instanceof EntityDamageByChildEntityEvent) {
                $this->mavoric->broadcastEvent(new PlayerAttack($event, $this->mavoric, $damager, $entity, $isProjectile));
            }
        }

        if ($entity instanceof Player) {
            $this->mavoric->broadcastEvent(new PlayerDamage($event, $this->mavoric, $damager, $entity, $isProjectile)); 
        }
    }


    /**
     * BLOCKS
     */
    public function onBlockBreak(BlockBreakEvent $event) {
        $player = $event->getPlayer();
        $block = $event->getBlock();
        $item = $event->getItem();
        $time = $this->getBreakTime($player);

        $this->mavoric->broadcastEvent(new PlayerBreakBlock($event, $this->mavoric, $player, $block, $time));
        return;
    }
    

    /**
     * Checks by Mavoric
     */

    /**
     * The following code was referenced from https://github.com/pmmp/AntiInstaBreak
     */
    private function getBreakTime(Player $player): int {
        if (!isset($this->breakTimes[$player->getName()])) {
            return -1;
        }

        $calculatedTime = ceil(microtime(true) * 20) - $this->breakTimes[$player->getName()];
        unset($this->breakTimes[$player->getName()]);
        
        return $calculatedTime;
    }

}
