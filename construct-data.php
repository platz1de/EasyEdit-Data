<?php

#Constructing data from java WorldEdit data files
$blockData = json_decode(getData("https://raw.githubusercontent.com/EngineHub/WorldEdit/master/worldedit-core/src/main/resources/com/sk89q/worldedit/world/registry/legacy.json"), true)["blocks"];
$bedrockData = json_decode(file_get_contents("bedrock_palette.json"), true, 512, JSON_THROW_ON_ERROR);

$toBedrock = [];
foreach ($blockData as $legacyId => $state) {
	getReadableBlockState($state); //WorldEdit doesn't have a proper order in state properties, so we need to sort them
	if (isset($bedrockData[$state])) {
		if($bedrockData[$state] !== $legacyId){
			$toBedrock[$legacyId] = $bedrockData[$state];
		}
	} else {
		echo "Missing bedrock data for $state" . PHP_EOL;
	}
}

$sort = static function ($a, $b) {
	$idA = explode(":", $a)[0];
	$idB = explode(":", $b)[0];
	if ($idA !== $idB) {
		return $idA - $idB;
	}
	return explode(":", $a)[1] - explode(":", $b)[1];
};
uksort($toBedrock, $sort);
file_put_contents("bedrock-conversion-map.json", json_encode($toBedrock, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

function getData(string $url)
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_AUTOREFERER, true);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	$data = curl_exec($ch);
	curl_close($ch);
	return $data;
}

function getReadableBlockState(string &$state)
{
	if (!str_ends_with($state, "]")) {
		return; //no properties
	}
	preg_match("/(.*)\[(.*?)]/", $state, $matches);
	$properties = explode(",", $matches[2]);
	sort($properties);
	$state = $matches[1] . "[" . implode(",", $properties) . "]";
}
