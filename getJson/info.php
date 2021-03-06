<?php
require 'sql.php';

$requestCounter = 0;
function checkRequestCounter($count)
{
    global $requestCounter;
    $requestCounter+=$count;

    $limitPerSecond = 20;
    $limitPer10Minutes = 100;

    if(($requestCounter % $limitPerSecond == 0) && ($requestCounter % $limitPer10Minutes != 0))
    {
        sleep(1);
    }
    if($requestCounter % $limitPer10Minutes == 0)
    {
        sleep(120);
    }
}

//League of Legends Key
$key='api_key=RGAPI-a319d88e-821c-41d6-8409-7b24c779380e';
//URL FOR EUNE SERVER
$host = "https://eun1.api.riotgames.com";

//LEAGUES 3 HIGHEST RANKS IN LEAGUE OF LEGENDS
$leagues = array();
$leagues["challenger"] = "/lol/league/v4/challengerleagues/by-queue/RANKED_SOLO_5x5";
$leagues["grandMaster"] = "/lol/league/v4/grandmasterleagues/by-queue/RANKED_SOLO_5x5";
$leagues["master"] = "/lol/league/v4/masterleagues/by-queue/RANKED_SOLO_5x5";

//QUESTION ABOUT SUMMONER
$getSummonerAccountId = "/lol/summoner/v4/summoners/";
//QUESTION ABOUT SUMMONER MATCH HISTORY (last 100 games)
$getSummonerMatchHistory = "/lol/match/v4/matchlists/by-account/";
//QUESTIONS FOR MATCH DETAILS
$getDetailsById = "/lol/match/v4/matches/";
$getTimelinesById = "/lol/match/v4/timelines/by-match/";

//CODE OF RANKED QUEUE
$soloRankedQueue = "?queue=420";

foreach ($leagues as $league) {

    $getSummonerId = file_get_contents($host.$league."?".$key);
    $objSummonerId = json_decode($getSummonerId);
    checkRequestCounter(1);

    $topPlayers = $objSummonerId -> entries;

    foreach($topPlayers as $topPlayer) {
        $topPlayerSummonerId = $topPlayer -> summonerId;

        //GET INFO ABOUT ACCOUNT id,accountId,puuid,name,profileIconId,revisionDate,summonerLevel
        $getSummoner = file_get_contents($host . $getSummonerAccountId . $topPlayerSummonerId . "?" . $key);
        $objSummoner = json_decode($getSummoner);
        checkRequestCounter(1);

        $accountId = $objSummoner->accountId;

        //GET INFO ABOUT ACCOUNT MATCH HISTORY matches[0-99](platformId,gameId,champion,queue,season,timestamp,role,lane),totalGames
        $getSummonerHistory = file_get_contents($host . $getSummonerMatchHistory . $accountId .$soloRankedQueue. "&" . $key);
        $objSummonerHistory = json_decode($getSummonerHistory);
        checkRequestCounter(1);

        $matches = $objSummonerHistory->matches;

        foreach ($matches as $match) {
            $gameId = $match -> gameId;
            $getGame -> execute();
            $getGame -> store_result();
            $getGame -> fetch();
            $gameRows = $getGame -> num_rows;

            if ($gameRows == 1) continue;
            $region = $match->platformId;
            $matchDate = date('Y-m-d H:i:s', ($match->timestamp) / 1000);

            //GET ACCOUNT MATCH DETAILS
            $getMatchDetails = file_get_contents($host . $getDetailsById . $gameId . "?" . $key);
            $objMatchDetails = json_decode($getMatchDetails);
            checkRequestCounter(1);

            $gameDuration = gmdate('i:s', $objMatchDetails->gameDuration);
            $patch = $objMatchDetails->gameVersion;

            //ADD DATA TO GAME TABLE
            $game->execute();

            //GET ACCOUNT MATCH TIMELINES
            $getMatchTimelines = file_get_contents($host . $getTimelinesById . $gameId . "?" . $key);
            $objMatchTimelines = json_decode($getMatchTimelines);
            checkRequestCounter(1);

            $participants = $objMatchDetails->participants;
            $frames = $objMatchTimelines->frames;

            foreach ($participants as $i => $participant) {

                $accountId = $objMatchDetails->participantIdentities[$i]->player->accountId;
                $summonerName = $objMatchDetails->participantIdentities[$i]->player->summonerName;
                $summoner->execute();

                $itemInfoJson = file_get_contents("http://ddragon.leagueoflegends.com/cdn/8.24.1/data/en_GB/item.json");
                $itemInfo = json_decode($itemInfoJson);

                for($position = 0;$position < 7; $position++)
                {
                  $items = "item".$position;
                  $item = $participant -> stats -> $items;
                  $depthItem = $itemInfo -> data -> $item -> depth;

                  if($depthItem)
                  {
                      if($depthItem > 1)
                      {
                          if($item != 0)
                          {
                              $playerItems -> execute();
                          }
                      }
                  }
                }

                $mainPerk = $participant->stats->perkPrimaryStyle;
                $subPerk = $participant->stats->perkSubStyle;
                $Perk1 = $participant->stats->perk0;
                $Perk2 = $participant->stats->perk1;
                $Perk3 = $participant->stats->perk2;
                $Perk4 = $participant->stats->perk3;
                $Perk5 = $participant->stats->perk4;
                $Perk6 = $participant->stats->perk5;
                //ADD PERK INFO TO DATABASE
                $playerRunes -> execute();

                $spell = $participant -> spell1Id;
                $playerSpells -> execute();

                $spell = $participant -> spell2Id;
                $playerSpells -> execute();

                $role = $participant->timeline->role;
                $lane = $participant->timeline->lane;

                if ($role == "DUO_SUPPORT")
                {
                    $role = "SUPPORT";
                }
                else
                {
                    $role = $lane;
                }

                if ($participant->teamId == 100) $team = "blue";
                else $team = "red";

                if ($participant->stats->win == "Win") $win = 1;
                else $win = 0;

                $kill = $participant->stats->kills;
                $death = $participant->stats->deaths;
                $assist = $participant->stats->assists;
                $championId = $participant->championId;

                $participantId = $i + 1;

                //ADD DATA TO PLAYERGAME TABLE
                $playerGame->execute();

                foreach ($frames as $j => $frame) {
                    $events = $frame->events;
                    foreach ($events as $event)
                    {
                        $type = $event->type;
                        if (($type == "ITEM_PURCHASED" || $type == "ITEM_SOLD" || $type == "ITEM_DESTROYED") && ($event->participantId) == $participantId)
                        {
                            $itemId = $event->itemId;
                            $itemTime = $event->timestamp;

                            $playerItemsHistory->execute();
                        }
                        elseif ($type == "SKILL_LEVEL_UP" && ($event->participantId) == $participantId)
                        {
                            $event->timestamp;
                            $skillSlot = $event->skillSlot;
                            $addPointTime = $event->timestamp;

                            $playerPoints->execute();
                        }
                    }
                    if ($j == count($frames) - 1) continue;
                    $participantFrame = $frame->participantFrames->$participantId;
                }
            }
        }
    }
}