<?php

/**
 * Welcome to the properly worst script in history
 */

use pocketmine\math\Axis;
use pocketmine\math\Facing;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\network\mcpe\convert\GlobalItemTypeDictionary;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;
use Ramsey\Uuid\Uuid;

error_reporting(E_ALL);

const BEDROCK_VERSION = "1.19.1";
const JAVA_VERSION = "1.19";

try {
	require_once("phar://PocketMine-MP.phar/vendor/autoload.php");
} catch (Throwable) {
	echo "Drop a valid PocketMine Phar into the generation folder";
	return;
}

$repo = json_decode(file_get_contents("../dataRepo.json"), true, 512, JSON_THROW_ON_ERROR);
$repo["version"] = BEDROCK_VERSION . "-" . Uuid::uuid4();
file_put_contents("../dataRepo.json", json_encode($repo, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$bedrockData = getBedrockData();
array_multisort(array_values($bedrockData), SORT_NATURAL, array_keys($bedrockData), SORT_NATURAL, $bedrockData);
file_put_contents("debug/source-data.json", json_encode($bedrockData, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

$bedrockMapping = [];
$javaMapping = [];
$geyserMapping = json_decode(getData("https://raw.githubusercontent.com/GeyserMC/mappings/master/blocks.json"), true, 512, JSON_THROW_ON_ERROR);
$missingBedrock = [];
$missingJava = [];

$rewrites = yaml_parse_file("manual-rewrites.yml");

$bedrockSourceCount = 0;
foreach ($geyserMapping as $javaId => $data) {
	if (!str_ends_with($javaId, "]")) {
		$javaId .= "[]";
	}

	$stateData = [];
	foreach ($data["bedrock_states"] ?? [] as $type => $value) {
		$stateData[] = $type . "=" . match ($value) {
				true => "1",
				false => "0",
				default => $value
			};
	}
	sort($stateData);
	$bedrockState = $data["bedrock_identifier"] . "[" . implode(",", $stateData) . "]";
	$bedrockSourceCount++;

	foreach ($rewrites["bedrock"] as $search => $replace) {
		$bedrockState = preg_replace($search, $replace, $bedrockState);
	}
	$bedrockState = preg_replace("/(\[),+|,+(])|(,),+/", "$1$2$3", $bedrockState);
	if (isset($bedrockData[$bedrockState])) {
		if (!isset($bedrockMapping[$javaId])) { //use first one
			if (str_ends_with($javaId, "[]")) {
				$bedrockMapping[substr($javaId, 0, -2)] = $bedrockData[$bedrockState];
			}
			$bedrockMapping[$javaId] = $bedrockData[$bedrockState];
		}
	} else {
		$missingJava[$javaId] = $bedrockState;
	}

	$reliabilityOverwrites = [
		"/(.*)\[]/" => "$1", //java chokes on empty state data
		"/(.*chest)(\[.*type=)(?:left|right)(.*])/" => "$1$2single$3" //part of tiles in bedrock (converted separately)
	];
	foreach ($reliabilityOverwrites as $search => $replace) {
		$javaId = preg_replace($search, $replace, $javaId);
	}
	$javaId = preg_replace("/(\[),+|,+(])|(,),+/", "$1$2$3", $javaId);

	if (isset($bedrockMapping[$javaId])) {
		if (!isset($javaMapping[$bedrockMapping[$javaId]])) { //use first one
			$javaMapping[$bedrockMapping[$javaId]] = $javaId;
		}
	} else {
		$missingBedrock[$bedrockState] = $javaId;
	}
}

foreach ($rewrites["java"] as $search => $replace) {
	foreach ($bedrockMapping as $java => $bedrock) {
		$java = preg_replace($search, $replace, $java);
		$java = preg_replace("/(\[),+|,+(])|(,),+/", "$1$2$3", $java);
		if (str_ends_with($java, "[]")) {
			$bedrockMapping[substr($java, 0, -2)] = $bedrock;
		}
		$bedrockMapping[$java] = $bedrock;
	}
}

$javaMapping[$bedrockData["minecraft:invisible_bedrock[]"]] = "minecraft::barrier";
$javaMapping["8:0"] = "minecraft:water";
$javaMapping["10:0"] = "minecraft:lava";

array_multisort(array_values($bedrockMapping), SORT_NATURAL, array_keys($bedrockMapping), SORT_NATURAL, $bedrockMapping);
array_multisort(array_keys($javaMapping), SORT_NATURAL, array_values($javaMapping), SORT_NATURAL, $javaMapping);

$currentState = "";
foreach ($bedrockMapping as $java => $id) {
	preg_match("/(.*)\[(.*?)]/", $java, $matches);
	if (!isset($matches[1])) {
		continue;
	}
	if ($matches[1] !== $currentState && !isset($bedrockMapping[$matches[1]])) {
		$currentState = $matches[1];
		$bedrockMapping[$matches[1]] = $id;
		$defaultStates[$matches[1]] = $java;
	}
}

array_multisort(array_values($bedrockMapping), SORT_NATURAL, array_keys($bedrockMapping), SORT_NATURAL, $bedrockMapping);

file_put_contents("debug/defaultStates.json", json_encode($defaultStates, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
file_put_contents("debug/missingBedrock.json", json_encode($missingBedrock, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
file_put_contents("debug/missingJava.json", json_encode($missingJava, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

echo "Matched " . count($bedrockMapping) . " java blocks to bedrock (sources provide " . $bedrockSourceCount . " pairs and " . count($bedrockData) . " translations), " . count($missingBedrock) . " not found" . PHP_EOL;
echo "Matched " . count($javaMapping) . " bedrock blocks to java (sources provide " . $bedrockSourceCount . " pairs and " . count($bedrockData) . " translations), " . count($missingJava) . " not found" . PHP_EOL;
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
$missingRotations = [];
foreach ($bedrockMapping as $state => $id) {
	if (!str_ends_with($state, "]")) {
		continue; //no properties
	}
	remapProperties($state, $id, $rotationData, $bedrockMapping, $rotations, $missingRotations);
	remapProperties($state, $id, $flipData["x"], $bedrockMapping, $flipX, $missingRotations);
	remapProperties($state, $id, $flipData["z"], $bedrockMapping, $flipZ, $missingRotations);
	remapProperties($state, $id, $flipData["y"], $bedrockMapping, $flipY, $missingRotations);
}
file_put_contents("debug/missingRotations.json", json_encode($missingRotations, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
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

function remapProperties(string $state, string $id, array $remaps, array $bedrockMapping, array &$save, array &$missing)
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
		$missing["$id ($state)"] = $newState;
	}
}

$blockData = json_decode(getData("https://raw.githubusercontent.com/PrismarineJS/minecraft-data/master/data/pc/common/legacy.json"), true)["blocks"];

$toBedrock = [];
$missingData = [];
foreach ($blockData as $legacyId => $state) {
	getReadableBlockState($state); //WorldEdit doesn't have a proper order in state properties, so we need to sort them
	if (isset($bedrockMapping[$state])) {
		if ($bedrockMapping[$state] !== $legacyId) {
			$toBedrock[$legacyId] = $bedrockMapping[$state];
		}
	} else {
		$missingData[$legacyId] = $state;
	}
}

array_multisort(array_keys($toBedrock), SORT_NATURAL, array_values($toBedrock), SORT_NATURAL, $toBedrock);
file_put_contents("../bedrock-conversion-map.json", json_encode($toBedrock, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
file_put_contents("debug/missingData.json", json_encode($missingData, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

$rawItems = json_decode(getData("https://raw.githubusercontent.com/CloudburstMC/Data/master/runtime_item_states.json"), true);
$itemData = json_decode(getData("https://raw.githubusercontent.com/pmmp/BedrockData/master/item_id_map.json"), true);
$bedrockItemData = json_decode(getData("https://raw.githubusercontent.com/PrismarineJS/minecraft-data/master/data/bedrock/" . BEDROCK_VERSION . "/items.json"), true);
$javaItemData = json_decode(getData("https://raw.githubusercontent.com/PrismarineJS/minecraft-data/master/data/pc/" . JAVA_VERSION . "/items.json"), true);
$rewrites = json_decode(getData("https://raw.githubusercontent.com/pmmp/BedrockData/master/r16_to_current_item_map.json"), true);
$bedrockItems = [];
$itemMapping = [];
$missingItems = [];

foreach ($rawItems as $data) {
	if (!isset($itemData[$data["name"]])) {
		$itemData[$data["name"]] = $data["id"];
	}
}

foreach ($itemData as $name => $id) {
	if(($itemData[$name] ?? null) !== $id) {
		continue;
	}
	if (isset($rewrites["simple"][$name])) {
		unset($itemData[$name]);
		$itemData[$rewrites["simple"][$name]] = $id . ":0";
	} elseif (isset($rewrites["complex"][$name])) {
		foreach ($rewrites["complex"][$name] as $i => $variation) {
			$itemData[$variation] = $id . ":" . $i;
		}
		$itemData[$name] = $id . ":0";
	} else {
		$itemData[$name] .= ":0";
	}
}

foreach ($bedrockItemData as $item) {
	if (isset($bedrockItems[$item["id"]])) {
		continue;
	}
	$bedrockItems[$item["id"]] = $item["name"];
	if (isset($item["variations"])) {
		foreach ($item["variations"] as $variation) {
			$bedrockItems[$variation["id"]] = $variation["name"];
			$itemData["minecraft:" . $variation["name"]] = substr($itemData["minecraft:" . $item["name"]], 0, -2) . ":" . $variation["metadata"];
		}
	}
}

foreach ($javaItemData as $item) {
	$name = "minecraft:" . $item["name"];
	if (!isset($bedrockItems[$item["id"]])) {
		$missingItems[$name] = $item["id"];
		continue;
	}
	$bedrockState = "minecraft:" . $bedrockItems[$item["id"]];

	if (isset($itemData[$bedrockState])) {
		$itemMapping[$name] = $itemData[$bedrockState];
	} else if (isset($bedrockMapping[$name])) {
		$id = explode(":", $bedrockMapping[$name]);
		$itemMapping[$name] = ($id[0] > 255 ? 255 - $id[0] : $id[0]) . ":" . $id[1];
	} else {
		$missingItems[$name] = $bedrockState;
	}
}

array_multisort(array_values($itemMapping), SORT_NATURAL, array_keys($itemMapping), SORT_NATURAL, $itemMapping);
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
	$bedrockData = [];
	$ids = json_decode(getData("https://raw.githubusercontent.com/pmmp/BedrockData/master/block_id_map.json"), true, 512, JSON_THROW_ON_ERROR);
	$reader = PacketSerializer::decoder(getData("https://raw.githubusercontent.com/pmmp/BedrockData/master/r12_to_current_block_map.bin"), 0, new PacketSerializerContext(GlobalItemTypeDictionary::getInstance()->getDictionary()));
	$nbt = new NetworkNbtSerializer();
	$ignore = yaml_parse_file("ignore-pmmp.yml");
	while (!$reader->feof()) {
		$id = $reader->getString();
		$meta = $reader->getLShort();

		$offset = $reader->getOffset();
		$state = $nbt->read($reader->getBuffer(), $offset)->mustGetCompoundTag();
		$reader->setOffset($offset);

		foreach ($ignore as $find => $rule) {
			if (preg_match($find, $id) && in_array($meta, $rule, true)) {
				continue 2;
			}
		}

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

		if (str_contains($fullName, "light_block")) {
			continue; //completely wrongly mapped (as end rods?? and only level 14)
		}

		if (!isset($bedrockData[$fullName])) { //use the first one
			$bedrockData[$fullName] = $ids[$id] . ":" . $meta;
		} else {
			echo "Duplicate block: " . $fullName . " (" . $id . " " . $ids[$id] . ":" . $meta . " " . $bedrockData[$fullName] . ")\n";
		}
	}

	$pastR16 = json_decode(file_get_contents("past-1.16-mappings.json"), true, 512, JSON_THROW_ON_ERROR);

	//Generate potential mappings from CloudBurst data
	$potentialMappings = [];
	$serializer = new BigEndianNbtSerializer();
	$statesMap = $serializer->read(gzdecode(getData("https://raw.githubusercontent.com/CloudburstMC/Data/master/block_palette.nbt")));
	$idMap = json_decode(getData("https://raw.githubusercontent.com/CloudburstMC/Data/master/legacy_block_ids.json"), true, 512, JSON_THROW_ON_ERROR);
	$meta = 0;
	$current = "";
	$skipped = false;
	foreach ($statesMap->mustGetCompoundTag()->getListTag("blocks") as $tag) {
		$name = $tag->getString("name");
		if ($current !== $name) {
			$current = $name;
			$meta = 0;
			$skipped = false;
		}

		$states = [];
		foreach ($tag->getTag("states") as $property => $state) {
			$states[] = $property . "=" . $state->getValue();
		}
		$state = $name . "[" . implode(",", $states) . "]";

		if (!$skipped) {
			foreach ($pastR16["__skip"] as $skip => $goal) {
				if (preg_match($skip, $state)) {
					$skipped = true;
					$meta = $goal;
				}
			}
		}

		if (!isset($bedrockData[$state]) && $meta <= 15) {
			$potentialMappings[$state] = $idMap[$name] . ":" . $meta;
		}
		$meta++;
	}

	foreach ($pastR16["__auto"] as $find) {
		$set = 0;
		foreach ($potentialMappings as $state => $replace) {
			if (!isset($bedrockData[$state]) && preg_match($find, $state)) {
				$bedrockData[$state] = $replace;
				unset($potentialMappings[$state]);
				$set++;
			}
		}
		if ($set === 0) {
			echo "NOTICE: $find is not needed\n";
		}
	}
	unset($pastR16["__comment"], $pastR16["__auto"], $pastR16["__skip"]);
	foreach ($pastR16 as $state => $id) {
		if (isset($bedrockData[$state])) {
			echo "WARNING: $state is already mapped to $bedrockData[$state]\n";
		} elseif (!isset($potentialMappings[$state])) {
			echo "WARNING: $state is not in potential state data\n";
		} else {
			unset($potentialMappings[$state]);
			$bedrockData[$state] = $id;
		}
	}

	file_put_contents("debug/potentialMappings.json", json_encode($potentialMappings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
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