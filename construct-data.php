<?php

#Constructing data from McEdit-United data files
$dataSources = json_decode(getData("https://raw.githubusercontent.com/Podshot/MCEdit-Unified/master/mcver/mcver.json"), true);

$bedrockNameRemaps = [
	"Chiseled Quartz Block" => "Chiseled Quartz Block (Upright)",
	"Purpur Slab" => "Purpur Slab (Bottom)",
	"Structure Block (Data)" => "Structure Block",
	"Path" => "Grass Path",
	"Coarse" => "Coarse Dirt",
	"Double Purpur Slabs" => "Purpur Double Slab",
	"Beetroot (Age 1)" => "Beetroot (Age 2)", "Beetroot (Age 2)" => "Beetroot (Age 4)", "Beetroot (Age 3)" => "Beetroot (Age 7 (Max))",
	"Structure Void" => "tile.217.name",
	"Standing Banner (East-NorthEast)" => "Standing Banner Facing ENE", "Standing Banner (NorthEast)" => "Standing Banner Facing NE", "Standing Banner (East-SouthEast)" => "Standing Banner Facing ESE", "Standing Banner (East)" => "Standing Banner Facing East", "Standing Banner (South-SouthEast)" => "Standing Banner Facing SSE", "Standing Banner (SouthEast)" => "Standing Banner Facing SE", "Standing Banner (South-SouthWest)" => "Standing Banner Facing SSW", "Standing Banner (South)" => "Standing Banner Facing South", "Standing Banner (West-SouthWest)" => "Standing Banner Facing WSW", "Standing Banner (SouthWest)" => "Standing Banner Facing SW", "Standing Banner (West-NorthWest)" => "Standing Banner Facing WNW", "Standing Banner (West)" => "Standing Banner Facing West", "Standing Banner (North-NorthWest)" => "Standing Banner Facing NNW", "Standing Banner (NorthWest)" => "Standing Banner Facing NW", "Standing Banner (North-NorthEast)" => "Standing Banner Facing NNE", "Standing Banner (North)" => "Standing Banner Facing North",
	"Wall Banner (North)" => "Wall Banner Facing North", "Wall Banner (South)" => "Wall Banner Facing South", "Wall Banner (West)" => "Wall Banner Facing West", "Wall Banner (East)" => "Wall Banner Facing East",
	"Red Sandstone Double Slab (Seamed)" => "Red Sandstone Double Slab",
	"Double Plant (Upper Half, East)" => "Large Fern (Upper Half)", "Double Plant (Upper Half, North)" => "Double Tallgrass (Upper Half)", "Double Plant (Upper Half, West)" => "Lilac (Upper Half)", "Double Plant (Upper Half, South)" => "Sunflower (Upper Half)"
];
$javaNameRemaps = [
	"Chiseled Quartz Block (Upright)" => "Chiseled Quartz Block", "Chiseled Quartz Block (East/West)" => "Chiseled Quartz Block", "Chiseled Quartz Block (North/South)" => "Chiseled Quartz Block", //why do these even have rotation?
	"Structure Block" => "Structure Block (Data)",
	"Grass Path" => "Path",
	"Coarse Dirt" => "Coarse",
	"Shulker Box" => "Purple Shulker Box",
	"Purpur Slab (Bottom)" => "Purpur Slab",
	"Purpur Double Slab" => "Double Purpur Slabs",
	"Beetroot (Age 1)" => "Beetroot (Age 0)", "Beetroot (Age 2)" => "Beetroot (Age 1)", "Beetroot (Age 3)" => "Beetroot (Age 1)", "Beetroot (Age 4)" => "Beetroot (Age 2)", "Beetroot (Age 5)" => "Beetroot (Age 2)", "Beetroot (Age 6)" => "Beetroot (Age 2)", "Beetroot (Age 7 (Max))" => "Beetroot (Age 3)",
	"tile.217.name" => "Structure Void",
	"Standing Banner Facing ENE" => "Standing Banner (East-NorthEast)", "Standing Banner Facing NE" => "Standing Banner (NorthEast)", "Standing Banner Facing ESE" => "Standing Banner (East-SouthEast)", "Standing Banner Facing East" => "Standing Banner (East)", "Standing Banner Facing SSE" => "Standing Banner (South-SouthEast)", "Standing Banner Facing SE" => "Standing Banner (SouthEast)", "Standing Banner Facing SSW" => "Standing Banner (South-SouthWest)", "Standing Banner Facing South" => "Standing Banner (South)", "Standing Banner Facing WSW" => "Standing Banner (West-SouthWest)", "Standing Banner Facing SW" => "Standing Banner (SouthWest)", "Standing Banner Facing WNW" => "Standing Banner (West-NorthWest)", "Standing Banner Facing West" => "Standing Banner (West)", "Standing Banner Facing NNW" => "Standing Banner (North-NorthWest)", "Standing Banner Facing NW" => "Standing Banner (NorthWest)", "Standing Banner Facing NNE" => "Standing Banner (North-NorthEast)", "Standing Banner Facing North" => "Standing Banner (North)",
	"Wall Banner Facing North" => "Wall Banner (North)", "Wall Banner Facing South" => "Wall Banner (South)", "Wall Banner Facing West" => "Wall Banner (West)", "Wall Banner Facing East" => "Wall Banner (East)",
	"Red Sandstone Double Slab" => "Red Sandstone Double Slab (Seamed)",
	"Large Fern (Upper Half)" => "Double Plant (Upper Half, East)", "Double Tallgrass (Upper Half)" => "Double Plant (Upper Half, North)", "Lilac (Upper Half)" => "Double Plant (Upper Half, West)", "Sunflower (Upper Half)" => "Double Plant (Upper Half, South)",
	"Cauldron (Level 0.5)" => "Cauldron (Level 0)", "Cauldron (Level 1.5)" => "Cauldron (Level 1)", "Cauldron (Level 2.5)" => "Cauldron (Level 2)",
];
$ignoreBedrock = "/tile\..*|Reserved Block|.*Prismarine.*Slab.*|Update Game Block.*|Nether Reactor Core|Glowing Obsidian|Stone Cutter|Invisible Bedrock|Item Frame.*|.*Quartz Block \(Smooth\)|Bone Block \(Smooth\)|Purpur Pillar \(Smooth\)/";
$ignoreJava = "/OLD\/Redstone Comparator.*/";
$javaIds = [];
$bedrockIds = [];
$javaNames = [
	"Purpur Slab (Top)" => [205, 8], //Purpur Slab is missing its data
	"White Shulker Box" => [219, 0], "Orange Shulker Box" => [220, 0], "Magenta Shulker Box" => [221, 0], "Light blue Shulker Box" => [222, 0], "Yellow Shulker Box" => [223, 0], "Lime Shulker Box" => [224, 0], "Pink Shulker Box" => [225, 0], "Gray Shulker Box" => [226, 0], "Silver Shulker Box" => [227, 0], "Cyan Shulker Box" => [228, 0], "Purple Shulker Box" => [229, 0], "Blue Shulker Box" => [230, 0], "Brown Shulker Box" => [231, 0], "Green Shulker Box" => [232, 0], "Red Shulker Box" => [233, 0], "Black Shulker Box" => [234, 0],
	"Rose Bush (Upper Half)" => [175, 12], "Peony (Upper Half)" => [175, 13],
	"Observer (Powered, Down)" => [218, 8], "Observer (Powered, Up)" => [218, 9], "Observer (Powered, South)" => [218, 10], "Observer (Powered, North)" => [218, 11], "Observer (Powered, East)" => [218, 12], "Observer (Powered, West)" => [218, 13],
	"OLD/Redstone Comparator (Off, Compare, East)" => [150, 1],
];
$bedrockNames = [
	"Barrier" => [416, 0], //These were a "little" late in bedrock
	"Red Sandstone Double Slab (Seamless)" => [181, 8],
	"Structure Block (Save)" => [252, 1], "Structure Block (Load)" => [252, 2], "Structure Block (Corner)" => [252, 3],
	"Piston Head (Sticky, Down)" => [472, 0], "Piston Head (Sticky, Up)" => [472, 1], "Piston Head (Sticky, South)" => [472, 2], "Piston Head (Sticky, North)" => [472, 3], "Piston Head (Sticky, East)" => [472, 4], "Piston Head (Sticky, West)" => [472, 5], //These are just missing...
	"Piston (Extended, Down)" => [33, 8], "Piston (Extended, Up)" => [33, 9], "Piston (Extended, South)" => [33, 10], "Piston (Extended, North)" => [33, 11], "Piston (Extended, East)" => [33, 12], "Piston (Extended, West)" => [33, 13],
	"Sticky Piston (Extended, Down)" => [29, 8], "Sticky Piston (Extended, Up)" => [29, 9], "Sticky Piston (Extended, South)" => [29, 10], "Sticky Piston (Extended, North)" => [29, 11], "Sticky Piston (Extended, East)" => [29, 12], "Sticky Piston (Extended, West)" => [29, 13],
	"Observer (Down)" => [251, 0], "Observer (Up)" => [251, 1], "Observer (South)" => [251, 2], "Observer (North)" => [251, 3], "Observer (East)" => [251, 4], "Observer (West)" => [251, 5], "Observer (Powered, Down)" => [251, 8], "Observer (Powered, Up)" => [251, 9], "Observer (Powered, South)" => [251, 10], "Observer (Powered, North)" => [251, 11], "Observer (Powered, East)" => [251, 12], "Observer (Powered, West)" => [251, 13],
	"Jukebox (Has Record)" => [84, 1],

];
//Beds/Tallgrass are completely wrong in both data sets, but match in vanilla
foreach ($dataSources["Java"] as $id => $javaSource) {
	parseSource("java-$id", [26, 31, 43], $javaSource[0], $javaNames, $javaIds);
}
foreach ($dataSources["PE"] as $id => $bedrockSource) {
	if ($id === "1.4") {
		continue; //Just a bunch of educational blocks & 1.13 block which aren't supported by java anyways
	}
	parseSource("bedrock-$id", [26, 31, 43, 251], $bedrockSource[0], $bedrockNames, $bedrockIds);
}

$toBedrock = matchNames("bedrock", $bedrockNameRemaps, $ignoreJava, $javaNames, $bedrockNames, $javaIds, $bedrockIds);
$toJava = matchNames("java", $javaNameRemaps, $ignoreBedrock, $bedrockNames, $javaNames, $bedrockIds, $javaIds);

$sort = static function ($a, $b) {
	$idA = explode(":", $a)[0];
	$idB = explode(":", $b)[0];
	if ($idA !== $idB) {
		return $idA - $idB;
	}
	return explode(":", $a)[1] - explode(":", $b)[1];
};
uksort($toBedrock, $sort);
uksort($toJava, $sort);
file_put_contents("bedrock-conversion-map.json", json_encode($toBedrock, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
file_put_contents("java-conversion-map.json", json_encode($toJava, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

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

function parseSource(string $id, array $ignore, string $url, array &$writeNames, array &$writeIds)
{
	$data = json_decode(getData($url), true);
	if (!isset($data["blocks"])) {
		return;
	}
	foreach ($data["blocks"] as $blockData) {
		if (in_array($blockData["id"], $ignore, true)) {
			continue;
		}
		if (isset($writeIds[$blockData["id"]])) {
			echo "Warning: Duplicate entry " . $blockData["id"] . " found in " . $id . PHP_EOL;
		}
		if (isset($blockData["data"])) {
			foreach ($blockData["data"] as $meta => $metaData) {
				if (!isset($metaData["invalid"])) {
					if (isset($writeNames[$metaData["name"]])) {
						echo "Warning: Duplicate name " . $metaData["name"] . " found in " . $id . PHP_EOL;
					}
					$writeNames[$metaData["name"]] = [$blockData["id"], $meta];
				}
			}
		} else {
			$writeNames[$blockData["name"]] = [$blockData["id"], 0];
		}
		$writeIds[$blockData["id"]] = $blockData["idStr"];
	}
}

function matchNames(string $type, array $remaps, string $ignore, array $names, array $names2, array $ids, array $ids2): array
{
	$saveTo = [];
	foreach ($names as $name => $data) {
		$name = $remaps[$name] ?? $name;
		if (isset($names2[$name])) {
			if ($data !== $names2[$name]) {
				$stringId = $data[0] . ":" . $data[1];
				if (isset($saveTo[$stringId])) {
					echo "Warning: Duplicate entry $stringId found in $type" . PHP_EOL;
				}
				$saveTo[$stringId] = $names2[$name][0] . ":" . $names2[$name][1];
			}
			continue;
		}

		if (preg_match($ignore, $name)) {
			$saveTo[$data[0] . ":" . $data[1]] = "0:0";
			continue;
		}
		echo "Warning: Could not find $type id for $name ($data[0]:$data[1])" . PHP_EOL;
	}
	return $saveTo;
}
