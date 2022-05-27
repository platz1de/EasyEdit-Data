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

error_reporting(E_ALL);

const BEDROCK_VERSION = "1.18.11";
const JAVA_VERSION = "1.18";

try {
	require_once("phar://PocketMine-MP.phar/vendor/autoload.php");
} catch (Throwable) {
	echo "Drop a valid PocketMine Phar into the generation folder";
	return;
}

file_put_contents("../dataVersion", file_get_contents("../dataVersion") + 1);

$bedrockData = getBedrockData();
array_multisort(array_values($bedrockData), SORT_NATURAL, array_keys($bedrockData), SORT_NATURAL, $bedrockData);
file_put_contents("debug/source-data.json", json_encode($bedrockData, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

$bedrockMapping = [];
$javaMapping = [];
$javaToBedrock = json_decode(getData("https://raw.githubusercontent.com/PrismarineJS/minecraft-data/master/data/bedrock/" . BEDROCK_VERSION . "/blocksJ2B.json"), true, 512, JSON_THROW_ON_ERROR);
$bedrockToJava = json_decode(getData("https://raw.githubusercontent.com/PrismarineJS/minecraft-data/master/data/bedrock/" . BEDROCK_VERSION . "/blocksB2J.json"), true, 512, JSON_THROW_ON_ERROR);
$javaToBedrockNonLegacy = $javaToBedrock;
$missingBedrock = [];
$missingJava = [];

$rewrites = yaml_parse_file("manual-rewrites.yml");

foreach ($rewrites["java"] as $search => $replace) {
	foreach ($javaToBedrock as $java => $bedrock) {
		$java = preg_replace($search, $replace, $java);
		$java = preg_replace("/(\[),+|,+(])|(,),+/", "$1$2$3", $java);
		$javaToBedrock[$java] = $bedrock;
	}
}

foreach ($javaToBedrock as $java => $bedrock) {
	foreach ($rewrites["bedrock"] as $search => $replace) {
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

//these rules are really important to make everything work
$reliabilityOverwrites = [
	"/(.*)\[]/" => "$1",
	"/(.*chest)(\[.*type=)(?:left|right)(.*])/" => "$1$2single$3"
];

foreach ($bedrockToJava as $bedrock => $java) {
	foreach ($reliabilityOverwrites as $search => $replace) {
		$java = preg_replace($search, $replace, $java);
	}
	$java = preg_replace("/(\[),+|,+(])|(,),+/", "$1$2$3", $java);
	if (isset($bedrockMapping[$java])) {
		if (!isset($javaMapping[$bedrockMapping[$java]])) { //use first one
			$javaMapping[$bedrockMapping[$java]] = $java;
		}
	} else {
		$missingBedrock[$bedrock] = $java;
	}
}

$javaMapping[$bedrockData["minecraft:invisible_bedrock[]"]] = "minecraft::barrier";

array_multisort(array_values($bedrockMapping), SORT_NATURAL, array_keys($bedrockMapping), SORT_NATURAL, $bedrockMapping);
array_multisort(array_keys($javaMapping), SORT_NATURAL, array_values($javaMapping), SORT_NATURAL, $javaMapping);

file_put_contents("debug/missingBedrock.json", json_encode($missingBedrock, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
file_put_contents("debug/missingJava.json", json_encode($missingJava, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

echo "Matched " . count($bedrockMapping) . " java blocks to bedrock (sources provide " . count($javaToBedrockNonLegacy) . " pairs and " . count($bedrockData) . " translations), " . count($missingBedrock) . " not found" . PHP_EOL;
echo "Matched " . count($javaMapping) . " bedrock blocks to java (sources provide " . count($bedrockToJava) . " pairs and " . count($bedrockData) . " translations), " . count($missingJava) . " not found" . PHP_EOL;
file_put_contents("../bedrock_palette.json", json_encode($bedrockMapping, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
file_put_contents("../java_palette.json", json_encode($javaMapping, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

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
file_put_contents("../rotation-data.json", json_encode($rotations, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
file_put_contents("../flip-data.json", json_encode(["xAxis" => $flipX, "zAxis" => $flipZ, "yAxis" => $flipY], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

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

file_put_contents("../tile-data-states.json", json_encode($tileStates, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
file_put_contents("../java-tile-states.json", json_encode($javaTileStates, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

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

$toBedrock = [];
foreach ($blockData as $legacyId => $state) {
	getReadableBlockState($state); //WorldEdit doesn't have a proper order in state properties, so we need to sort them
	if (isset($bedrockMapping[$state])) {
		if ($bedrockMapping[$state] !== $legacyId) {
			$toBedrock[$legacyId] = $bedrockMapping[$state];
		}
	} else {
		echo "Missing bedrock data for $state" . PHP_EOL;
	}
}

array_multisort(array_keys($toBedrock), SORT_NATURAL, array_values($toBedrock), SORT_NATURAL, $toBedrock);
file_put_contents("../bedrock-conversion-map.json", json_encode($toBedrock, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

$bedrockItemData = json_decode(getData("https://raw.githubusercontent.com/PrismarineJS/minecraft-data/master/data/bedrock/" . BEDROCK_VERSION . "/items.json"), true);
$javaItemData = json_decode(getData("https://raw.githubusercontent.com/PrismarineJS/minecraft-data/master/data/pc/" . JAVA_VERSION . "/items.json"), true);
$javaItems = [];
$itemMapping = [];
$missingItems = [];

foreach ($javaItemData as $item) {
	$javaItems[$item["id"]] = $item["name"];
}

foreach ($bedrockItemData as $item) {
	if (isset($javaItems[$item["id"]])) {
		$itemMapping[$item["name"]] = $javaItems[$item["id"]];
	} else {
		$missingItems[$item["id"]] = $item["name"];
	}
	if (isset($item["variations"])) {
		foreach ($item["variations"] as $variation) {
			if (isset($javaItems[$item["id"]])) {
				$itemMapping[$variation["name"]] = $javaItems[$variation["id"]];
			} else {
				$missingItems[$variation["id"]] = $variation["name"];
			}
		}
	}
}

foreach ($itemMapping as $bedrockName => $javaName) {
	if ($bedrockName === $javaName) {
		unset($itemMapping[$bedrockName]);
	}
}

file_put_contents("../bedrock-item-map.json", json_encode($itemMapping, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
file_put_contents("debug/missingItems.json", json_encode($missingItems, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

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

/**
 * @return array
 * @throws JsonException
 */
function getBedrockData(): array
{
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
	return $bedrockData;
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