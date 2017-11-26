<?php
namespace Megapix96;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\item\Item;
use pocketmine\level\Position;
use pocketmine\level\sound\AnvilFallSound;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\CallbackTask;
use pocketmine\utils\Config;
use pocketmine\entity\Attribute;
use Megapix96\Color;

class Main extends PluginBase implements Listener{

    public $red = 0, $blue = 0,
           $redHp = 75, $blueHp = 75,
           $team = [],

    	   $settingsConfig, $settings, $positionConfig, $position,
    	   $redCoreTap, $blueCoreTap, $joinBlockTap;

    private $moneyAPI;

    public function onEnable() {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        if(!file_exists($this->getDataFolder())) @mkdir($this->getDataFolder(), 0721, true);

        $positionData = ["x" => 0, "y" => 0, "z" => 0, "level" => $this->getServer()->getDefaultLevel()->getName()];
        $this->settingsConfig = new Config($this->getDataFolder() . "settings.json", Config::JSON, [
            "enable" => false,
            "popup" => false,
            "money" => false,
            "money.api" => "EconomyAPI",
            "money.kill" => false,
            "money.kill.amount" => 100,
            "money.win" => false,
            "money.win.amount" => 2500,
            "death.keep.inventory" => false,
            "death.keep.effects" => false,
            "death.keep.exp" => false,
            "teamchat" => true,
            "teamchat.str" => "@",
            "shutdown" => false
        ]);

        $this->positionConfig = new Config($this->getDataFolder() . "positions.json", Config::JSON, [
            "respawn.red" => $positionData,
            "respawn.blue" => $positionData,
            "core.red" => $positionData,
            "core.blue" => $positionData,
            "join.pos" => $positionData,
            "lobby.pos" => $positionData
        ]);

        foreach ($this->settingsConfig->getAll() as $key => $value) {
            $this->settings[$key] = $value;
        }

        foreach ($this->positionConfig->getAll() as $key => $value) {
            if (empty ($this->getServer()->getLevelByName($value["level"]))) {
                $this->getLogger()->critical("world名が異常です!");
                $this->getServer()->getPluginManager()->disablePlugin($this);
            }

            $this->position[$key] = $this->toPosition($value);
            $this->getServer()->loadLevel($value["level"]);
        }

        $this->getLogger()->info("§a初めてこのプラグインを入れた方への解説");
        $this->getLogger()->info("§9まずコマンドを実行しよう!");
        $this->getLogger()->info("§bOP側が設定するコマンド:");
        $this->getLogger()->info("§e/setredres §fこのコマンドを入力したプレイヤーのいる地点が§cRedTeam§fのリスポーン地点になります。");
        $this->getLogger()->info("§e/setblueres §fこのコマンドを入力したプレイヤーのいる地点が§9BlueTeam§fのリスポーン地点になります。");
        $this->getLogger()->info("§e/setredcore §fこのコマンドを入力したあとに、ブロックをTapすると そのブロックが§cRedTeam§fのコアになります。");
        $this->getLogger()->info("§e/setbluecore §fこのコマンドを入力したあとに、ブロックをTapすると そのブロックが§9BlueTeam§fのコアになります。");
        $this->getLogger()->info("§e/setjoinblock §fこのコマンドを入力したあとに、ブロックをTapすると そのブロックをTapすると試合に参加出来るようになります。");
        $this->getLogger()->info("§e/setlobbypos §fこのコマンドを入力したプレイヤーのいる地点が§6Lobby§fとなります。");
        $this->getLogger()->info("§fその後に§bSettings.json§f のEnableを§atrue§fに変えてください。");
        $this->getLogger()->info("§f※§bSettings.json§f のEnable以外はどちらでも良いです");

        if (!($this->settings["enable"])) {
            $this->getLogger()->alert("§cEnable が falseだった為プラグインを終了します。");
            //$this->getServer()->getPluginManager()->disablePlugin($this);
        }

        if ($this->settings["money"]) {
        	$api = $this->settings["money.api"];
            if ($api === "EconomyAPI" or $api === "PocketMoney") {
                if (($this->moneyAPI = $this->getServer()->getPluginManager()->getPlugin($api)) != null) {
                    $this->getLogger()->info("§a" . $api . "を読み込みました!");
                } else {
                    $this->getLogger()->warning("§c" . $api . "が見つかりませんでした!");
                    $this->getServer()->getPluginManager()->disablePlugin($this);
                }
            } else {
                $this->getLogger()->warning("§c" . $api . " というAPIには対応しておりません。");
                $this->getServer()->getPluginManager()->disablePlugin($this);
            }
        }

        if ($this->settings['popup']) {
            $this->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask([$this, 'popup'], []), 20 * 1.5);
        }
     Color::init();
    }

    public function onLogin(PlayerLoginEvent $ev){
       if (!($this->settings["enable"])) return;
       $p = $ev->getPlayer();
       $n = $p->getName();
       $this->team[$n] = false;
    }

    public function onJoin(PlayerJoinEvent $ev) {
        if (!($this->settings["enable"])) return;
        $p = $ev->getPlayer();
        $n = $p->getName();
        $this->returnToHub($p, false);
    }

    public function onQuit(PlayerQuitEvent $ev) {
        if (!($this->settings["enable"])) return;
        $p = $ev->getPlayer();
        if ($this->team[$p->getName()] === "Red") {
            $this->red--;
        } else if ($this->team[$p->getName()] === "Blue") {
            $this->blue--;
        }
        $this->team[$p->getName()] = false;
    }

    public function onCommand(CommandSender $p, Command $c, $l, array $a) {
        if ($p instanceof ConsoleCommandSender) {
            $this->getLogger()->critical("ゲーム内で実行してください");
            return false;
        }

        if (!$p->isOp() && strtolower($c->getName()) !== "hub") {
            $p->sendMessage("§cOP権限がありません！");
            return false;
        }

        switch (strtolower($c->getName())) {
            case "setredres":
                $this->position["respawn.red"] = $p->getPosition();
                $this->positionConfig->set("respawn.red", $this->toArray($this->position["respawn.red"]));
                $this->positionConfig->save();
                $p->sendMessage("RedTeamのRespawn地点をセットしました");
                break;

            case "setblueres":
                $this->position["respawn.blue"] = $p->getPosition();
                $this->positionConfig->set("respawn.blue", $this->toArray($this->position["respawn.blue"]));
                $this->positionConfig->save();
                $p->sendMessage("BlueTeamのRespawn地点をセットしました");
                break;

            case "setredcore":
                $this->redCoreTap[$p->getName()] = true;
                $p->sendMessage("RedのCoreブロックをTapしてください...");
                break;

            case "setbluecore":
                $this->blueCoreTap[$p->getName()] = true;
                $p->sendMessage("BlueのCoreブロックをTapしてください...");
                break;

            case "setjoinblock":
                $this->joinBlockTap[$p->getName()] = true;
                $p->sendMessage("Join用ブロックをTapしてください...");
                break;

            case "setlobbypos":
                $this->position["lobby.pos"] = $p->getPosition();
                $this->positionConfig->set("lobby.pos", $this->toArray($this->position["lobby.pos"]));
                $this->positionConfig->save();
                $p->sendMessage("Lobbyをセットしました");
                break;

            case 'hub':
                $p->sendMessage("§b5秒後にHubへと戻ります...");
                $this->getServer()->getScheduler()->scheduleDelayedTask(new CallbackTask([$this, 'returnToHub'], [$p]), 20 * 5);
                break;
        }

        return true;
    }

    public function onDamage(EntityDamageEvent $ev) {
        if (!($this->settings["enable"])) return;
        $p = $ev->getEntity();
        if ($ev instanceof EntityDamageByEntityEvent) {
            if ($this->team[$p->getName()] !== false) {
                if ($this->team[$ev->getDamager()->getName()] === $this->team[$p->getName()]) {
                    $ev->setCancelled();
                } else {
                    if ($p->getHealth() - round($ev->getFinalDamage()) <= 0 && !($ev->isCancelled())) {
                        $ev->setCancelled();
                        $p->setHealth(20);
                        $p->setFood(20);
                        if (!$this->settings["death.keep.exp"]) {
                            $this->setXpProgress($p,0);
                        }
                        if (!$this->settings["death.keep.inventory"]) {
                            $p->getInventory()->clearAll();
                        }
                        if (!$this->settings["death.keep.effects"]) {
                            $p->removeAllEffects();
                        }
                        $pos = $this->team[$p->getName()] === "Red" ? $this->position["respawn.red"] : $this->position["respawn.blue"];
                        $p->teleport($pos);
                        $p->sendMessage("死んでしまったので、復活しました");
                        $this->setDefaultArmor($p);
                    }
                    if ($this->settings['money.kill']) {
                        $d = $ev->getDamager();
                        if ($this->settings['money.api'] === "EconomyAPI") {
                            $this->moneyAPI->addMoney($d->getName(), $this->settings['money.kill.amount']);
                        } else {
                            $this->moneyAPI->grantMoney($d->getName(), $this->settings['killmoney.money']);
                        }
                        $d->sendPopup("§e+ " . $this->settings['money.kill.amount'] . " coins!");
                    }
                }
            }
         } else {
            if($p->getHealth() - round($ev->getFinalDamage()) <= 0 && !($ev->isCancelled())) {
                $ev->setCancelled();
                $p->setHealth(20);
                $p->setFood(20);
                if (!$this->settings["death.keep.exp"]) {
                    $this->setXpProgress($p,0);
                }
                if (!$this->settings["death.keep.inventory"]) {
                    $p->getInventory()->clearAll();
                }
                if (!$this->settings["death.keep.effects"]) {
                    $p->removeAllEffects();
                }
                $pos = $this->team[$p->getName()] === "Red" ? $this->position["respawn.red"] : $this->position["respawn.blue"];
                $p->teleport($pos);
                $p->sendMessage("死んでしまったので、復活しました");
                $this->setDefaultArmor($p);
            }
        }
    }

    public function onBreak(BlockBreakEvent $ev) {
        if (!($this->settings["enable"])) return;
        $p = $ev->getPlayer();
        $b = $ev->getBlock();
        $n = $p->getName();
        $redCore = $this->position["core.red"];
        $blueCore = $this->position["core.blue"];
        if ($redCore->equals($b)) {
            $ev->setCancelled();
            if ($this->team[$n] === "Blue") {
                $this->redHp--;
                $this->getServer()->broadcastMessage("§cRed §aのコアが §6" . $n . " §aにより壊されています! §a| §e" . $this->redHp . " / 75");
                $c = $this->position["core.red"];
                $p->getLevel()->addSound(new AnvilFallSound($redCore));
                if ($this->redHp <= 0) {
                    $this->endGame("Blue");
                }
            }
        }else if ($blueCore->equals($b)) {
            $ev->setCancelled();
            if ($this->team[$n] === "Red") {
                $this->blueHp--;
                $this->getServer()->broadcastMessage("§9Blue §aのコアが §6".$n." §aにより壊されています! §a| §e" . $this->blueHp . " / 75");
                $c = $this->position["core.blue"];
                $p->getLevel()->addSound(new AnvilFallSound($blueCore));
                if ($this->blueHp <= 0) {
                    $this->endGame("Red");
                }
            }
        }
    }

    public function onChat(PlayerChatEvent $ev) {
        if ($this->settings["teamchat"]) {
            if (strpos($ev->getMessage(), $this->settings["teamchat.str"]) !== false) {
                $ev->setCancelled();
                foreach ($this->getServer()->getOnlinePlayers() as $p){
                    if ($this->team[$p->getName()] === $this->team[$ev->getPlayer()->getName()]) {
                        $p->sendMessage("<".$ev->getPlayer()->getDisplayName()." : TEAM> ".$ev->getMessage());
                    }
                }
            }
        }
    }

    public function onInteract(PlayerInteractEvent $ev) {
        $p = $ev->getPlayer();
        $n = $p->getName();
        $b = $ev->getBlock();
        if ($this->settings["enable"]) {
            if ($b->equals($this->position["join.pos"])) {
                if (empty ($this->team[$n])) {
                    if ($this->red < $this->blue) {
                        $this->red++;
                        $this->team[$n] = "Red";
                        $p->sendMessage("§aあなたのチームは §cRed §aチームです");
                    } else if ($this->red > $this->blue) {
                        $this->blue++;
                        $this->team[$n] = "Blue";
                        $p->sendMessage("§aあなたのチームは §9Blue チームです");
                    } else {
                        if (rand (0,1) === 0) {
                            $this->red++;
                            $this->team[$n] = "Red";
                            $p->sendMessage("§aあなたのチームは §cRed §aチームです");
                        } else {
                            $this->blue++;
                            $this->team[$n] = "Blue";
                            $p->sendMessage("§aあなたのチームは §9Blue チームです");
                        }
                    }
                    if ($this->team[$n] === "Red") 
                        $p->teleport($this->position["respawn.red"]);
                    else 
                        $p->teleport($this->position["respawn.blue"]);

                    $this->setDefaultArmor($p);
                }
            }
        }
        if (!$p->isOp()) return;
        if (isset ($this->redCoreTap[$n])) {
            $this->position["core.red"] = $b->asPosition();
            $this->positionConfig->set("core.red", $this->toArray($this->position["core.red"]));
            $this->positionConfig->save();
            unset ($this->redCoreTap[$p->getName()]);
            $p->sendMessage("RedのCoreの位置をセットしました");
        } else if (isset ($this->blueCoreTap[$n])) {
            $this->position["core.blue"] = $b->asPosition();
            $this->positionConfig->set("core.blue", $this->toArray($this->position["core.blue"]));
            $this->positionConfig->save();
            unset ($this->blueCoreTap[$p->getName()]);
            $p->sendMessage("BlueのCoreの位置をセットしました");
        } else if (isset ($this->joinBlockTap[$n])) {
            $this->position["join.pos"] = $b->asPosition();
            $this->positionConfig->set("join.pos", $this->toArray($this->position["join.pos"]));
            $this->positionConfig->save();
            unset ($this->joinBlockTap[$p->getName()]);
            $p->sendMessage("Join用ブロックの位置をセットしました");
        }
    }

    private function endGame(string $win) {
        $this->getServer()->broadcastMessage("§a" . $win . " チームの勝利!!");
        $pos = $this->position["lobby.pos"];
        foreach ($this->getServer()->getOnlinePlayers() as $p) {
            if ($this->settings["money"] && $this->settings["money.win"]) {
                if ($this->team[$p->getName()] === $win) {
                    $p->sendMessage("§e".$this->settings["money.win.amount"]." coin 獲得!");
                    if ($this->settings['money.api'] === "EconomyAPI") {
                        $this->moneyAPI->addMoney($p->getName(), $this->settings['money.win.amount']);
                    } else {
                        $this->moneyAPI->grantMoney($p->getName(), $this->settings['money.win.amount']);
                    }
                }
            }
            $p->teleport($pos);
            $p->setHealth(20);
            $p->setFood(20);
            $this->setXpProgress($p,0);
            $this->setXpLevel($p,0);
            $p->getInventory()->clearAll();
            $p->removeAllEffects();
        }
        $this->red = 0;
        $this->blue = 0;
        $this->redHp = 75;
        $this->blueHp = 75;
        $this->team = [];
        if ($this->settings["shutdown"]) {
            $this->getServer()->shutdown();
        }
    }

    public function popup(){
        if (!($this->settings["enable"])) return;
        $this->getServer()->broadcastPopup("          §cRed | §6Join:" . $this->red . " §aHP:" . $this->redHp . "\n          §9Blue | §6Join:" . $this->blue . " §aHP:" . $this->blueHp);
    }

    private function returnToHub(Player $p, $sendMessage = true) {
        if ($this->team[$p->getName()] === "Red") {
            $this->red--;
            $p->setDisplayName($p->getName());
            $p->setNameTag($p->getName());
        } else if ($this->team[$p->getName()] === "Blue") {
            $this->blue--;
            $p->setDisplayName($p->getName());
            $p->setNameTag($p->getName());
        }
        $p->setHealth(20);
        $p->setFood(20);
        $this->setXpProgress($p,0);
        $this->setXpLevel($p,0);
        $p->getInventory()->clearAll();
        $p->removeAllEffects();
        $this->team[$p->getName()] = false;
        $pos = $this->position["lobby.pos"];
        $p->teleport($pos);
        if ($sendMessage) {
            $p->sendMessage("§bHubに戻りました!");
        }
    }

    private function setDefaultArmor(Player $p) {
        $team = $this->team[$p->getName()];
        for ($i = 0; $i <= 3; $i++) {
            $item = Item::get(298 + $i);
            if ($team === "Red") {
                $item = $this->setCustomColor($item, Color::getRGB(255,0,0));
            } else if($team === "Blue") {
                $item = $this->setCustomColor($item, Color::getRGB(0,0,255));
            }
            $p->getInventory()->setArmorItem($i, $item);
        }
        $p->getInventory()->sendArmorContents($p);
    }

    //非対応sourceに対応
    public function setCustomColor(Item $item, Color $color) : Item {
        if(($hasTag = $item->hasCompoundTag())){
            $tag = $item->getNamedTag();
        }else{
            $tag = new CompoundTag("", []);
        }
        $tag->customColor = new IntTag("customColor", $color->getColorCode());
        $item->setCompoundTag($tag);

        return $item;
    }

    public function setXpProgress($p,$value){
       $p->getAttributeMap()->getAttribute(Attribute::EXPERIENCE)->setValue($value);
    }

    public function setXpLevel($p,$value){
       $p->getAttributeMap()->getAttribute(Attribute::EXPERIENCE_LEVEL)->setValue($value);
    }

    private function toPosition(array $configPosition) : Position {
        return new Position($configPosition["x"], $configPosition["y"], $configPosition["z"], $this->getServer()->getLevelByName($configPosition["level"]));
    }

    private function toArray(Position $position) : array {
        return ["x" => (int) $position->x, "y" => (int) $position->y, "z" => (int) $position->z, "level" => $position->getLevel()->getName()];
    }
}