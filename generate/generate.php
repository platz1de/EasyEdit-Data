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

echo "Found " . count($javaToBedrock) . " translations to bedrock" . PHP_EOL;
file_put_contents("debug/java-to-bedrock-full.json", json_encode($javaToBedrock, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

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

	$pre = $group["states"];
	$defaults = [];
	$possible = [];
	foreach ($group["states"] as $java => $bedrock) {
		preg_match("/^([a-z\d:_]+)\[(.*)]$/", $java, $matches);
		if (count($matches) === 0) {
			continue;
		}
		foreach (explode(",", $matches[2]) as $state) {
			$d = explode("=", $state);
			$defaults[$d[0]][] = $d[1];
			$possible[$d[0]][] = $d[1];
		}
	}
	foreach ($possible as $key => $value) {
		$possible[$key] = array_values(array_unique($value));
	}

	foreach ($defaults as $key => $value) {
		if (isset($customData["defaults"][$group["name"]][$key])) {
			$defaults[$key] = $customData["defaults"][$group["name"]][$key];
		} elseif (isset($customData["defaults"]["global"][$key])) {
			$defaults[$key] = $customData["defaults"]["global"][$key];
		} else {
			$found = false;
			foreach ($customData["defaults"]["regex"] as $regex => $default) {
				if (isset($default[$key]) && preg_match($regex, $group["name"])) {
					$defaults[$key] = $default[$key];
					$found = true;
					break;
				}
			}
			if (!$found) {
				echo "No default for " . $group["name"] . " " . $key . "(" . implode(", ", $value) . ")" . PHP_EOL;
				$defaults[$key] = $value[0];
			}
		}
		if (!in_array($defaults[$key], $value)) {
			echo "Default for " . $group["name"] . " " . $key . " is not valid (" . $defaults[$key] . ") - " . implode(", ", $value) . PHP_EOL;
		}
	}

	if (!$hasChanges) {
		$obj = ["type" => "none"];
		if (count($possible) > 0) {
			$obj["values"] = $possible;
		}
		if (count($defaults) > 0) {
			$obj["defaults"] = $defaults;
		}
		$jtb[$group["name"]] = $obj;
		continue;
	}

	foreach ($group["states"] as $java => $bedrock) {
		preg_match("/^([a-z\d:_]+)(?:\[(.*)])?$/", $java, $matches);
		$states = [];
		if (isset($matches[2])) {
			foreach (explode(",", $matches[2]) as $state) {
				$d = explode("=", $state);
				$states[$d[0]] = $d[1];
			}
		}
		foreach ($defaults as $key => $value) {
			if (!isset($states[$key])) {
				$states[$key] = $value;
			}
		}
		unset($group["states"][$java]);
		$java = $matches[1] . "[" . implode(",", array_map(function ($key, $value) {
				return "$key=$value";
			}, array_keys($states), $states)) . "]";
		$group["states"][$java] = $bedrock;
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
		if ($matches[2] !== "") {
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
		$checkValues = function ($prev, $past) use (&$values, &$bedrockValues, &$obj, $group) {
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
			$obj["state_additions"][$key] = $value[0];
			unset($bedrockValues[$key]);
		}
		if (in_array($key, $customData["jtb_additions"][$group["name"]] ?? [], true)) {
			$value = array_unique($value);
			if (count($value) !== 1) {
				throw new RuntimeException("Added state $key with multiple values");
			}
			$obj["state_additions"][$key] = $value[0];
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
				$obj["state_additions"][$key] = $value[0];
				unset($bedrockValues[$key]);
			}
		} elseif ($bedrockValues === []) {
			foreach ($values as $key => $value) {
				$obj["state_removals"][] = $key;
				unset($values[$key]);
			}
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
					"state_additions" => [
						"top_slot_bit" => "true"
					]
				],
				"bottom" => [
					"name" => $normalName,
					"state_additions" => [
						"top_slot_bit" => "false"
					]
				],
				"double" => [
					"name" => $doubleState,
					"state_additions" => [
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
			"0" => ["name" => $group["name"], "state_additions" => ["liquid_depth" => "0"]],
			"1" => ["name" => $flowingName, "state_additions" => ["liquid_depth" => "1"]],
			"2" => ["name" => $flowingName, "state_additions" => ["liquid_depth" => "2"]],
			"3" => ["name" => $flowingName, "state_additions" => ["liquid_depth" => "3"]],
			"4" => ["name" => $flowingName, "state_additions" => ["liquid_depth" => "4"]],
			"5" => ["name" => $flowingName, "state_additions" => ["liquid_depth" => "5"]],
			"6" => ["name" => $flowingName, "state_additions" => ["liquid_depth" => "6"]],
			"7" => ["name" => $flowingName, "state_additions" => ["liquid_depth" => "7"]],
			"8" => ["name" => $flowingName, "state_additions" => ["liquid_depth" => "8"]],
			"9" => ["name" => $flowingName, "state_additions" => ["liquid_depth" => "9"]],
			"10" => ["name" => $flowingName, "state_additions" => ["liquid_depth" => "10"]],
			"11" => ["name" => $flowingName, "state_additions" => ["liquid_depth" => "11"]],
			"12" => ["name" => $flowingName, "state_additions" => ["liquid_depth" => "12"]],
			"13" => ["name" => $flowingName, "state_additions" => ["liquid_depth" => "13"]],
			"14" => ["name" => $flowingName, "state_additions" => ["liquid_depth" => "14"]],
			"15" => ["name" => $flowingName, "state_additions" => ["liquid_depth" => "15"]]
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
			"false" => ["name" => "minecraft:cave_vines"]
		];
		unset($values["berries"]);
	}

	if ($group["name"] === "minecraft:comparator") {
		$obj["type"] = "multi";
		$obj["multi_name"] = "powered";
		$obj["multi_states"] = [
			"true" => ["name" => "minecraft:powered_comparator", "state_additions" => ["output_lit_bit" => "true"]],
			"false" => ["name" => "minecraft:unpowered_comparator", "state_additions" => ["output_lit_bit" => "false"]]
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
			"facing",
			"powered"
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

	if ($group["name"] === "minecraft:redstone_torch" || $group["name"] === "minecraft:redstone_wall_torch") {
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

	if ($group["name"] === "minecraft:brown_mushroom_block" || $group["name"] === "minecraft:red_mushroom_block") {
		$obj["type"] = "combined";
		$obj["combined_names"] = [
			"down",
			"up",
			"north",
			"south",
			"west",
			"east"
		];
		$obj["target_name"] = "huge_mushroom_bits";
		$obj["combined_states"] = [
			"false" => [
				"false" => [
					"false" => [
						"false" => [
							"false" => [
								"false" => "0" //none
							]
						]
					]
				],
				"true" => [
					"true" => [
						"false" => [
							"true" => [
								"false" => "1" //up, north, west
							],
							"false" => [
								"false" => "2", //up, north
								"true" => "3" //up, north, east
							]
						]
					],
					"false" => [
						"false" => [
							"true" => [
								"false" => "4" //up, west
							],
							"false" => [
								"false" => "5", //up
								"true" => "6" //up, east
							]
						],
						"true" => [
							"true" => [
								"false" => "7" //up, south, west
							],
							"false" => [
								"false" => "8", //up, south
								"true" => "9" //up, south, east
							]
						]
					]
				]
			],
			"default" => "14" //all sides
		];
		unset($values["west"], $values["east"], $values["north"], $values["south"], $values["up"], $values["down"], $bedrockValues["huge_mushroom_bits"]);
	}

	if ($group["name"] === "minecraft:mushroom_stem") {
		$obj["type"] = "combined";
		$obj["name"] = "minecraft:brown_mushroom_block";
		$obj["combined_names"] = [
			"down",
			"up",
			"north",
			"south",
			"west",
			"east"
		];
		$obj["target_name"] = "huge_mushroom_bits";
		$obj["combined_states"] = [
			"false" => [
				"false" => [
					"false" => [
						"false" => [
							"false" => [
								"false" => "0" //none
							]
						]
					],
					"true" => [
						"true" => [
							"true" => [
								"true" => "10" //default stem (all horizontal sides)
							]
						]
					]
				],
			],
			"default" => "15" //all stem sides
		];
		unset($values["west"], $values["east"], $values["north"], $values["south"], $values["up"], $values["down"], $bedrockValues["huge_mushroom_bits"]);
	}

	if ($group["name"] === "minecraft:vine") {
		$obj["type"] = "combined";
		$obj["state_removals"] = ["up"]; //up is automatically added in bedrock
		$obj["combined_names"] = [
			"east",
			"north",
			"west",
			"south",
		];
		$obj["target_name"] = "vine_direction_bits";
		$obj["combined_states"] = [
			"false" => [
				"false" => [
					"false" => [
						"false" => "0", //none
						"true" => "1", //south
					],
					"true" => [
						"false" => "2", //west
						"true" => "3", //south, west
					]
				],
				"true" => [
					"false" => [
						"false" => "4", //north
						"true" => "5", //south, north
					],
					"true" => [
						"false" => "6", //north, west
						"true" => "7", //south, north, west
					]
				]
			],
			"true" => [
				"false" => [
					"false" => [
						"false" => "8", //east
						"true" => "9", //south, east
					],
					"true" => [
						"false" => "10", //east, west
						"true" => "11", //south, east, west
					]
				],
				"true" => [
					"false" => [
						"false" => "12", //north, east
						"true" => "13", //south, north, east
					],
					"true" => [
						"false" => "14", //north, east, west
						"true" => "15", //south, north, east, west
					]
				]
			]
		];
		unset($values["west"], $values["east"], $values["north"], $values["south"], $values["up"], $bedrockValues["vine_direction_bits"]);
	}

	if ($group["name"] === "minecraft:sculk_vein" || $group["name"] === "minecraft:glow_lichen") {
		$obj["type"] = "combined";
		$obj["combined_names"] = [
			"east",
			"north",
			"west",
			"south",
			"up",
			"down"
		];
		$obj["target_name"] = "multi_face_direction_bits";
		$obj["combined_states"] = [ //copilot go brrrrrrrrrrrrrrrr
			"false" => [
				"false" => [
					"false" => [
						"false" => [
							"false" => [
								"false" => "0", //none
								"true" => "1" //down
							],
							"true" => [
								"false" => "2", //up
								"true" => "3" //up, down
							]
						],
						"true" => [
							"false" => [
								"false" => "4", //north
								"true" => "5" //north, down
							],
							"true" => [
								"false" => "6", //north, up
								"true" => "7" //north, up, down
							]
						]
					],
					"true" => [
						"false" => [
							"false" => [
								"false" => "8", //south
								"true" => "9" //south, down
							],
							"true" => [
								"false" => "10", //south, up
								"true" => "11" //south, up, down
							]
						],
						"true" => [
							"false" => [
								"false" => "12", //north, south
								"true" => "13" //north, south, down
							],
							"true" => [
								"false" => "14", //north, south, up
								"true" => "15" //north, south, up, down
							]
						]
					]
				],
				"true" => [
					"false" => [
						"false" => [
							"false" => [
								"false" => "16", //west
								"true" => "17" //west, down
							],
							"true" => [
								"false" => "18", //west, up
								"true" => "19" //west, up, down
							]
						],
						"true" => [
							"false" => [
								"false" => "20", //north, west
								"true" => "21" //north, west, down
							],
							"true" => [
								"false" => "22", //north, west, up
								"true" => "23" //north, west, up, down
							]
						]
					],
					"true" => [
						"false" => [
							"false" => [
								"false" => "24", //south, west
								"true" => "25" //south, west, down
							],
							"true" => [
								"false" => "26", //south, west, up
								"true" => "27" //south, west, up, down
							]
						],
						"true" => [
							"false" => [
								"false" => "28", //north, south, west
								"true" => "29" //north, south, west, down
							],
							"true" => [
								"false" => "30", //north, south, west, up
								"true" => "31" //north, south, west, up, down
							]
						]
					]
				]
			],
			"true" => [
				"false" => [
					"false" => [
						"false" => [
							"false" => [
								"false" => "32", //east
								"true" => "33" //east, down
							],
							"true" => [
								"false" => "34", //east, up
								"true" => "35" //east, up, down
							]
						],
						"true" => [
							"false" => [
								"false" => "36", //north, east
								"true" => "37" //north, east, down
							],
							"true" => [
								"false" => "38", //north, east, up
								"true" => "39" //north, east, up, down
							]
						]
					],
					"true" => [
						"false" => [
							"false" => [
								"false" => "40", //south, east
								"true" => "41" //south, east, down
							],
							"true" => [
								"false" => "42", //south, east, up
								"true" => "43" //south, east, up, down
							]
						],
						"true" => [
							"false" => [
								"false" => "44", //north, south, east
								"true" => "45" //north, south, east, down
							],
							"true" => [
								"false" => "46", //north, south, east, up
								"true" => "47" //north, south, east, up, down
							]
						]
					]
				],
				"true" => [
					"false" => [
						"false" => [
							"false" => [
								"false" => "48", //west, east
								"true" => "49" //west, east, down
							],
							"true" => [
								"false" => "50", //west, east, up
								"true" => "51" //west, east, up, down
							]
						],
						"true" => [
							"false" => [
								"false" => "52", //north, west, east
								"true" => "53" //north, west, east, down
							],
							"true" => [
								"false" => "54", //north, west, east, up
								"true" => "55" //north, west, east, up, down
							]
						]
					],
					"true" => [
						"false" => [
							"false" => [
								"false" => "56", //south, west, east
								"true" => "57" //south, west, east, down
							],
							"true" => [
								"false" => "58", //south, west, east, up
								"true" => "59" //south, west, east, up, down
							]
						],
						"true" => [
							"false" => [
								"false" => "60", //north, south, west, east
								"true" => "61" //north, south, west, east, down
							],
							"true" => [
								"false" => "62", //north, south, west, east, up
								"true" => "63" //north, south, west, east, up, down
							]
						]
					]
				]
			]
		];
		unset($values["west"], $values["east"], $values["north"], $values["south"], $values["up"], $values["down"], $bedrockValues["multi_face_direction_bits"]);
	}

	//internal tile states
	if (str_ends_with($group["name"], "banner")) {
		preg_match("/minecraft:([a-z_]*)_banner/", $group["name"], $matches);
		$obj["internal_tile"] = ["color" => $matches[1] ?? throw new \Exception("Invalid banner name: " . $group["name"])];
		if (str_ends_with($obj["internal_tile"]["color"], "_wall")) {
			$obj["internal_tile"]["color"] = substr($obj["internal_tile"]["color"], 0, -5);
		}
	}

	if (str_ends_with($group["name"], "bed")) {
		preg_match("/minecraft:([a-z_]*)_bed/", $group["name"], $matches);
		$obj["internal_tile"] = ["color" => $matches[1]];
	}

	if ($obj["type"] === "singular" && $obj["name"] === "minecraft:skull") {
		$obj["internal_tile"] = ["type" => [
				"minecraft:skeleton_skull" => "skeleton",
				"minecraft:skeleton_wall_skull" => "skeleton",
				"minecraft:wither_skeleton_skull" => "wither_skeleton",
				"minecraft:wither_skeleton_wall_skull" => "wither_skeleton",
				"minecraft:zombie_head" => "zombie",
				"minecraft:zombie_wall_head" => "zombie",
				"minecraft:player_head" => "player",
				"minecraft:player_wall_head" => "player",
				"minecraft:creeper_head" => "creeper",
				"minecraft:creeper_wall_head" => "creeper",
				"minecraft:dragon_head" => "dragon",
				"minecraft:dragon_wall_head" => "dragon",
				"minecraft:piglin_head" => "piglin",
				"minecraft:piglin_wall_head" => "piglin"
			][$group["name"]] ?? throw new Exception("Unknown skull type: " . $group["name"])];
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
	if ($defaults !== []) {
		$obj["defaults"] = $defaults;
	}
	if ($possible !== []) {
		$obj["values"] = $possible;
	}
	$failed ? $failedJTB[$group["name"]] = $obj : $jtb[$group["name"]] = $obj;
}

if ($failedJTB !== []) echo "\e[31mFailed to convert " . count($failedJTB) . " blocks\e[39m" . PHP_EOL;
echo "Converted " . count($jtb) . " blocks" . PHP_EOL;
file_put_contents("debug/java-to-bedrock-fail.json", json_encode($failedJTB, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
file_put_contents("../java-to-bedrock.json", json_encode($jtb, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

echo "Testing mappings..." . PHP_EOL;
$succeeded = 0;
$failedTests = [];
foreach ($javaToBedrock as $java => $bedrock) {
	$pre = $java;
	$java = toBedrock($java, $jtb);
	if ($java === null) {
		continue;
	}
	$bedrock = sortState($bedrock);
	if ($java === $bedrock) {
		$succeeded++;
	} else {
		$failedTests[] = ["pre" => $pre, "java" => $java, "bedrock" => $bedrock];
	}
}
echo "Successfully tested $succeeded mappings, " . (count($failedTests)) . " failed" . PHP_EOL;
file_put_contents("debug/java-to-bedrock-tests.json", json_encode($failedTests, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

function toBedrock(string $java, $jtb): string|null
{
	preg_match("/^([a-z\d:_]+)(?:\[(.+)])?$/", $java, $matches);
	if (count($matches) === 0) {
		throw new RuntimeException("Invalid java block: $java");
	}
	$javaName = $matches[1];
	$data = $jtb[$javaName] ?? null;
	if ($data === null) {
		return null;
	}
	if (isset($matches[2])) {
		$states = [];
		foreach (explode(",", $matches[2]) as $state) {
			$state = explode("=", $state);
			if (count($state) !== 2) {
				throw new RuntimeException("Invalid java block state: $java");
			}
			$states[$state[0]] = $state[1];
		}
	} else {
		$states = [];
	}
	foreach ($data["defaults"] ?? [] as $key => $value) {
		if (!isset($states[$key])) {
			$states[$key] = $value;
		}
	}
	return processState($data, $java, $states);
}

/**
 * @param mixed   $data
 * @param mixed   $java
 * @param array   $states
 * @param Closure $r
 * @return string
 */
function processState(mixed $data, mixed $java, array $states): string
{
	switch ($data["type"]) {
		case "none":
			break;
		case "singular":
			$java = $data["name"];
			processStates($states, $data);
			$java .= (count($states) === 0 ? "" : "[" . implode(",", array_map(static function (string $key, string $value) {
					return "$key=$value";
				}, array_keys($states), $states)) . "]");
			break;
		case "multi":
			$d = $data["multi_states"][$states[$data["multi_name"]]] ?? $data["multi_states"]["default"];
			unset($states[$data["multi_name"]]);
			processStates($states, $data);
			processStates($states, $d);
			$java = $d["name"] . (count($states) === 0 ? "" : "[" . implode(",", array_map(static function (string $key, string $value) {
						return "$key=$value";
					}, array_keys($states), $states)) . "]");
			break;
		case "combined":
			$keys = [];
			foreach ($data["combined_names"] as $key) {
				$keys[] = $states[$key];
				unset($states[$key]);
			}
			$r = function ($data, array $keys) use (&$r) {
				if (!is_array($data)) {
					return $data;
				}
				$key = array_shift($keys);
				if (!isset($data[$key])) {
					return null;
				}
				return $r($data[$key], $keys);
			};
			$value = $r($data["combined_states"], $keys) ?? $data["combined_states"]["default"];
			processStates($states, $data);
			$states[$data["target_name"]] = $value;
			$java = $data["name"] . (count($states) === 0 ? "" : "[" . implode(",", array_map(static function (string $key, string $value) {
						return "$key=$value";
					}, array_keys($states), $states)) . "]");
			break;
		case "combined_multi":
			$keys = [];
			foreach ($data["combined_names"] as $key) {
				$keys[] = $states[$key];
				unset($states[$key]);
			}
			$r = function (array $data, array $keys) use (&$r) {
				if (count($keys) === 0) {
					return $data;
				}
				$key = array_shift($keys);
				return $r($data[$key], $keys);
			};
			$d = $r($data["combined_states"], $keys);
			processStates($states, $data);
			foreach ($d as $key => $value) {
				$states[$key] = $value;
			}
			$java = $data["name"] . (count($states) === 0 ? "" : "[" . implode(",", array_map(static function (string $key, string $value) {
						return "$key=$value";
					}, array_keys($states), $states)) . "]");
			break;
	}
	return sortState($java);
}

function processStates(&$states, $data)
{
	foreach ($data["state_removals"] ?? [] as $state) {
		unset($states[$state]);
	}
	/**
	 * @var string $old
	 * @var string $new
	 */
	foreach ($data["state_renames"] ?? [] as $old => $new) {
		if (isset($states[$old])) {
			$value = $states[$old];
			unset($states[$old]);
			$states[$new] = $value;
		}
	}
	/**
	 * @var string   $state
	 * @var string[] $values
	 */
	foreach ($data["state_values"] ?? [] as $state => $values) {
		if (isset($states[$state])) {
			$states[$state] = $values[$states[$state]] ?? $states[$state];
		}
	}
	foreach ($data["state_additions"] ?? [] as $state => $value) {
		$states[$state] = $value;
	}
}

function sortState(string $state): string
{
	preg_match("/^(.+)\[(.+)\]$/", $state, $matches);
	if (count($matches) === 0) {
		return $state;
	}
	$states = explode(",", $matches[2]);
	sort($states);
	return $matches[1] . "[" . implode(",", $states) . "]";
}

unset($jtb["minecraft:mushroom_stem"]["combined_states"]["false"]["false"]["false"]); //this will falsely override the normal mushroom blocks

$btj = [];
foreach ($jtb as $java => $bedrockData) {
	revertJavaToBedrock($java, $bedrockData, $btj, $customData);
}

$stem = $jtb["minecraft:mushroom_stem"];
$stem["name"] = "minecraft:red_mushroom_block";
revertJavaToBedrock("minecraft:mushroom_stem", $stem, $btj, $customData);

function revertJavaToBedrock($java, $bedrockData, &$btj, $customData)
{
	$mergeStates = function (&$a, $b, $bedrock) use ($customData) {
		if ($a["type"] === "singular" && $b["type"] === "singular") {
			if (in_array($b["name"], $customData["btj_ignore"][$bedrock] ?? [])) {
				return;
			}
			if (in_array($a["name"], $customData["btj_ignore"][$bedrock] ?? [])) {
				$a = $b;
				return;
			}
			$a["type"] = "multi";
			$nameA = $a["name"];
			$nameB = $b["name"];
			unset($a["name"]);
			$addA = $a["state_additions"] ?? [];
			$addB = $b["state_additions"] ?? [];
			$a["state_additions"] = [];
			foreach ($addA as $key => $value) {
				if ($addB[$key] === $value) {
					$a["state_additions"][$key] = $value;
					unset($addB[$key], $addA[$key]);
				}
			}
			$removeA = $a["state_removals"] ?? [];
			$removeB = $b["state_removals"] ?? [];
			$a["state_removals"] = [];
			$potValues = [];
			foreach ($removeA as $key => $value) {
				$potValues[$key] = [$value, null];
			}
			foreach ($removeB as $key => $value) {
				if (isset($potValues[$key])) {
					$potValues[$key][1] = $value;
				} else {
					$potValues[$key] = [null, $value];
				}
			}
			foreach ($removeA as $key => $value) {
				if (isset($removeB[$key])) {
					$a["state_removals"][$key] = $value;
					unset($removeA[$key], $removeB[$key]);
				}
			}
			if (count($a["state_removals"]) === 1) {
				$a["multi_name"] = array_key_first($a["state_removals"]);
				unset($a["state_removals"]);
			} else if (isset($customData["btj_multi"][$bedrock])) {
				$a["multi_name"] = $customData["btj_multi"][$bedrock];
				unset($a["state_removals"][$a["multi_name"]]);
			} else {
				throw new \Exception("Failed to find multi name for $bedrock ($nameA, $nameB)" . json_encode($a) . json_encode($b));
			}
			$multi = [];
			$multi[$potValues[$a["multi_name"]][0] ?? "default"] = ["name" => $nameA, "state_additions" => $addA, "state_removals" => $removeA];
			$multi[$potValues[$a["multi_name"]][1] ?? "default"] = ["name" => $nameB, "state_additions" => $addB, "state_removals" => $removeB];
			$a["multi_states"] = $multi;
			foreach ($b["defaults"] ?? [] as $key => $value) {
				if (!isset($a["defaults"][$key])) {
					$a["defaults"][$key] = $value;
				}
			}
			return;
		}
		if ($a["type"] === "multi" && $b["type"] === "singular") {
			$multi = $a["multi_states"];
			$multi[$b["state_removals"][$a["multi_name"]] ?? "default"] = ["name" => $b["name"], "state_additions" => $b["state_additions"] ?? [], "state_removals" => $b["state_removals"] ?? []];
			$a["multi_states"] = $multi;
			return;
		}
		if ($a["type"] === "combined_multi" && $b["type"] === "combined_multi" && count($a["combined_names"]) === 1 && count($b["combined_names"]) === 1 && $a["combined_names"][0] === $b["combined_names"][0]) {
			$a["type"] = "multi";
			$a["multi_name"] = $a["combined_names"][0];
			unset($a["combined_names"]);
			$multi = [];
			foreach ($a["combined_states"] as $key => $value) {
				$multi[$key] = ["name" => $a["name"], "state_additions" => $value];
			}
			foreach ($b["combined_states"] as $key => $value) {
				$multi[$key] = ["name" => $b["name"], "state_additions" => $value];
			}
			$a["multi_states"] = $multi;
			unset($a["combined_states"], $a["name"]);
			return;
		}
		echo "Failed to merge states: " . json_encode($a) . " and " . json_encode($b) . PHP_EOL;
	};
	switch ($bedrockData["type"]) {
		case "none":
			if (isset($btj[$java])) {
				$mergeStates($btj[$java], $bedrockData, $java);
				return;
			}
			$btj[$java] = ["type" => "none"];
			break;
		case "singular":
			$state = ["type" => "singular", "name" => $java];
			flipStateTranslation($state, $bedrockData);
			if (isset($bedrockData["defaults"])) {
				$state["defaults"] = $bedrockData["defaults"];
			}
			if (isset($btj[$bedrockData["name"]])) {
				$mergeStates($btj[$bedrockData["name"]], $state, $bedrockData["name"]);
				return;
			}
			$btj[$bedrockData["name"]] = $state;
			break;
		case "multi":
			foreach ($bedrockData["multi_states"] as $value => $data) {
				$state = ["type" => "singular", "name" => $java];
				flipStateTranslation($state, $bedrockData);
				flipStateTranslation($state, $data);
				if (isset($bedrockData["defaults"])) {
					$state["defaults"] = $bedrockData["defaults"];
				}
				$state["state_additions"][$bedrockData["multi_name"]] = $value;
				if (isset($btj[$data["name"]])) {
					$mergeStates($btj[$data["name"]], $state, $data["name"]);
					continue;
				}
				$btj[$data["name"]] = $state;
			}
			break;
		case "combined":
			$state = ["type" => "combined_multi", "name" => $java, "combined_names" => [$bedrockData["target_name"]]];
			flipStateTranslation($state, $bedrockData);
			$map = [];
			$mapReader = function ($data, $keys) use (&$mapReader, &$map, $bedrockData) {
				foreach ($data as $key => $value) {
					$new = $keys;
					$new[] = $key;
					if (is_array($value)) {
						$mapReader($value, $new);
					} else {
						if (isset($map[$value])) {
							foreach ($new as $k => $v) {
								if ($v === $bedrockData["defaults"][$bedrockData["combined_names"][$k]]) {
									$map[$value][$bedrockData["combined_names"][$k]] = $v;
								}
							}
						} else {
							$map[$value] = [];
							foreach ($new as $i => $k) {
								$map[$value][$bedrockData["combined_names"][$i]] = $k;
							}
						}
					}
				}
			};
			$default = $bedrockData["combined_states"]["default"] ?? null;
			unset($bedrockData["combined_states"]["default"]);
			$mapReader($bedrockData["combined_states"], []);
			if ($default !== null) {
				$map[$default] = [];
				foreach ($bedrockData["defaults"] ?? [] as $key => $value) {
					if (in_array($key, $bedrockData["combined_names"], true)) {
						$map[$default][$key] = $value;
					}
				}
			}
			$state["combined_states"] = $map;
			if (isset($bedrockData["defaults"])) {
				$state["defaults"] = $bedrockData["defaults"];
			}
			if (isset($btj[$bedrockData["name"]])) {
				$mergeStates($btj[$bedrockData["name"]], $state, $bedrockData["name"]);
				return;
			}
			$btj[$bedrockData["name"]] = $state;
			break;
		case "combined_multi":
			$state = ["type" => "combined_multi", "name" => $java];
			flipStateTranslation($state, $bedrockData);
			$map = [];
			$names = [];
			$mapReader = function ($data, $keys) use (&$mapReader, &$map, &$names, $bedrockData) {
				foreach ($data as $key => $value) {
					$new = $keys;
					$new[] = $key;
					if (is_array($value) && is_array(array_values($value)[0])) {
						$mapReader($value, $new);
					} else {
						$writeState = function (&$value, $keys, $values) use (&$writeState, $bedrockData) {
							if (count($keys) > 0) {
								$k = array_shift($keys);
								if (!isset($value[$k])) {
									$value[$k] = [];
								}
								$writeState($value[$k], $keys, $values);
								return;
							}
							if ($value !== []) {
								foreach ($values as $i => $j) {
									if ($j === $bedrockData["defaults"][$bedrockData["combined_names"][$i]]) {
										$value[$bedrockData["combined_names"][$i]] = $j;
									}
								}
							} else {
								foreach ($values as $i => $j) {
									$value[$bedrockData["combined_names"][$i]] = $j;
								}
							}
						};
						$writeState($map, array_values($value), $new);
						$names = array_keys($value);
					}
				}
			};
			$mapReader($bedrockData["combined_states"], []);
			$state["combined_states"] = $map;
			$state["combined_names"] = $names;
			if (isset($bedrockData["defaults"])) {
				$state["defaults"] = $bedrockData["defaults"];
			}
			if (isset($btj[$bedrockData["name"]])) {
				$mergeStates($btj[$bedrockData["name"]], $state, $bedrockData["name"]);
				return;
			}
			$btj[$bedrockData["name"]] = $state;
			break;
	}
}

foreach ($btj as $key => &$value) {
	if (isset($value["state_removals"])) {
		$value["state_removals"] = array_keys($value["state_removals"]);
	}
	if (isset($value["state_additions"]) && $value["state_additions"] === []) {
		unset($value["state_additions"]);
	}
	if (isset($value["state_removals"]) && $value["state_removals"] === []) {
		unset($value["state_removals"]);
	}
	foreach ($value["multi_states"] ?? [] as $k => $v) {
		if (isset($v["state_removals"])) {
			$value["multi_states"][$k]["state_removals"] = array_keys($v["state_removals"]);
		}
		if (isset($v["state_additions"]) && $v["state_additions"] === []) {
			unset($value["multi_states"][$k]["state_additions"]);
		}
		if (isset($v["state_removals"]) && $v["state_removals"] === []) {
			unset($value["multi_states"][$k]["state_removals"]);
		}
	}
}
unset($value);

$btj["minecraft:invisible_bedrock"] = ["type" => "singular", "name" => "minecraft:barrier"];

function flipStateTranslation(&$state, $bedrockData)
{
	if (isset($bedrockData["state_additions"])) {
		$state["state_removals"] = $bedrockData["state_additions"];
	}
	if (isset($bedrockData["internal_tile"])) {
		foreach ($bedrockData["internal_tile"] as $key => $value) {
			$state["state_removals"][$key] = $value;
		}
		$state["internal_tile"] = $bedrockData["internal_tile"];
	}
	if (isset($bedrockData["state_renames"])) {
		$state["state_renames"] = array_flip($bedrockData["state_renames"]);
	}
	if (isset($bedrockData["state_values"])) {
		$state["state_values"] = [];
		foreach ($bedrockData["state_values"] as $key => $value) {
			$state["state_values"][$state["state_renames"][$key] ?? $key] = array_flip($value);
		}
	}
	if (isset($bedrockData["state_removals"])) {
		$state["state_additions"] = [];
		foreach ($bedrockData["state_removals"] as $key) {
			$state["state_additions"][$key] = $bedrockData["defaults"][$key];
		}
	}
}

echo "Converted " . count($btj) . " blocks" . PHP_EOL;
file_put_contents("../bedrock-to-java.json", json_encode($btj, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

foreach ($javaToBedrock as $java => $bedrock) {
	if (str_contains($java, "waterlogged = false")) {
		preg_match(" /^minecraft:(.+)\[(.+)\]$/", $java, $matches);
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
		$javaToBedrock["minecraft:" . $matches[1] . "[" . implode(", ", $states) . "]"] = $bedrock;
	}
}

foreach ($javaToBedrock as $java => $bedrock) {
	if (str_contains($java, "powered = false")) {
		preg_match(" /^minecraft:(.+)\[(.+)\]$/", $java, $matches);
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
		$javaToBedrock["minecraft:" . $matches[1] . "[" . implode(", ", $states) . "]"] = $bedrock;
	}
}

foreach ($javaToBedrock as $java => $bedrock) {
	if (!isset($javaToBedrock[$java . "[]"]) && preg_match("/^minecraft:([a-z_]+)$/", $java, $matches)) {
		$javaToBedrock[$java . "[]"] = $bedrock;
	}
}

ksort($javaToBedrock);

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

$rotations = ["rotate" => [], "xFlip" => [], "yFlip" => [], "zFlip" => []];
$stateRotations = [];
$missingRotations = [];

foreach ($javaToBedrock as $state => $id) {
	if (!str_ends_with($state, "]")) {
		continue; //no properties
	}
	remapProperties($state, $id, $rotationData, $javaToBedrock, $rotations["rotate"], $missingRotations, $stateRotations, "rotate");
	remapProperties($state, $id, $flipData["x"], $javaToBedrock, $rotations["xFlip"], $missingRotations, $stateRotations, "flip-x");
	remapProperties($state, $id, $flipData["z"], $javaToBedrock, $rotations["yFlip"], $missingRotations, $stateRotations, "flip-y");
	remapProperties($state, $id, $flipData["y"], $javaToBedrock, $rotations["zFlip"], $missingRotations, $stateRotations, "flip-z");
}
file_put_contents("debug/missing-rotations.json", json_encode($missingRotations, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
file_put_contents("debug/rotation-data-all.json", json_encode($rotations, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
echo "Rotated " . array_sum(array_map(static fn($e) => count($e), $rotations)) . " blocks" . PHP_EOL;
file_put_contents("../manipulation-data.json", json_encode($stateRotations, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

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

	$d = toBedrock($state, $jtb);
	if ($d !== null) {
		$toBedrock[$legacyId] = $d;
	} else {
		$missingData[$legacyId] = $state;
	}
}

array_multisort(array_keys($toBedrock), SORT_NATURAL, array_values($toBedrock), SORT_NATURAL, $toBedrock);
file_put_contents("../legacy-conversion-map.json", json_encode($toBedrock, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
file_put_contents("debug/missing-legacy.json", json_encode($missingData, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
echo "Converted " . count($toBedrock) . " legacy blocks" . PHP_EOL;

$itemData = json_decode(getData("https://raw.githubusercontent.com/GeyserMC/mappings/master/items.json"), true);
$items = [];

foreach ($itemData as $java => $item) {
	$items[$java] = ["name" => $item["bedrock_identifier"], "damage" => $item["bedrock_data"]];
}

echo "Converted " . count($items) . " items" . PHP_EOL;
file_put_contents("../item-conversion-map.json", json_encode($items, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

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

function remapProperties(string $state, string $id, array $remaps, array $bedrockMapping, array &$save, array &$missing, array &$stateSave, string $key)
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
			$save[$id] = $newID = $bedrockMapping[$newState];

			preg_match("/(.*)\[(.*?)]/", $id, $matches);
			preg_match("/(.*)\[(.*?)]/", $newID, $matches2);
			$pre = [];
			foreach (explode(",", $matches[2]) as $property) {
				$data = explode("=", $property);
				$pre[$data[0]] = $data[1];
			}
			$post = [];
			foreach (explode(",", $matches2[2]) as $property) {
				$data = explode("=", $property);
				$post[$data[0]] = $data[1];
			}
			$diff = array_diff_assoc($post, $pre);
			foreach ($diff as $k => $v) {
				$stateSave[$matches[1]][$key][$k][$pre[$k]] = $post[$k];
			}
		}
	} else {
		$missing["$id ($state)"] = $newState;
	}
}