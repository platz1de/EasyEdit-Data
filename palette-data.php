<?php

/**
 * Welcome to the properly worst script in history
 */

use pocketmine\network\mcpe\convert\GlobalItemTypeDictionary;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;

require_once("phar://PocketMine-MP.phar/vendor/autoload.php");

$pmmpRewrites = [
	"/(.*)door_hinge_bit=1(.*upper_block_bit=0.*)/" => "$1door_hinge_bit=0$2", "/(.*)open_bit=1(.*upper_block_bit=1.*)/" => "$1open_bit=0$2", "/(.*)direction=.(.*upper_block_bit=1.*)/" => "$1direction=0$2"
];
$bedrockData = [];
$ids = json_decode(getData("https://raw.githubusercontent.com/pmmp/BedrockData/master/block_id_map.json"), true, 512, JSON_THROW_ON_ERROR);
$reader = PacketSerializer::decoder(getData("https://raw.githubusercontent.com/pmmp/BedrockData/master/r12_to_current_block_map.bin"), 0, new PacketSerializerContext(GlobalItemTypeDictionary::getInstance()->getDictionary()));
$nbt = new NetworkNbtSerializer();
while (!$reader->feof()) {
	$id = $reader->getString();
	$meta = $reader->getLShort();

	$offset = $reader->getOffset();
	$state = $nbt->read($reader->getBuffer(), $offset)->mustGetCompoundTag();
	$reader->setOffset($offset);

	$fullName = $state->getString("name") . "[";
	$states = $state->getCompoundTag("states");
	if (count($states->getValue()) > 0) {
		$stateData = [];
		foreach ($states->getValue() as $name => $data) {
			$stateData[] = $name . "=" . $data->getValue();
		}
		$fullName .= implode(",", $stateData);
	}
	$fullName .= "]";

	foreach ($pmmpRewrites as $search => $replace) {
		$fullName = preg_replace($search, $replace, $fullName);
	}
	$fullName = preg_replace("/(\[),+|,+(])|(,),+/", "$1$2$3", $fullName);

	if (!isset($bedrockData[$fullName])) { //use the first one
		$bedrockData[$fullName] = $ids[$id] . ":" . $meta;
	}
}

//some meta values are in the following order for some reason:
//7, 1, 2, 3, 4, 5 / 15, 9, 10, 11, 12, 13
$current = "";
$savedKey = "";
$followsPattern = false;
$last = -1;
foreach ($bedrockData as $key => $value) {
	preg_match("/(.*)\[/", $key, $matches);
	$id = explode(":", $value);
	if ($current !== $matches[1]) {
		$current = $matches[1];
		if ((int) $id[1] !== 7) {
			$followsPattern = false;
			continue;
		}
		$followsPattern = true;
		$last = 6;
	} elseif (!$followsPattern) {
		continue;
	}

	if ((int) $id[1] !== ++$last) {
		$followsPattern = false;
		continue;
	}

	switch ((int) $id[1]) {
		case 7:
			$savedKey = $key;
			$last = 0;
			break;
		case 5:
			$bedrockData[$savedKey] = $id[0] . ":0";
			$last = 14;
			break;
		case 15:
			$savedKey = $key;
			$last = 8;
			break;
		case 13:
			$bedrockData[$savedKey] = $id[0] . ":8";
			$followsPattern = false;
			break;
	}
}

$bedrockMapping = [];
$javaMapping = [];
$javaToBedrock = json_decode(getData("https://raw.githubusercontent.com/PrismarineJS/minecraft-data/master/data/bedrock/1.17.10/blocksJ2B.json"), true, 512, JSON_THROW_ON_ERROR);
$bedrockToJava = json_decode(getData("https://raw.githubusercontent.com/PrismarineJS/minecraft-data/master/data/bedrock/1.17.10/blocksB2J.json"), true, 512, JSON_THROW_ON_ERROR);
$missingBedrock = [];
$missingJava = [];

$rewrites = [
	"/true/" => "1", "/false/" => "0", //Bedrock uses integers for booleans
	"/(.*)door_hinge_bit=1(.*upper_block_bit=0.*)/" => "$1door_hinge_bit=0$2", "/(.*)open_bit=1(.*upper_block_bit=1.*)/" => "$1open_bit=0$2", "/(.*)direction=.(.*upper_block_bit=1.*)/" => "$1direction=0$2", //Doors only save these in one part
	"/wall_post_bit=1/" => "wall_post_bit=0", //walls are handled really weirdly
	"/wall_connection_type_east=short/" => "wall_connection_type_east=none", "/wall_connection_type_east=tall/" => "wall_connection_type_east=none",
	"/wall_connection_type_north=short/" => "wall_connection_type_north=none", "/wall_connection_type_north=tall/" => "wall_connection_type_north=none",
	"/wall_connection_type_south=short/" => "wall_connection_type_south=none", "/wall_connection_type_south=tall/" => "wall_connection_type_south=none",
	"/wall_connection_type_west=short/" => "wall_connection_type_west=none", "/wall_connection_type_west=tall/" => "wall_connection_type_west=none"
];

$legacyRewrites = [
	"/(minecraft:note_block)\[instrument=harp,note=0,powered=true]/" => "$1[]",
	"/minecraft:dirt_path(.*)/" => "minecraft:grass_path$1",
	"/minecraft:oak_sign(.*)/" => "minecraft:sign$1",
	"/minecraft:oak_wall_sign(.*)/" => "minecraft:wall_sign$1",
	"/(.*)waterlogged=false(.*)/" => "$1$2",
	"/(.*)axis=y]/" => "$1]",
	"/(minecraft:iron_trapdoor)\[(.*)powered=false(.*)]/" => "$1[$2$3]"
];

//Waterlogging is weird
foreach ($javaToBedrock as $java => $bedrock) {
	foreach ($legacyRewrites as $search => $replace) {
		$java = preg_replace($search, $replace, $java);
	}
	$java = preg_replace("/(\[),+|,+(])|(,),+/", "$1$2$3", $java);
	$javaToBedrock[$java] = $bedrock;
}

foreach ($javaToBedrock as $java => $bedrock) {
	foreach ($rewrites as $search => $replace) {
		$bedrock = preg_replace($search, $replace, $bedrock);
	}
	$bedrock = preg_replace("/(\[),+|,+(])|(,),+/", "$1$2$3", $bedrock);
	if (isset($bedrockData[$bedrock])) {
		if (!isset($bedrockMapping[$java])) { //use first one
			if (str_ends_with($java, "[]")) {
				$bedrockMapping[substr($java, 0, -2)] = $bedrockData[$bedrock];
			}
			$bedrockMapping[$java] = $bedrockData[$bedrock];
		}
	} else {
		$missingJava[$java] = $bedrock;
	}
}
foreach ($bedrockToJava as $bedrock => $java) {
	foreach ($rewrites as $search => $replace) {
		$bedrock = preg_replace($search, $replace, $bedrock);
	}
	$bedrock = preg_replace("/(\[),+|,+(])|(,),+/", "$1$2$3", $bedrock);
	if (isset($bedrockData[$bedrock])) {
		if (!isset($javaMapping[$bedrockData[$bedrock]])) { //use first one
			$javaMapping[$bedrockData[$bedrock]] = $java;
		}
	} else {
		$missingBedrock[$bedrock] = $java;
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
echo "Matched " . count($bedrockMapping) . " java to bedrock (sources provide " . count($javaToBedrock) . " pairs and " . count($bedrockData) . " translations)" . PHP_EOL;
echo "Matched " . count($javaMapping) . " bedrock to java (sources provide " . count($bedrockToJava) . " pairs and " . count($bedrockData) . " translations)" . PHP_EOL;
uasort($bedrockMapping, $sort);
uksort($javaMapping, $sort);
file_put_contents("bedrock_palette.json", json_encode($bedrockMapping, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
file_put_contents("java_palette.json", json_encode($javaMapping, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
file_put_contents("debugData.json", json_encode([$missingJava, $missingBedrock, $bedrockData], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

$rotationData = [
	"north=true" => "east=true", "east=true" => "south=true", "south=true" => "west=true", "west=true" => "north=true", "north=false" => "east=false", "east=false" => "south=false", "south=false" => "west=false", "west=false" => "north=false",
	"facing=north" => "facing=east", "facing=east" => "facing=south", "facing=south" => "facing=west", "facing=west" => "facing=north",
	"axis=z" => "axis=x", "axis=x" => "axis=z",
	"rotation=0" => "rotation=4", "rotation=1" => "rotation=5", "rotation=2" => "rotation=6", "rotation=3" => "rotation=7", "rotation=4" => "rotation=8", "rotation=5" => "rotation=9", "rotation=6" => "rotation=10", "rotation=7" => "rotation=11", "rotation=8" => "rotation=12", "rotation=9" => "rotation=13", "rotation=10" => "rotation=14", "rotation=11" => "rotation=15", "rotation=12" => "rotation=0", "rotation=13" => "rotation=1", "rotation=14" => "rotation=2", "rotation=15" => "rotation=3"
];
$flipData = [
	"x" => [
		"east=true" => "west=true", "west=true" => "east=true", "east=false" => "west=false", "west=false" => "east=false",
		"facing=east" => "facing=west", "facing=west" => "facing=east",
		"rotation=1" => "rotation=15", "rotation=2" => "rotation=14", "rotation=3" => "rotation=13", "rotation=4" => "rotation=12", "rotation=5" => "rotation=11", "rotation=6" => "rotation=10", "rotation=7" => "rotation=9", "rotation=9" => "rotation=7", "rotation=10" => "rotation=6", "rotation=11" => "rotation=5", "rotation=12" => "rotation=4", "rotation=13" => "rotation=3", "rotation=14" => "rotation=2", "rotation=15" => "rotation=1",
	],
	"z" => [
		"north=true" => "south=true", "south=true" => "north=true", "north=false" => "south=false", "south=false" => "north=false",
		"facing=north" => "facing=south", "facing=south" => "facing=north",
		"rotation=0" => "rotation=8", "rotation=1" => "rotation=7", "rotation=2" => "rotation=6", "rotation=3" => "rotation=5", "rotation=5" => "rotation=3", "rotation=6" => "rotation=2", "rotation=7" => "rotation=1", "rotation=8" => "rotation=0", "rotation=9" => "rotation=15", "rotation=10" => "rotation=14", "rotation=11" => "rotation=13", "rotation=13" => "rotation=11", "rotation=14" => "rotation=10", "rotation=15" => "rotation=9"
	],
	"y" => [
		"up=true" => "down=true", "down=true" => "up=true", "up=false" => "down=false", "down=false" => "up=false",
		"facing=up" => "facing=down", "facing=down" => "facing=up",
		"half=upper" => "half=lower", "half=lower" => "half=upper", //slabs
		"half=bottom" => "half=top", "half=top" => "half=bottom", //stairs, why 2 different names mojang???
	],
];
$rotations = [];
$flipX = [];
$flipZ = [];
$flipY = [];
foreach ($bedrockMapping as $state => $id) {
	if (!str_ends_with($state, "]")) {
		continue; //no properties
	}
	remapProperties($state, $id, $rotationData, $bedrockMapping, $rotations);
	remapProperties($state, $id, $flipData["x"], $bedrockMapping, $flipX);
	remapProperties($state, $id, $flipData["z"], $bedrockMapping, $flipZ);
	remapProperties($state, $id, $flipData["y"], $bedrockMapping, $flipY);
}

file_put_contents("rotation-data.json", json_encode($rotations, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
file_put_contents("flip-data.json", json_encode(["xAxis" => $flipX, "zAxis" => $flipZ, "yAxis" => $flipY], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

function remapProperties(string $state, string $id, array $remaps, array $bedrockMapping, array &$save)
{
	if (isset($save[$id])) {
		return;
	}
	preg_match("/(.*)\[(.*?)]/", $state, $matches);
	$properties = explode(",", $matches[2]);
	foreach ($properties as $i => $property) {
		$properties[$i] = $remaps[$property] ?? $property;
	}
	sort($properties);
	$newState = $matches[1] . "[" . implode(",", $properties) . "]";
	if ($newState === $state) {
		return null;
	}
	if (isset($bedrockMapping[$newState])) {
		$save[$id] = $bedrockMapping[$newState];
	} else {
		echo "Missing rotation for $id ($state) -> $newState" . PHP_EOL;
	}
}

#Constructing data from java WorldEdit data files
$blockData = json_decode(getData("https://raw.githubusercontent.com/EngineHub/WorldEdit/master/worldedit-core/src/main/resources/com/sk89q/worldedit/world/registry/legacy.json"), true)["blocks"];
$bedrockData = json_decode(file_get_contents("bedrock_palette.json"), true, 512, JSON_THROW_ON_ERROR);

$toBedrock = [];
foreach ($blockData as $legacyId => $state) {
	getReadableBlockState($state); //WorldEdit doesn't have a proper order in state properties, so we need to sort them
	if (isset($bedrockData[$state])) {
		if ($bedrockData[$state] !== $legacyId) {
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