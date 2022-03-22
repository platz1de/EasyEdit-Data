<?php

/**
 * Welcome to the properly worst script in history
 */

use pocketmine\math\Axis;
use pocketmine\math\Facing;
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

$bedrockMapping = [];
$javaMapping = [];
$javaToBedrock = json_decode(getData("https://raw.githubusercontent.com/PrismarineJS/minecraft-data/master/data/bedrock/1.18.11/blocksJ2B.json"), true, 512, JSON_THROW_ON_ERROR);
$bedrockToJava = json_decode(getData("https://raw.githubusercontent.com/PrismarineJS/minecraft-data/master/data/bedrock/1.18.11/blocksB2J.json"), true, 512, JSON_THROW_ON_ERROR);
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

//these rules are really important to make everything work
$reliabilityOverwrites = [
	"/(.*)\[]/" => "$1",
	"/(.*chest)(\[.*type=)(?:left|right)(.*])/" => "$1$2single$3"
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
	foreach ($reliabilityOverwrites as $search => $replace) {
		$java = preg_replace($search, $replace, $java);
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

/**
 * Some properties of blocks are defined with tiles in bedrock while the blockstate is used in java,
 * they need to be remapped in a special way, allowing full conversion
 */
$tileStates = [];
$javaTileStates = [];
foreach ($bedrockMapping as $state => $id) {
	preg_match("/(.*)\[(.*?)]/", $state, $matches);
	if (!isset($matches[2])) {
		continue;
	}
	$properties = explode(",", $matches[2]);
	if (str_ends_with($matches[1], "chest")) {
		$facing = null;
		$type = null;
		foreach ($properties as $property) {
			if (str_starts_with($property, "type=")) {
				$type = str_replace("type=", "", $property);
			}
			if (str_starts_with($property, "facing=")) {
				$facing = str_replace("facing=", "", $property);
			}
		}
		if ($facing === null || $type === null || $type === "single") {
			continue;
		}
		$facing = ["north" => Facing::NORTH, "east" => Facing::EAST, "south" => Facing::SOUTH, "west" => Facing::WEST][$facing];
		$identifier = Facing::toString(Facing::rotate($facing, Axis::Y, $type === "left"));
		$tileStates["chest_relation"][$state] = $identifier;
		$javaTileStates["chest_relation"][$javaMapping[$id]][$identifier] = $state;
	} elseif (str_ends_with($matches[1], "shulker_box")) {
		foreach ($properties as $property) {
			if (str_starts_with($property, "facing=")) {
				$tileStates["shulker_box_facing"][$state] = str_replace("facing=", "", $property);
				$javaTileStates["shulker_box_facing"][$javaMapping[$id]][str_replace("facing=", "", $property)] = $state;
			}
		}
	} elseif (!str_ends_with($matches[1], "piston_head") && (str_ends_with($matches[1], "head") || str_ends_with($matches[1], "skull"))) {
		$tileStates["skull_type"][$state] = preg_replace("/(_wall)?(_head|_skull|minecraft:)/", "", $matches[1]);
		$javaTileStates["skull_type"][$javaMapping[$id]][preg_replace("/(_wall)?(_head|_skull|minecraft:)/", "", $matches[1])] = $state;
		foreach ($properties as $property) {
			if (str_starts_with($property, "rotation=")) {
				$tileStates["skull_rotation"][$state] = str_replace("rotation=", "", $property);
				$javaTileStates["skull_rotation"][$javaMapping[$id]][preg_replace("/(_wall)?(_head|_skull|minecraft:)/", "", $matches[1])][str_replace("rotation=", "", $property)] = $state;
			}
		}
	}
}

foreach ($javaTileStates["skull_rotation"] as $placeholder1 => $data) {
    foreach ($data as $placeholder2 => $value) {
        $javaTileStates["skull_rotation"][$javaTileStates["skull_type"][$placeholder1][$placeholder2]] = $value;
    }
}

file_put_contents("rotation-data.json", json_encode($rotations, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
file_put_contents("flip-data.json", json_encode(["xAxis" => $flipX, "zAxis" => $flipZ, "yAxis" => $flipY], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
file_put_contents("tile-data-states.json", json_encode($tileStates, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
file_put_contents("java-tile-states.json", json_encode($javaTileStates, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

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
	//These are really weird
	if (str_ends_with($matches[1], "wall") || str_ends_with($matches[1], "fire") || str_ends_with($matches[1], "vine")) {
		if (in_array("down=true", $properties, true)) {
			$properties[array_search("down=true", $properties)] = "up=false";
		}
		if (in_array("down=false", $properties, true)) {
			$properties[array_search("down=false", $properties)] = "up=true";
		}
	}
	sort($properties);
	$newState = $matches[1] . "[" . implode(",", $properties) . "]";
	if ($newState === $state) {
		return null;
	}
	if (isset($bedrockMapping[$newState])) {
		if ($id !== $bedrockMapping[$newState]) {
			$save[$id] = $bedrockMapping[$newState];
		}
	} else {
		echo "Missing rotation for $id ($state) -> $newState" . PHP_EOL;
	}
}

$blockData = json_decode(getData("https://raw.githubusercontent.com/PrismarineJS/minecraft-data/master/data/pc/common/legacy.json"), true)["blocks"];
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