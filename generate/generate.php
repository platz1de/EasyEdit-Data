<?php

/**
 * Welcome to the properly worst script in history
 */

use pocketmine\math\Axis;
use pocketmine\math\Facing;
use Ramsey\Uuid\Uuid;

error_reporting(E_ALL);

const BEDROCK_VERSION = "1.19.1";

try {
	require_once("phar://PocketMine-MP.phar/vendor/autoload.php");
} catch (Throwable) {
	echo "Drop a valid PocketMine Phar into the generation folder";
	return;
}

$repo = json_decode(file_get_contents("../dataRepo.json"), true, 512, JSON_THROW_ON_ERROR);
$repo["version"] = BEDROCK_VERSION . "-" . Uuid::uuid4();
$repo["state-version"] =
	(1 << 24) | //major
	(18 << 16) | //minor
	(10 << 8) | //patch
	(1); //revision
file_put_contents("../dataRepo.json", json_encode($repo, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$suppress = json_decode(file_get_contents("suppress.json"), true, 512, JSON_THROW_ON_ERROR);

$javaToBedrock = [];
$geyserMapping = json_decode(getData("https://raw.githubusercontent.com/GeyserMC/mappings/master/blocks.json"), true, 512, JSON_THROW_ON_ERROR);

$bedrockSourceCount = 0;
foreach ($geyserMapping as $java => $bedrockData) {
	$bedrock = $bedrockData["bedrock_identifier"];
	$states = [];
	if (isset($bedrockData["bedrock_states"])) {
		foreach ($bedrockData["bedrock_states"] as $state => $value) {
			if (is_bool($value)) {
				$states[] = $state . "=" . ($value ? "true" : "false");
			} else {
				$states[] = $state . "=" . $value;
			}
		}
		$bedrock .= "[" . implode(",", $states) . "]";
	}
	$javaToBedrock[$java] = $bedrock;
}

file_put_contents("debug/all.json", json_encode($javaToBedrock, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

foreach ($javaToBedrock as $java => $bedrock) {
	foreach ($suppress["source"] as $suppressData) {
		if (preg_match($suppressData, $java)) {
			unset($javaToBedrock[$java]);
			continue 2;
		}
	}
}

foreach (scandir("patches") as $patch) {
	if ($patch === "." || $patch === "..") {
		continue;
	}
	$patchData = json_decode(file_get_contents("patches/$patch"), true, 512, JSON_THROW_ON_ERROR);
	foreach ($patchData as $java => $bedrock) {
		$pre = $javaToBedrock;
		$javaToBedrock[$java] = $bedrock;
		if ($pre === $javaToBedrock) {
			echo "\e[31mFailed to apply patch $patch ($java -> $bedrock)\e[39m" . PHP_EOL;
		}
	}
}

$bedrockToJava = [];
foreach ($javaToBedrock as $java => $bedrock) {
	if (isset($bedrockToJava[$bedrock])) {
		$ratingNew = 0;
		$ratingOld = 0;
		foreach (["powered=false", "waterlogged=false", "snowy=false"] as $pos) {
			if (str_contains($java, $pos)) {
				$ratingNew++;
			}
			if (str_contains($bedrockToJava[$bedrock], $pos)) {
				$ratingOld++;
			}
		}
		if ($ratingNew > $ratingOld) {
			$bedrockToJava[$bedrock] = $java;
		}
		if ($ratingNew !== 0) {
			continue;
		}
		foreach ($suppress["java_ignore"] as $suppressData => $_) {
			if (preg_match($suppressData, $java)) {
				continue 2;
			}
		}
		echo "Duplicate java block: $java ($bedrock, " . $bedrockToJava[$bedrock] . ")" . PHP_EOL;
	} else {
		$bedrockToJava[$bedrock] = $java;
	}
}
$bedrockToJava["minecraft:invisible_bedrock[]"] = "minecraft:barrier";

//Compatibility with old versions
foreach ($javaToBedrock as $java => $bedrock) {
	foreach ([
		         "/minecraft:dirt_path(.*)/" => "minecraft:grass_path$1",
		         "/minecraft:oak_sign(.*)/" => "minecraft:sign$1",
		         "/minecraft:oak_wall_sign(.*)/" => "minecraft:wall_sign$1",
		         "/minecraft:water_cauldron\\[level=(.)]/" => "minecraft:cauldron[level=$1]",
		         "/minecraft:cauldron/" => "minecraft:cauldron[level=0]",
	         ] as $search => $replace) {
		if (preg_match($search, $java)) {
			$javaToBedrock[preg_replace($search, $replace, $java)] = $bedrock;
		}
	}
}

//Alternative walls
foreach ($javaToBedrock as $java => $bedrock) {
	if (preg_match("/^minecraft:(.*)_wall\[east=(.*),north=(.*),south=(.*),up=(.*),west=(.*)]$/", $java, $matches)) {
		foreach ([2, 3, 4, 6] as $i) {
			$matches[$i] = $matches[$i] === "none" ? "false" : $matches[$i];
		}
		$new = "minecraft:" . $matches[1] . "_wall[east=" . $matches[2] . ",north=" . $matches[3] . ",south=" . $matches[4] . ",up=" . $matches[5] . ",west=" . $matches[6] . "]";
		if (!isset($javaToBedrock[$new])) {
			$javaToBedrock[$new] = $bedrock;
		}
	}
}

ksort($javaToBedrock);
ksort($bedrockToJava);

echo "Found " . count($javaToBedrock) . " translations to bedrock" . PHP_EOL;
echo "Found " . count($bedrockToJava) . " translations to java" . PHP_EOL;
file_put_contents("debug/java-to-bedrock-full.json", json_encode($javaToBedrock, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
file_put_contents("debug/bedrock-to-java-full.json", json_encode($bedrockToJava, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

$customData = json_decode(file_get_contents("data.json"), true, 512, JSON_THROW_ON_ERROR);
$jtb = [];
$failedJTB = [];

$groupsJtb = [];
foreach ($javaToBedrock as $java => $bedrock) {
	preg_match("/^([a-z\d:_]+)(?:\[(.*)])?$/", $java, $matches);
	if (count($matches) === 0) {
		continue;
	}
	if (!isset($groupsJtb[$matches[1]])) {
		$groupsJtb[$matches[1]] = ["states" => [], "name" => $matches[1]];
	}
	$groupsJtb[$matches[1]]["states"][$java] = $bedrock;
}

foreach ($groupsJtb as $group) {
	$hasChanges = false;
	foreach ($group["states"] as $java => $bedrock) {
		if ($java !== $bedrock) {
			$hasChanges = true;
			break;
		}
	}
	if (!$hasChanges) {
		$jtb[$group["name"]] = ["type" => "none"];
		continue;
	}

	$javaStates = [];
	$bedrockStates = [];
	$bedrockStatesFlattened = [];
	foreach ($group["states"] as $java => $bedrock) {
		preg_match("/^([a-z\d:_]+)(?:\[(.*)])?$/", $java, $matches);
		if (count($matches) === 0) {
			throw new RuntimeException("Invalid java block: $java");
		}
		$states = [];
		if (isset($matches[2])) {
			foreach (explode(",", $matches[2]) as $state) {
				$d = explode("=", $state);
				$states[$d[0]] = $d[1];
			}
		}
		$javaStates[] = $states;
		preg_match("/^([a-z\d:_]+)(?:\[(.*)])?$/", $bedrock, $matches);
		if (count($matches) === 0) {
			throw new RuntimeException("Invalid bedrock block: $bedrock");
		}
		$states = [];
		if (isset($matches[2])) {
			foreach (explode(",", $matches[2]) as $state) {
				$d = explode("=", $state);
				$states[$d[0]] = $d[1];
			}
		}
		$bedrockStates[$matches[1]][] = $states;
		$bedrockStatesFlattened[] = $states;
	}

	$obj = ["type" => "unknown"];

	//apply state renames and search for value changes
	if (count($bedrockStates) === 1) {
		$obj["type"] = "singular";
		$obj["name"] = array_key_first($bedrockStates);
	}
	$values = [];
	foreach ($javaStates as $states) {
		foreach ($states as $key => $value) {
			if (!isset($values[$key])) {
				$values[$key] = [];
			}
			$values[$key][] = $value;
		}
	}
	$bedrockValues = [];
	foreach ($bedrockStatesFlattened as $states) {
		foreach ($states as $key => $value) {
			if (!isset($bedrockValues[$key])) {
				$bedrockValues[$key] = [];
			}
			$bedrockValues[$key][] = $value;
		}
	}
	foreach ($values as $key => $value) {
		$checkValues = function ($prev, $past) use (&$values, &$bedrockValues, &$obj) {
			if (!isset($values[$prev], $bedrockValues[$past])) return false;
			$hasChanges = false;
			foreach ($values[$prev] as $i => $v) {
				if ($v !== $bedrockValues[$past][$i]) {
					$hasChanges = true;
					break;
				}
			}
			if (!$hasChanges) {
				unset($values[$prev], $bedrockValues[$past]);
				$obj["state_renames"][$prev] = $past;
				return true;
			}
			$map = [];
			foreach ($values[$prev] as $i => $v) {
				if (isset($map[$v]) && $map[$v] !== $bedrockValues[$past][$i]) {
					return false;
				}
				$map[$v] = $bedrockValues[$past][$i];
			}
			$obj["state_renames"][$prev] = $past;
			$obj["state_values"][$past] = $map;
			unset($values[$prev], $bedrockValues[$past]);
			return true;
		};
		if (!($checkValues($key, $key) || $checkValues($key, $key . "_bit") || (isset($customData["jtb_states"]["global"][$key]) && $checkValues($key, $customData["jtb_states"]["global"][$key])) || (isset($customData["jtb_states"]["global"][$key . "_"]) && $checkValues($key, $customData["jtb_states"]["global"][$key . "_"])) || (isset($customData["jtb_states"][$group["name"]][$key]) && $checkValues($key, $customData["jtb_states"][$group["name"]][$key])))) {
			foreach ($customData["jtb_states"]["regex"] as $regex => $replacements) {
				if (isset($replacements[$key]) && preg_match($regex, $group["name"]) && $checkValues($key, $replacements[$key])) {
					break;
				}
			}
		}
	}
	foreach ($bedrockValues as $key => $value) {
		if (in_array($key, $customData["jtb_additions"]["global"], true)) {
			$value = array_unique($value);
			if (count($value) !== 1) {
				throw new RuntimeException("Added state $key with multiple values");
			}
			$obj["state_addition"][$key] = $value[0];
			unset($bedrockValues[$key]);
		}
		if (in_array($key, $customData["jtb_additions"][$group["name"]] ?? [], true)) {
			$value = array_unique($value);
			if (count($value) !== 1) {
				throw new RuntimeException("Added state $key with multiple values");
			}
			$obj["state_addition"][$key] = $value[0];
			unset($bedrockValues[$key]);
		}
	}
	foreach ($values as $key => $value) {
		if (in_array($key, $customData["jtb_removals"]["global"], true)) {
			$obj["state_removals"][] = $key;
			unset($values[$key]);
		}
		if (in_array($key, $customData["jtb_removals"][$group["name"]] ?? [], true)) {
			$obj["state_removals"][] = $key;
			unset($values[$key]);
		}
	}
	if ($obj["type"] === "singular" && ($values !== [] || $bedrockValues !== [])) {
		if ($values === []) {
			foreach ($bedrockValues as $key => $value) {
				$value = array_unique($value);
				if (count($value) !== 1) {
					throw new RuntimeException("Added state $key with multiple values");
				}
				$obj["state_addition"][$key] = $value[0];
				unset($bedrockValues[$key]);
			}
		} elseif ($bedrockValues === []) {
			$obj["state_removals"] = array_keys($values);
			$values = [];
		}
	}

	if ($obj["type"] === "unknown" && str_ends_with($group["name"], "_slab")) {
		$fail = false;
		$types = ["top" => [], "bottom" => [], "double" => []];
		foreach ($group["states"] as $state => $bedrock) {
			preg_match('/type=(top|bottom|double)/', $state, $matches);
			if (!isset($matches[1])) {
				echo "Unknown type $state" . PHP_EOL;
				$fail = true;
				break;
			}
			$types[$matches[1]][$state] = $bedrock;
		}
		if (!$fail) {
			$normalName = null;
			$doubleState = null;
			foreach ($types as $type => $states) {
				foreach ($states as $state) {
					preg_match("/^([a-z\d:_]+)\[(.*)]$/", $state, $matches);
					if ($type === "double") {
						if ($doubleState !== null && $doubleState !== $matches[1]) {
							echo "Double state mismatch" . PHP_EOL;
							$fail = true;
							break 2;
						}
						$doubleState = $matches[1];
					} else {
						if ($normalName !== null && $normalName !== $matches[1]) {
							echo "Normal name mismatch" . PHP_EOL;
							$fail = true;
							break 2;
						}
						$normalName = $matches[1];
					}
				}
			}
		}
		if (!$fail) {
			$obj["type"] = "multi";
			$obj["multi_name"] = "type";
			$obj["multi_states"] = [
				"top" => [
					"name" => $normalName,
					"state_addition" => [
						"top_slot_bit" => "true"
					]
				],
				"bottom" => [
					"name" => $normalName,
					"state_addition" => [
						"top_slot_bit" => "false"
					]
				],
				"double" => [
					"name" => $doubleState,
					"state_addition" => [
						"top_slot_bit" => "false"
					]
				]
			];
			unset($values["type"], $bedrockValues["top_slot_bit"]);
		}
	}

	if ($obj["type"] === "singular" && str_ends_with($group["name"], "_button")) {
		$obj["type"] = "combined";
		$obj["combined_names"] = [
			"face",
			"facing"
		];
		$obj["target_name"] = "facing_direction";
		$obj["combined_states"] = [
			"ceiling" => [
				"east" => "0",
				"north" => "0",
				"south" => "0",
				"west" => "0"
			],
			"floor" => [
				"east" => "1",
				"north" => "1",
				"south" => "1",
				"west" => "1"
			],
			"wall" => [
				"east" => "5",
				"north" => "2",
				"south" => "3",
				"west" => "4"
			]
		];
		unset($values["face"], $values["facing"], $bedrockValues["facing_direction"]);
	}

	if ($group["name"] === "minecraft:water" || $group["name"] === "minecraft:lava") {
		$obj["type"] = "multi";
		$obj["multi_name"] = "level";
		$flowingName = "minecraft:flowing_" . ($group["name"] === "minecraft:water" ? "water" : "lava");
		$obj["multi_states"] = [
			"0" => ["name" => $group["name"], "state_addition" => ["liquid_depth" => "0"]],
			"1" => ["name" => $flowingName, "state_addition" => ["liquid_depth" => "1"]],
			"2" => ["name" => $flowingName, "state_addition" => ["liquid_depth" => "2"]],
			"3" => ["name" => $flowingName, "state_addition" => ["liquid_depth" => "3"]],
			"4" => ["name" => $flowingName, "state_addition" => ["liquid_depth" => "4"]],
			"5" => ["name" => $flowingName, "state_addition" => ["liquid_depth" => "5"]],
			"6" => ["name" => $flowingName, "state_addition" => ["liquid_depth" => "6"]],
			"7" => ["name" => $flowingName, "state_addition" => ["liquid_depth" => "7"]],
			"8" => ["name" => $flowingName, "state_addition" => ["liquid_depth" => "8"]],
			"9" => ["name" => $flowingName, "state_addition" => ["liquid_depth" => "9"]],
			"10" => ["name" => $flowingName, "state_addition" => ["liquid_depth" => "10"]],
			"11" => ["name" => $flowingName, "state_addition" => ["liquid_depth" => "11"]],
			"12" => ["name" => $flowingName, "state_addition" => ["liquid_depth" => "12"]],
			"13" => ["name" => $flowingName, "state_addition" => ["liquid_depth" => "13"]],
			"14" => ["name" => $flowingName, "state_addition" => ["liquid_depth" => "14"]],
			"15" => ["name" => $flowingName, "state_addition" => ["liquid_depth" => "15"]]
		];
		unset($values["level"], $bedrockValues["liquid_depth"]);
	}

	if (in_array($group["name"], ["minecraft:furnace", "minecraft:blast_furnace", "minecraft:smoker", "minecraft:redstone_ore", "minecraft:deepslate_redstone_ore", "minecraft:redstone_lamp"], true)) {
		$obj["type"] = "multi";
		$obj["multi_name"] = "lit";
		$obj["multi_states"] = [
			"true" => ["name" => "minecraft:lit_" . substr($group["name"], 10)],
			"false" => ["name" => $group["name"]]
		];
		unset($values["lit"]);
	}

	if ($group["name"] === "minecraft:cave_vines" || $group["name"] === "minecraft:cave_vines_plant") {
		$obj["type"] = "multi";
		$obj["multi_name"] = "berries";
		$obj["multi_states"] = [
			"true" => ["name" => $group["name"] === "minecraft:cave_vines" ? "minecraft:cave_vines_head_with_berries" : "minecraft:cave_vines_body_with_berries"],
			"false" => ["name" => $group["name"]]
		];
		unset($values["berries"]);
	}

	if ($group["name"] === "minecraft:comparator") {
		$obj["type"] = "multi";
		$obj["multi_name"] = "powered";
		$obj["multi_states"] = [
			"true" => ["name" => "minecraft:powered_comparator", "state_addition" => ["output_lit_bit" => "true"]],
			"false" => ["name" => "minecraft:unpowered_comparator", "state_addition" => ["output_lit_bit" => "false"]]
		];
		unset($values["powered"], $bedrockValues["output_lit_bit"]);
	}

	if ($group["name"] === "minecraft:daylight_detector") {
		$obj["type"] = "multi";
		$obj["multi_name"] = "inverted";
		$obj["multi_states"] = [
			"true" => ["name" => "minecraft:daylight_detector_inverted"],
			"false" => ["name" => "minecraft:daylight_detector"]
		];
		unset($values["inverted"]);
	}

	if ($group["name"] === "minecraft:lever") {
		$obj["type"] = "combined_multi";
		$obj["combined_names"] = [
			"face",
			"facing"
		];
		$obj["combined_states"] = [
			"ceiling" => [
				"east" => [
					"false" => ["lever_direction" => "down_east_west", "open_bit" => "true"],
					"true" => ["lever_direction" => "down_east_west", "open_bit" => "false"]
				],
				"north" => [
					"false" => ["lever_direction" => "down_north_south", "open_bit" => "false"],
					"true" => ["lever_direction" => "down_north_south", "open_bit" => "true"]
				],
				"south" => [
					"false" => ["lever_direction" => "down_north_south", "open_bit" => "true"],
					"true" => ["lever_direction" => "down_north_south", "open_bit" => "false"]
				],
				"west" => [
					"false" => ["lever_direction" => "down_east_west", "open_bit" => "false"],
					"true" => ["lever_direction" => "down_east_west", "open_bit" => "true"]
				]
			],
			"floor" => [
				"east" => [
					"false" => ["lever_direction" => "up_east_west", "open_bit" => "true"],
					"true" => ["lever_direction" => "up_east_west", "open_bit" => "false"]
				],
				"north" => [
					"false" => ["lever_direction" => "up_north_south", "open_bit" => "false"],
					"true" => ["lever_direction" => "up_north_south", "open_bit" => "true"]
				],
				"south" => [
					"false" => ["lever_direction" => "up_north_south", "open_bit" => "true"],
					"true" => ["lever_direction" => "up_north_south", "open_bit" => "false"]
				],
				"west" => [
					"false" => ["lever_direction" => "up_east_west", "open_bit" => "false"],
					"true" => ["lever_direction" => "up_east_west", "open_bit" => "true"]
				]
			],
			"wall" => [
				"east" => [
					"false" => ["lever_direction" => "east", "open_bit" => "false"],
					"true" => ["lever_direction" => "east", "open_bit" => "true"]
				],
				"north" => [
					"false" => ["lever_direction" => "north", "open_bit" => "false"],
					"true" => ["lever_direction" => "north", "open_bit" => "true"]
				],
				"south" => [
					"false" => ["lever_direction" => "south", "open_bit" => "false"],
					"true" => ["lever_direction" => "south", "open_bit" => "true"]
				],
				"west" => [
					"false" => ["lever_direction" => "west", "open_bit" => "false"],
					"true" => ["lever_direction" => "west", "open_bit" => "true"]
				]
			]
		];
		unset($values["face"], $values["facing"], $values["powered"], $bedrockValues["lever_direction"], $bedrockValues["open_bit"]);
	}

	if ($group["name"] === "minecraft:piston_head") {
		$obj["type"] = "multi";
		$obj["multi_name"] = "type";
		$obj["multi_states"] = [
			"normal" => ["name" => "minecraft:piston_arm_collision"],
			"sticky" => ["name" => "minecraft:sticky_piston_arm_collision"]
		];
		unset($values["type"]);
	}

	if($group["name"] === "minecraft:redstone_torch" || $group["name"] === "minecraft:redstone_wall_torch") {
		$obj["type"] = "multi";
		$obj["multi_name"] = "lit";
		$obj["multi_states"] = [
			"true" => ["name" => "minecraft:redstone_torch"],
			"false" => ["name" => "minecraft:unlit_redstone_torch"]
		];
		unset($values["lit"]);
	}

	if ($group["name"] === "minecraft:repeater") {
		$obj["type"] = "multi";
		$obj["multi_name"] = "powered";
		$obj["multi_states"] = [
			"true" => ["name" => "minecraft:powered_repeater"],
			"false" => ["name" => "minecraft:unpowered_repeater"]
		];
		unset($values["powered"]);
	}

	$failed = false;
	if ($values !== []) {
		$failed = true;
	}
	if ($bedrockValues !== []) {
		$failed = true;
	}
	if ($obj["type"] === "unknown") {
		$failed = true;
	}
	if ($failed) {
		$obj["all"] = $group["states"];
		$obj["missing_java"] = $values;
		$obj["missing_bedrock"] = $bedrockValues;
	}
	$failed ? $failedJTB[$group["name"]] = $obj : $jtb[$group["name"]] = $obj;
}

if ($failedJTB !== []) echo "\e[31mFailed to convert " . count($failedJTB) . " blocks\e[39m" . PHP_EOL;
echo "Converted " . count($jtb) . " blocks" . PHP_EOL;
file_put_contents("debug/java-to-bedrock-fail.json", json_encode($failedJTB, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
file_put_contents("../java-to-bedrock.json", json_encode($jtb, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

foreach ($javaToBedrock as $java => $bedrock) {
	if (str_contains($java, "waterlogged=false")) {
		preg_match("/^minecraft:(.+)\[(.+)\]$/", $java, $matches);
		if (count($matches) === 0) {
			continue;
		}
		$states = explode(",", $matches[2]);
		$states = array_filter($states, static function (string $state) {
			return !str_contains($state, "waterlogged");
		});
		if (count($states) === 0) {
			$javaToBedrock["minecraft:" . $matches[1]] = $bedrock;
			continue;
		}
		$javaToBedrock["minecraft:" . $matches[1] . "[" . implode(",", $states) . "]"] = $bedrock;
	}
}

foreach ($javaToBedrock as $java => $bedrock) {
	if (str_contains($java, "powered=false")) {
		preg_match("/^minecraft:(.+)\[(.+)\]$/", $java, $matches);
		if (count($matches) === 0) {
			continue;
		}
		$states = explode(",", $matches[2]);
		$states = array_filter($states, static function (string $state) {
			return !str_contains($state, "powered");
		});
		if (count($states) === 0) {
			$javaToBedrock["minecraft:" . $matches[1]] = $bedrock;
			continue;
		}
		$javaToBedrock["minecraft:" . $matches[1] . "[" . implode(",", $states) . "]"] = $bedrock;
	}
}

$current = "";
foreach ($javaToBedrock as $java => $bedrock) {
	preg_match("/^minecraft:(.+)\[.+\]$/", $java, $matches);
	if (count($matches) === 0) {
		$current = $java;
		continue;
	}
	if ($current !== "minecraft:" . $matches[1]) {
		$current = "minecraft:" . $matches[1];
		if (!isset($javaToBedrock[$current])) {
			$javaToBedrock[$current] = $bedrock;
		}
	}
}

foreach ($javaToBedrock as $java => $bedrock) {
	if (!isset($javaToBedrock[$java . "[]"]) && preg_match("/^minecraft:([a-z_]+)$/", $java, $matches)) {
		$javaToBedrock[$java . "[]"] = $bedrock;
	}
}

ksort($javaToBedrock);
ksort($bedrockToJava);

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
foreach ($javaToBedrock as $state => $id) {
	if (!str_ends_with($state, "]")) {
		continue; //no properties
	}
	remapProperties($state, $id, $rotationData, $javaToBedrock, $rotations, $missingRotations);
	remapProperties($state, $id, $flipData["x"], $javaToBedrock, $flipX, $missingRotations);
	remapProperties($state, $id, $flipData["z"], $javaToBedrock, $flipZ, $missingRotations);
	remapProperties($state, $id, $flipData["y"], $javaToBedrock, $flipY, $missingRotations);
}
file_put_contents("debug/missing-rotations.json", json_encode($missingRotations, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
file_put_contents("../rotation-data.json", json_encode($rotations, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
file_put_contents("../flip-data.json", json_encode(["xAxis" => $flipX, "zAxis" => $flipZ, "yAxis" => $flipY], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
echo "Rotated " . count($rotations) . " blocks" . PHP_EOL;
echo "Flipped " . count($flipX) + count($flipZ) + count($flipY) . " blocks" . PHP_EOL;

/**
 * Some properties of blocks are defined with tiles in bedrock while the blockstate is used in java,
 * they need to be remapped in a special way, allowing full conversion
 */
$jtbTileStates = [];
$btjTileStates = [];
foreach ($javaToBedrock as $state => $id) {
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
		$jtbTileStates["chest_relation"][$state] = $identifier;
		$btjTileStates["chest_relation"][$bedrockToJava[$id]][$identifier] = $state;
	} elseif (str_ends_with($matches[1], "shulker_box")) {
		foreach ($properties as $property) {
			if (str_starts_with($property, "facing=")) {
				$jtbTileStates["shulker_box_facing"][$state] = str_replace("facing=", "", $property);
				$btjTileStates["shulker_box_facing"][$bedrockToJava[$id]][str_replace("facing=", "", $property)] = $state;
			}
		}
	} elseif (!str_ends_with($matches[1], "piston_head") && (str_ends_with($matches[1], "head") || str_ends_with($matches[1], "skull"))) {
		$jtbTileStates["skull_type"][$state] = preg_replace("/(_wall)?(_head|_skull|minecraft:)/", "", $matches[1]);
		$btjTileStates["skull_type"][$bedrockToJava[$id]][preg_replace("/(_wall)?(_head|_skull|minecraft:)/", "", $matches[1])] = $state;
		foreach ($properties as $property) {
			if (str_starts_with($property, "rotation=")) {
				$jtbTileStates["skull_rotation"][$state] = str_replace("rotation=", "", $property);
				$btjTileStates["skull_rotation"][$bedrockToJava[$id]][preg_replace("/(_wall)?(_head|_skull|minecraft:)/", "", $matches[1])][str_replace("rotation=", "", $property)] = $state;
			}
		}
	}
}

$tmp = $btjTileStates["skull_rotation"];
$btjTileStates["skull_rotation"] = [];
foreach ($tmp as $placeholder1 => $data) {
	foreach ($data as $placeholder2 => $value) {
		$btjTileStates["skull_rotation"][$btjTileStates["skull_type"][$placeholder1][$placeholder2]] = $value;
	}
}

file_put_contents("../tile-states-btj.json", json_encode($btjTileStates, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
file_put_contents("../tile-states-jtb.json", json_encode($jtbTileStates, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
echo "Converted " . count($btjTileStates) . " tile states" . PHP_EOL;

$blockData = json_decode(getData("https://raw.githubusercontent.com/PrismarineJS/minecraft-data/master/data/pc/common/legacy.json"), true)["blocks"];

$toBedrock = [];
$missingData = [];
foreach ($blockData as $legacyId => $state) {
	$state = str_replace("persistent=false", "persistent=true", $state);

	//sort
	preg_match("/(.*)\[(.*?)]/", $state, $matches);
	if (isset($matches[2])) {
		$properties = explode(",", $matches[2]);
		sort($properties);
		$state = $matches[1] . "[" . implode(",", $properties) . "]";
	}

	if (isset($javaToBedrock[$state])) {
		if ($javaToBedrock[$state] !== $legacyId) {
			$toBedrock[$legacyId] = $javaToBedrock[$state];
		}
	} else {
		$missingData[$legacyId] = $state;
	}
}

array_multisort(array_keys($toBedrock), SORT_NATURAL, array_values($toBedrock), SORT_NATURAL, $toBedrock);
file_put_contents("../legacy-conversion-map.json", json_encode($toBedrock, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
file_put_contents("debug/missing-legacy.json", json_encode($missingData, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
echo "Converted " . count($toBedrock) . " legacy blocks" . PHP_EOL;

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