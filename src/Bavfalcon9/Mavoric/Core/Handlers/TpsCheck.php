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
namespace Bavfalcon9\Mavoric\Core\Handlers;
use Bavfalcon9\Mavoric\Mavoric;
use Bavfalcon9\Mavoric\Main;
use Bavfalcon9\Mavoric\Core\Handlers\TPS\RepeatingAsyncTask;
use Bavfalcon9\Mavoric\Core\Handlers\TPS\CheckTask;
use Bavfalcon9\Mavoric\Core\Handlers\TPS\HaltedTask;
use Bavfalcon9\Mavoric\Tasks\ViolationCheck;
use pocketmine\scheduler\Task;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;

class TpsCheck {
    private $plugin;
    private $mavoric;
    private $checks;
    private $tps;
    private $measuredTPS = 20;
    private $ticks = [];
    private $skipped = 0;
    private $halted = false;
    private $task;
    private $checkTask;

    public function __construct(Main $plugin, Mavoric $mavoric) {
        $this->mavoric = $mavoric;
        $this->plugin = $plugin;
        $this->registerCheck();
    }

    private function registerCheck() {
        $this->start();
        $this->tps = 20;
    }

    public function postTick(float $diff) {
        $diff === round($diff, 3);
        $tps = 20 - $diff;
        $this->ticks[] = $tps;

        if ($tps > $this->mavoric->settings->getTpsWarnValue() && $this->isHalted()) {
            $this->cancelHalt();
        }

        if ($tps < $this->mavoric->settings->getTpsWarnValue()) {
            if ($this->halted) return;
            $this->mavoric->messageStaff(Mavoric::FATAL, 'Server running lower than usual! TPS: ' . $tps);
        }

        if ($tps <= $this->mavoric->settings->getTpsStopValue()) {
            if ($this->halted) return;
            $this->setHalted($tps);
            $this->mavoric->messageStaff(Mavoric::FATAL, 'Pausing detections. A message will be prompted when detections are re-enabled.');
            return;
        }
    }

    private function setHalted(float $tps = 0) {
        $waitUntil = $tps * 20;
        #$this->stop();
        $this->halted = true;
        $this->checkTask->remove();
        #$this->plugin->getScheduler()->scheduleDelayedTask(new HaltedTask($this), $waitUntil);
        return;
    }

    /**
     * Halts occur when the TPS is below 15 or is spiking.
     */
    public function isHalted(): Bool {
        return $this->halted;
    }

    public function cancelHalt() {
        #$this->start();
        $this->checkTask = $this->plugin->getScheduler()->scheduleRepeatingTask(new ViolationCheck($this->mavoric), 20);
        $this->mavoric->messageStaff(Mavoric::INFORM, 'No longer pausing detections.');
        $this->halted = false;
    }

    public function isLow(): Bool {
        if ($this->isHalted()) return true;
        if ($this->tps <= 17) return true;
        else return false;
    }

    public function stop(): Bool {
        if ($this->task) {
            $this->task->remove();
            $this->task = null;
            $this->checkTask->remove();
            return true;
        } else {
            return false;
        }
    }

    public function getAverageTPS(): int {
        $avg = 20;
        foreach ($this->ticks as $tick) {
            $avg += $tick;
        }

        $avg = $avg / sizeof($this->ticks);
        return $avg;
    }

    public function start(): Bool {
        if ($this->task) {
            return false;
        } else {
            $this->task = $this->plugin->getScheduler()->scheduleRepeatingTask(new RepeatingAsyncTask($this), 20);
            $this->checkTask = $this->plugin->getScheduler()->scheduleRepeatingTask(new ViolationCheck($this->mavoric), 20);
            return true;
        }
    }

    public function runAsyncTask() {
        $this->plugin->getServer()->getAsyncPool()->submitTask(new CheckTask($this->plugin->getServer()->getTick(), function ($server, $diff) {
                $mavoric = $server->getPluginManager()->getPlugin('Mavoric');
                if (!$mavoric) {
                    return;
                } else {
                    $mavoric->mavoric->getTpsCheck()->postTick($diff);
                }
            })
        );
    }
}