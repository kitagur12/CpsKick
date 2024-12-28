<?php

namespace cpskick;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\scheduler\Task;
use SplQueue;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;
use pocketmine\event\server\DataPacketReceiveEvent;

class Main extends PluginBase implements Listener {

    private array $clickQueues = [];
    private array $times = [];

    public function onEnable(): void {
        $this->saveDefaultConfig();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getScheduler()->scheduleRepeatingTask(new class($this) extends Task {
            private $plugin;
            public function __construct($plugin)
            {
                $this->plugin = $plugin;
            }
            public function onRun(): void
            {
                foreach ($this->plugin->getServer()->getOnlinePlayers() as $player) {
                    $this->plugin->removeOldClicks($player);
                    $this->plugin->updateActionBar($player);
                }
            }
        }, 0);
    }

    public function handleDataPacketReceive(DataPacketReceiveEvent $event): void
    {
        $packet = $event->getPacket();
        $player = $event->getOrigin()->getPlayer();

        if ($packet instanceof InventoryTransactionPacket) {
            $trData = $packet->trData;

            if ($trData instanceof UseItemOnEntityTransactionData) {
                $actionType = $trData->getActionType();
                if ($actionType === 1) {
                    $player = $event->getOrigin()->getPlayer();
                    if ($player === null) {
                        return;
                    }
                    $this->registerClick($player);
                }
            }
        }
    }

    public function registerClick($player): void
    {
        $playerName = $player->getName();
        $currentTime = microtime(true);

        if (!isset($this->clickQueues[$playerName])) {
            $this->clickQueues[$playerName] = new SplQueue();
        }

        $this->clickQueues[$playerName]->enqueue($currentTime);

        $this->removeOldClicks($player);
    }

    public function removeOldClicks($player): void
    {
        $playerName = $player->getName();
        $currentTime = microtime(true);

        if (!isset($this->clickQueues[$playerName])) {
            return;
        }

        while (!$this->clickQueues[$playerName]->isEmpty()) {
            $clickTime = $this->clickQueues[$playerName]->bottom();
            if (($currentTime - $clickTime) >= 1.0) {
                $this->clickQueues[$playerName]->dequeue();
            } else {
                break;
            }
        }
    }

    public function getCPS($player): int
    {
        $playerName = $player->getName();

        if (!isset($this->clickQueues[$playerName])) {
            return 0;
        }

        return $this->clickQueues[$playerName]->count();
    }

    public function updateActionBar($player): void
    {
        $cps = self::getCPS($player);
        $playerName = $player->getName();
        $config = $this->getConfig();
        $cpslimit = $config->get("limit_cps", "20");
        if ($cps > $cpslimit) {
            $kickmessage = $config->get("kickmessage", "cps_kicked.");
            
            $player->kick($kickmessage);  
        } else {
            $this->times[$playerName] = 0;
        }
    }
}
