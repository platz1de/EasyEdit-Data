<?php

/**
 * Welcome to the properly worst script in history
 */

use Ramsey\Uuid\Uuid;

error_reporting(E_ALL);

try {
	require_once("phar://PocketMine-MP.phar/vendor/autoload.php");
} catch (Throwable) {
	echo "Drop a valid PocketMine Phar into the generation folder";
	return;
}

$repo = json_decode(file_get_contents("../dataRepo.json"), true, 512, JSON_THROW_ON_ERROR);
$repo["version"] = Uuid::uuid4();
$repo["latest"]["state-version"] =
	(1 << 24) | //major
	(19 << 16) | //minor
	(80 << 8) | //patch
	(11); //revision
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

	$obj = ["values" => $possible, "defaults" => $defaults];
	$failed = false;
	if (!$hasChanges) {
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
				echo "Added $key to " . $group["name"] . PHP_EOL;
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

	//apply state renames and search for value changes
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
				$obj["renames"][$prev] = $past;
				return true;
			}
			$map = [];
			foreach ($values[$prev] as $i => $v) {
				if (isset($map[$v]) && $map[$v] !== $bedrockValues[$past][$i]) {
					return false;
				}
				$map[$v] = $bedrockValues[$past][$i];
			}
			$obj["renames"][$prev] = $past;
			$obj["remaps"][$past] = $map;
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
			$obj["additions"][$key] = $value[0];
			unset($bedrockValues[$key]);
		}
		if (in_array($key, $customData["jtb_additions"][$group["name"]] ?? [], true)) {
			$value = array_unique($value);
			if (count($value) !== 1) {
				throw new RuntimeException("Added state $key with multiple values");
			}
			$obj["additions"][$key] = $value[0];
			unset($bedrockValues[$key]);
		}
	}
	foreach ($values as $key => $value) {
		if (in_array($key, $customData["jtb_removals"]["global"], true)) {
			$obj["removals"][] = $key;
			unset($values[$key]);
		}
		if (in_array($key, $customData["jtb_removals"][$group["name"]] ?? [], true)) {
			$obj["removals"][] = $key;
			unset($values[$key]);
		}
	}
	if (count($bedrockStates) === 1) {
		if ($bedrockValues !== [] && $values === []) {
			foreach ($bedrockValues as $key => $value) {
				$value = array_unique($value);
				if (count($value) !== 1) {
					throw new RuntimeException("Added state $key with multiple values");
				}
				$obj["additions"][$key] = $value[0];
				unset($bedrockValues[$key]);
			}
		} elseif ($bedrockValues === [] && $values !== []) {
			foreach ($values as $key => $value) {
				$obj["removals"][] = $key;
				unset($values[$key]);
			}
		}
		$obj["name"] = array_key_first($bedrockStates);
	}

	if (str_ends_with($group["name"], "_slab")) {
		$fail = false;
		$types = ["top" => [], "bottom" => [], "double" => []];
		/** @var string $state */
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
			$obj["identifier"] = ["type"];
			$obj["removals"][] = "type";
			$obj["mapping"] = [
				"top" => [
					"name" => $normalName,
					"additions" => [
						"top_slot_bit" => "true"
					]
				],
				"bottom" => [
					"name" => $normalName,
					"additions" => [
						"top_slot_bit" => "false"
					]
				],
				"double" => [
					"name" => $doubleState,
					"additions" => [
						"top_slot_bit" => "false"
					]
				]
			];
			unset($values["type"], $bedrockValues["top_slot_bit"]);
		}
	}

	if (str_ends_with($group["name"], "_button")) {
		$obj["identifier"] = [
			"face",
			"facing"
		];
		$obj["removals"][] = "face";
		$obj["removals"][] = "facing";
		$obj["mapping"] = [
			"ceiling" => ["def" => ["additions" => ["facing_direction" => "0"]]],
			"floor" => ["def" => ["additions" => ["facing_direction" => "1"]]],
			"wall" => [
				"east" => ["additions" => ["facing_direction" => "5"]],
				"north" => ["additions" => ["facing_direction" => "2"]],
				"south" => ["additions" => ["facing_direction" => "3"]],
				"west" => ["additions" => ["facing_direction" => "4"]]
			]
		];
		unset($values["face"], $values["facing"], $bedrockValues["facing_direction"]);
	}

	if ($group["name"] === "minecraft:water" || $group["name"] === "minecraft:lava") {
		$obj["renames"] = [
			"level" => "liquid_depth"
		];
		$obj["identifier"] = ["level"];
		$flowingName = "minecraft:flowing_" . ($group["name"] === "minecraft:water" ? "water" : "lava");
		$obj["mapping"] = [
			"0" => ["name" => $group["name"]],
			"def" => ["name" => $flowingName],
		];
		unset($values["level"], $bedrockValues["liquid_depth"]);
	}

	if (in_array($group["name"], ["minecraft:furnace", "minecraft:blast_furnace", "minecraft:smoker", "minecraft:redstone_ore", "minecraft:deepslate_redstone_ore", "minecraft:redstone_lamp"], true)) {
		$obj["identifier"] = ["lit"];
		$obj["removals"][] = "lit";
		$obj["mapping"] = [
			"true" => ["name" => "minecraft:lit_" . substr($group["name"], 10)],
			"false" => ["name" => $group["name"]]
		];
		unset($values["lit"]);
	}

	if ($group["name"] === "minecraft:cave_vines" || $group["name"] === "minecraft:cave_vines_plant") {
		$obj["identifier"] = ["berries"];
		$obj["removals"][] = "berries";
		$obj["mapping"] = [
			"true" => ["name" => $group["name"] === "minecraft:cave_vines" ? "minecraft:cave_vines_head_with_berries" : "minecraft:cave_vines_body_with_berries"],
			"false" => ["name" => "minecraft:cave_vines"]
		];
		unset($values["berries"]);
	}

	if ($group["name"] === "minecraft:comparator") {
		$obj["identifier"] = ["powered"];
		$obj["renames"]["powered"] = "output_lit_bit";
		$obj["mapping"] = [
			"true" => ["name" => "minecraft:powered_comparator"],
			"false" => ["name" => "minecraft:unpowered_comparator"]
		];
		unset($values["powered"], $bedrockValues["output_lit_bit"]);
	}

	if ($group["name"] === "minecraft:daylight_detector") {
		$obj["identifier"] = ["inverted"];
		$obj["removals"][] = "inverted";
		$obj["mapping"] = [
			"true" => ["name" => "minecraft:daylight_detector_inverted"],
			"false" => ["name" => "minecraft:daylight_detector"]
		];
		unset($values["inverted"]);
	}

	if ($group["name"] === "minecraft:lever") {
		$obj["identifier"] = [
			"face",
			"facing",
			"powered"
		];
		$obj["removals"][] = "face";
		$obj["removals"][] = "facing";
		$obj["removals"][] = "powered";
		$obj["mapping"] = [
			"ceiling" => [
				"east" => [
					"false" => ["additions" => ["lever_direction" => "down_east_west", "open_bit" => "true"]],
					"true" => ["additions" => ["lever_direction" => "down_east_west", "open_bit" => "false"]]
				],
				"north" => [
					"false" => ["additions" => ["lever_direction" => "down_north_south", "open_bit" => "false"]],
					"true" => ["additions" => ["lever_direction" => "down_north_south", "open_bit" => "true"]]
				],
				"south" => [
					"false" => ["additions" => ["lever_direction" => "down_north_south", "open_bit" => "true"]],
					"true" => ["additions" => ["lever_direction" => "down_north_south", "open_bit" => "false"]]
				],
				"west" => [
					"false" => ["additions" => ["lever_direction" => "down_east_west", "open_bit" => "false"]],
					"true" => ["additions" => ["lever_direction" => "down_east_west", "open_bit" => "true"]]
				]
			],
			"floor" => [
				"east" => [
					"false" => ["additions" => ["lever_direction" => "up_east_west", "open_bit" => "true"]],
					"true" => ["additions" => ["lever_direction" => "up_east_west", "open_bit" => "false"]]
				],
				"north" => [
					"false" => ["additions" => ["lever_direction" => "up_north_south", "open_bit" => "false"]],
					"true" => ["additions" => ["lever_direction" => "up_north_south", "open_bit" => "true"]]
				],
				"south" => [
					"false" => ["additions" => ["lever_direction" => "up_north_south", "open_bit" => "true"]],
					"true" => ["additions" => ["lever_direction" => "up_north_south", "open_bit" => "false"]]
				],
				"west" => [
					"false" => ["additions" => ["lever_direction" => "up_east_west", "open_bit" => "false"]],
					"true" => ["additions" => ["lever_direction" => "up_east_west", "open_bit" => "true"]]
				]
			],
			"wall" => [
				"east" => [
					"false" => ["additions" => ["lever_direction" => "east", "open_bit" => "false"]],
					"true" => ["additions" => ["lever_direction" => "east", "open_bit" => "true"]]
				],
				"north" => [
					"false" => ["additions" => ["lever_direction" => "north", "open_bit" => "false"]],
					"true" => ["additions" => ["lever_direction" => "north", "open_bit" => "true"]]
				],
				"south" => [
					"false" => ["additions" => ["lever_direction" => "south", "open_bit" => "false"]],
					"true" => ["additions" => ["lever_direction" => "south", "open_bit" => "true"]]
				],
				"west" => [
					"false" => ["additions" => ["lever_direction" => "west", "open_bit" => "false"]],
					"true" => ["additions" => ["lever_direction" => "west", "open_bit" => "true"]]
				]
			]
		];
		unset($values["face"], $values["facing"], $values["powered"], $bedrockValues["lever_direction"], $bedrockValues["open_bit"]);
	}

	if ($group["name"] === "minecraft:piston_head") {
		$obj["identifier"] = ["type"];
		$obj["removals"][] = "type";
		$obj["mapping"] = [
			"normal" => ["name" => "minecraft:piston_arm_collision"],
			"sticky" => ["name" => "minecraft:sticky_piston_arm_collision"]
		];
		unset($values["type"]);
	}

	if ($group["name"] === "minecraft:redstone_torch" || $group["name"] === "minecraft:redstone_wall_torch") {
		$obj["identifier"] = ["lit"];
		$obj["removals"][] = "lit";
		$obj["mapping"] = [
			"true" => ["name" => "minecraft:redstone_torch"],
			"false" => ["name" => "minecraft:unlit_redstone_torch"]
		];
		unset($values["lit"]);
	}

	if ($group["name"] === "minecraft:repeater") {
		$obj["identifier"] = ["powered"];
		$obj["removals"][] = "powered";
		$obj["mapping"] = [
			"true" => ["name" => "minecraft:powered_repeater"],
			"false" => ["name" => "minecraft:unpowered_repeater"]
		];
		unset($values["powered"]);
	}

	if ($group["name"] === "minecraft:brown_mushroom_block" || $group["name"] === "minecraft:red_mushroom_block") {
		$obj["identifier"] = [
			"down",
			"up",
			"north",
			"south",
			"west",
			"east"
		];
		$obj["removals"][] = "down";
		$obj["removals"][] = "up";
		$obj["removals"][] = "north";
		$obj["removals"][] = "south";
		$obj["removals"][] = "west";
		$obj["removals"][] = "east";
		$obj["mapping"] = [
			"false" => [
				"false" => [
					"false" => [
						"false" => [
							"false" => [
								"false" => ["additions" => ["huge_mushroom_bits" => "0"]] //none
							]
						]
					]
				],
				"true" => [
					"true" => [
						"false" => [
							"true" => [
								"false" => ["additions" => ["huge_mushroom_bits" => "1"]] //up, north, west
							],
							"false" => [
								"false" => ["additions" => ["huge_mushroom_bits" => "2"]], //up, north
								"true" => ["additions" => ["huge_mushroom_bits" => "3"]] //up, north, east
							]
						]
					],
					"false" => [
						"false" => [
							"true" => [
								"false" => ["additions" => ["huge_mushroom_bits" => "4"]] //up, west
							],
							"false" => [
								"false" => ["additions" => ["huge_mushroom_bits" => "5"]], //up
								"true" => ["additions" => ["huge_mushroom_bits" => "6"]] //up, east
							]
						],
						"true" => [
							"true" => [
								"false" => ["additions" => ["huge_mushroom_bits" => "7"]] //up, south, west
							],
							"false" => [
								"false" => ["additions" => ["huge_mushroom_bits" => "8"]], //up, south
								"true" => ["additions" => ["huge_mushroom_bits" => "9"]] //up, south, east
							]
						]
					]
				]
			],
			"def" => ["additions" => ["huge_mushroom_bits" => "14"]] //all sides
		];
		unset($values["west"], $values["east"], $values["north"], $values["south"], $values["up"], $values["down"], $bedrockValues["huge_mushroom_bits"]);
	}

	if ($group["name"] === "minecraft:mushroom_stem") {
		$obj["name"] = "minecraft:brown_mushroom_block";
		$obj["identifier"] = [
			"down",
			"up",
			"north",
			"south",
			"west",
			"east"
		];
		$obj["removals"][] = "down";
		$obj["removals"][] = "up";
		$obj["removals"][] = "north";
		$obj["removals"][] = "south";
		$obj["removals"][] = "west";
		$obj["removals"][] = "east";
		$obj["mapping"] = [
			"false" => [
				"false" => [
					"false" => [
						"false" => [
							"false" => [
								"false" => ["additions" => ["huge_mushroom_bits" => "0"]] //none
							]
						]
					],
					"true" => [
						"true" => [
							"true" => [
								"true" => ["additions" => ["huge_mushroom_bits" => "10"]] //default stem (all horizontal sides)
							]
						]
					]
				],
			],
			"def" => ["additions" => ["huge_mushroom_bits" => "15"]] //all stem sides
		];
		unset($values["west"], $values["east"], $values["north"], $values["south"], $values["up"], $values["down"], $bedrockValues["huge_mushroom_bits"]);
	}

	if ($group["name"] === "minecraft:vine") {
		$obj["identifier"] = [
			"east",
			"north",
			"west",
			"south",
		];
		$obj["removals"][] = "up"; //up is automatically added in bedrock
		$obj["removals"][] = "east";
		$obj["removals"][] = "north";
		$obj["removals"][] = "west";
		$obj["removals"][] = "south";
		$obj["mapping"] = [
			"false" => [
				"false" => [
					"false" => [
						"false" => ["additions" => ["vine_direction_bits" => "0"]], //none
						"true" => ["additions" => ["vine_direction_bits" => "1"]], //south
					],
					"true" => [
						"false" => ["additions" => ["vine_direction_bits" => "2"]], //west
						"true" => ["additions" => ["vine_direction_bits" => "3"]], //south, west
					]
				],
				"true" => [
					"false" => [
						"false" => ["additions" => ["vine_direction_bits" => "4"]], //north
						"true" => ["additions" => ["vine_direction_bits" => "5"]], //south, north
					],
					"true" => [
						"false" => ["additions" => ["vine_direction_bits" => "6"]], //north, west
						"true" => ["additions" => ["vine_direction_bits" => "7"]], //south, north, west
					]
				]
			],
			"true" => [
				"false" => [
					"false" => [
						"false" => ["additions" => ["vine_direction_bits" => "8"]], //east
						"true" => ["additions" => ["vine_direction_bits" => "9"]], //south, east
					],
					"true" => [
						"false" => ["additions" => ["vine_direction_bits" => "10"]], //east, west
						"true" => ["additions" => ["vine_direction_bits" => "11"]], //south, east, west
					]
				],
				"true" => [
					"false" => [
						"false" => ["additions" => ["vine_direction_bits" => "12"]], //north, east
						"true" => ["additions" => ["vine_direction_bits" => "13"]], //south, north, east
					],
					"true" => [
						"false" => ["additions" => ["vine_direction_bits" => "14"]], //north, east, west
						"true" => ["additions" => ["vine_direction_bits" => "15"]], //south, north, east, west
					]
				]
			]
		];
		unset($values["west"], $values["east"], $values["north"], $values["south"], $values["up"], $bedrockValues["vine_direction_bits"]);
	}

	if ($group["name"] === "minecraft:sculk_vein" || $group["name"] === "minecraft:glow_lichen") {
		$obj["identifier"] = [
			"east",
			"north",
			"west",
			"south",
			"up",
			"down"
		];
		$obj["removals"][] = "east";
		$obj["removals"][] = "north";
		$obj["removals"][] = "west";
		$obj["removals"][] = "south";
		$obj["removals"][] = "up";
		$obj["removals"][] = "down";
		$obj["mapping"] = [ //copilot go brrrrrrrrrrrrrrrr
			"false" => [
				"false" => [
					"false" => [
						"false" => [
							"false" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "0"]], //none
								"true" => ["additions" => ["multi_face_direction_bits" => "1"]], //down
							],
							"true" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "2"]], //up
								"true" => ["additions" => ["multi_face_direction_bits" => "3"]], //up, down
							]
						],
						"true" => [
							"false" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "4"]], //north
								"true" => ["additions" => ["multi_face_direction_bits" => "5"]], //north, down
							],
							"true" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "6"]], //north, up
								"true" => ["additions" => ["multi_face_direction_bits" => "7"]], //north, up, down
							]
						]
					],
					"true" => [
						"false" => [
							"false" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "8"]], //south
								"true" => ["additions" => ["multi_face_direction_bits" => "9"]], //south, down
							],
							"true" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "10"]], //south, up
								"true" => ["additions" => ["multi_face_direction_bits" => "11"]], //south, up, down
							]
						],
						"true" => [
							"false" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "12"]], //north, south
								"true" => ["additions" => ["multi_face_direction_bits" => "13"]], //north, south, down
							],
							"true" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "14"]], //north, south, up
								"true" => ["additions" => ["multi_face_direction_bits" => "15"]], //north, south, up, down
							]
						]
					]
				],
				"true" => [
					"false" => [
						"false" => [
							"false" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "16"]], //west
								"true" => ["additions" => ["multi_face_direction_bits" => "17"]], //west, down
							],
							"true" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "18"]], //west, up
								"true" => ["additions" => ["multi_face_direction_bits" => "19"]], //west, up, down
							]
						],
						"true" => [
							"false" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "20"]], //north, west
								"true" => ["additions" => ["multi_face_direction_bits" => "21"]], //north, west, down
							],
							"true" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "22"]], //north, west, up
								"true" => ["additions" => ["multi_face_direction_bits" => "23"]], //north, west, up, down
							]
						]
					],
					"true" => [
						"false" => [
							"false" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "24"]], //south, west
								"true" => ["additions" => ["multi_face_direction_bits" => "25"]], //south, west, down
							],
							"true" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "26"]], //south, west, up
								"true" => ["additions" => ["multi_face_direction_bits" => "27"]], //south, west, up, down
							]
						],
						"true" => [
							"false" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "28"]], //north, south, west
								"true" => ["additions" => ["multi_face_direction_bits" => "29"]], //north, south, west, down
							],
							"true" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "30"]], //north, south, west, up
								"true" => ["additions" => ["multi_face_direction_bits" => "31"]], //north, south, west, up, down
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
								"false" => ["additions" => ["multi_face_direction_bits" => "32"]], //east
								"true" => ["additions" => ["multi_face_direction_bits" => "33"]], //east, down
							],
							"true" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "34"]], //east, up
								"true" => ["additions" => ["multi_face_direction_bits" => "35"]], //east, up, down
							]
						],
						"true" => [
							"false" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "36"]], //north, east
								"true" => ["additions" => ["multi_face_direction_bits" => "37"]], //north, east, down
							],
							"true" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "38"]], //north, east, up
								"true" => ["additions" => ["multi_face_direction_bits" => "39"]], //north, east, up, down
							]
						]
					],
					"true" => [
						"false" => [
							"false" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "40"]], //south, east
								"true" => ["additions" => ["multi_face_direction_bits" => "41"]], //south, east, down
							],
							"true" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "42"]], //south, east, up
								"true" => ["additions" => ["multi_face_direction_bits" => "43"]], //south, east, up, down
							]
						],
						"true" => [
							"false" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "44"]], //north, south, east
								"true" => ["additions" => ["multi_face_direction_bits" => "45"]], //north, south, east, down
							],
							"true" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "46"]], //north, south, east, up
								"true" => ["additions" => ["multi_face_direction_bits" => "47"]], //north, south, east, up, down
							]
						]
					]
				],
				"true" => [
					"false" => [
						"false" => [
							"false" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "48"]], //west, east
								"true" => ["additions" => ["multi_face_direction_bits" => "49"]], //west, east, down
							],
							"true" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "50"]], //west, east, up
								"true" => ["additions" => ["multi_face_direction_bits" => "51"]], //west, east, up, down
							]
						],
						"true" => [
							"false" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "52"]], //north, west, east
								"true" => ["additions" => ["multi_face_direction_bits" => "53"]], //north, west, east, down
							],
							"true" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "54"]], //north, west, east, up
								"true" => ["additions" => ["multi_face_direction_bits" => "55"]], //north, west, east, up, down
							]
						]
					],
					"true" => [
						"false" => [
							"false" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "56"]], //south, west, east
								"true" => ["additions" => ["multi_face_direction_bits" => "57"]], //south, west, east, down
							],
							"true" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "58"]], //south, west, east, up
								"true" => ["additions" => ["multi_face_direction_bits" => "59"]], //south, west, east, up, down
							]
						],
						"true" => [
							"false" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "60"]], //north, south, west, east
								"true" => ["additions" => ["multi_face_direction_bits" => "61"]], //north, south, west, east, down
							],
							"true" => [
								"false" => ["additions" => ["multi_face_direction_bits" => "62"]], //north, south, west, east, up
								"true" => ["additions" => ["multi_face_direction_bits" => "63"]], //north, south, west, east, up, down
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
		$obj["additions"]["color"] = $matches[1] ?? throw new Exception("Invalid banner name: " . $group["name"]);
		if (str_ends_with($obj["additions"]["color"], "_wall")) {
			$obj["additions"]["color"] = substr($obj["additions"]["color"], 0, -5);
		}
		$obj["internal_tile"] = ["color"];
	}

	if (str_ends_with($group["name"], "bed")) {
		preg_match("/minecraft:([a-z_]*)_bed/", $group["name"], $matches);
		$obj["additions"]["color"] = $matches[1] ?? throw new Exception("Invalid bed name: " . $group["name"]);
		$obj["internal_tile"] = ["color"];
	}

	if (isset($obj["name"]) && $obj["name"] === "minecraft:skull") {
		$obj["additions"]["type"] = [
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
		][$group["name"]] ?? throw new Exception("Unknown skull type: " . $group["name"]);
		$obj["additions"]["attachment"] = str_contains($group["name"], "wall") ? "wall" : "floor";
		$obj["internal_tile"] = ["type", "attachment"];
		if (!str_contains($group["name"], "wall")) {
			$obj["renames"]["rotation"] = "rot";
			$obj["internal_tile"][] = "rot";
			$obj["additions"]["facing_direction"] = "1";
			unset($obj["remaps"]["facing_direction"]);
			if ($obj["remaps"] !== []) {
				throw new Exception("Unexpected remaps: " . json_encode($obj["remaps"]));
			}
			unset($obj["remaps"]);
		}
	}
	if (isset($obj["name"]) && $obj["name"] === "minecraft:flower_pot") {
		$obj["additions"]["type"] = [
			"minecraft:flower_pot" => "none",
			"minecraft:potted_acacia_sapling" => "minecraft:acacia_sapling",
			"minecraft:potted_allium" => "minecraft:allium",
			"minecraft:potted_azalea_bush" => "minecraft:azalea",
			"minecraft:potted_azure_bluet" => "minecraft:azure_bluet",
			"minecraft:potted_bamboo" => "minecraft:bamboo",
			"minecraft:potted_birch_sapling" => "minecraft:birch_sapling",
			"minecraft:potted_blue_orchid" => "minecraft:blue_orchid",
			"minecraft:potted_brown_mushroom" => "minecraft:brown_mushroom",
			"minecraft:potted_cactus" => "minecraft:cactus",
			"minecraft:potted_cornflower" => "minecraft:cornflower",
			"minecraft:potted_crimson_fungus" => "minecraft:crimson_fungus",
			"minecraft:potted_crimson_roots" => "minecraft:crimson_roots",
			"minecraft:potted_dandelion" => "minecraft:dandelion",
			"minecraft:potted_dark_oak_sapling" => "minecraft:dark_oak_sapling",
			"minecraft:potted_dead_bush" => "minecraft:dead_bush",
			"minecraft:potted_fern" => "minecraft:fern",
			"minecraft:potted_flowering_azalea_bush" => "minecraft:flowering_azalea",
			"minecraft:potted_jungle_sapling" => "minecraft:jungle_sapling",
			"minecraft:potted_lily_of_the_valley" => "minecraft:lily_of_the_valley",
			"minecraft:potted_mangrove_propagule" => "minecraft:mangrove_propagule",
			"minecraft:potted_oak_sapling" => "minecraft:oak_sapling",
			"minecraft:potted_orange_tulip" => "minecraft:orange_tulip",
			"minecraft:potted_oxeye_daisy" => "minecraft:oxeye_daisy",
			"minecraft:potted_pink_tulip" => "minecraft:pink_tulip",
			"minecraft:potted_poppy" => "minecraft:poppy",
			"minecraft:potted_red_mushroom" => "minecraft:red_mushroom",
			"minecraft:potted_red_tulip" => "minecraft:red_tulip",
			"minecraft:potted_spruce_sapling" => "minecraft:spruce_sapling",
			"minecraft:potted_warped_fungus" => "minecraft:warped_fungus",
			"minecraft:potted_warped_roots" => "minecraft:warped_roots",
			"minecraft:potted_white_tulip" => "minecraft:white_tulip",
			"minecraft:potted_wither_rose" => "minecraft:wither_rose"
		][$group["name"]] ?? throw new Exception("Unknown flower pot type: " . $group["name"]);
		$obj["internal_tile"] = ["type"];
	}

	if (!isset($obj["name"]) && !isset($obj["identifier"])) {
		$failed = true;
	}
	if ($values !== []) {
		$failed = true;
	}
	if ($bedrockValues !== []) {
		$failed = true;
	}
	if ($failed) {
		$obj["all"] = $group["states"];
		$obj["missing_java"] = $values;
		$obj["missing_bedrock"] = $bedrockValues;
	}
	$failed ? $failedJTB[$group["name"]] = $obj : $jtb[$group["name"]] = $obj;
}

$oder = ["name", "additions", "removals", "renames", "remaps", "identifier", "mapping", "values", "defaults", "internal_tile"];

foreach ($jtb as $name => $block) {
	if (isset($block["name"]) && $block["name"] === "minecraft:flower_pot" && $block["additions"]["type"] !== "none") {
		$block["additions"]["type"] = toBedrock($block["additions"]["type"], $jtb);
	}
	if ($block["values"] === []) unset($block["values"]);
	if ($block["defaults"] === []) unset($block["defaults"]);
	foreach ($block["renames"] ?? [] as $key => $value) {
		if ($key === $value) unset($block["renames"][$key]);
	}
	if (($block["renames"] ?? []) === []) unset($block["renames"]);

	$ordered = [];
	foreach ($oder as $key) {
		if (isset($block[$key])) {
			$ordered[$key] = $block[$key];
			unset($block[$key]);
		}
	}
	foreach ($block as $key => $value) {
		throw new Exception("Unknown key: " . $key);
	}
	$jtb[$name] = $ordered;
}

if ($failedJTB !== []) echo "\e[31mFailed to convert " . count($failedJTB) . " blocks\e[39m" . PHP_EOL;
echo "Converted " . count($jtb) . " blocks" . PHP_EOL;
file_put_contents("debug/java-to-bedrock-fail.json", json_encode($failedJTB, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

$write = $jtb;
foreach ($write as $name => $block) {
	unset($write[$name]["combined_defaults"]);
}
file_put_contents("../java-to-bedrock.json", json_encode($write, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

echo "Testing mappings..." . PHP_EOL;
$succeeded = 0;
$ignored = 0;
$failedTests = [];
foreach ($javaToBedrock as $java => $bedrock) {
	$pre = $java;
	$java = toBedrock($java, $jtb);
	if ($java === null) {
		$ignored++;
		continue;
	}
	$bedrock = sortState($bedrock);
	if ($java === $bedrock) {
		$succeeded++;
	} else {
		$failedTests[] = ["pre" => $pre, "java" => $java, "bedrock" => $bedrock];
	}
}
echo "Successfully tested $succeeded mappings, " . (count($failedTests)) . " failed, $ignored ignored" . PHP_EOL;
file_put_contents("debug/java-to-bedrock-tests.json", json_encode($failedTests, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));


$bedrockDefaults = [];
foreach ($jtb as $name => $block) {
	$def = $block["defaults"] ?? [];
	foreach ($block["removals"] ?? [] as $key) {
		unset($def[$key]);
	}
	foreach ($block["renames"] ?? [] as $key => $value) {
		if (isset($def[$key])) {
			if (isset($def[$value])) {
				throw new Exception("Duplicate default: " . $value);
			}
			$def[$value] = $def[$key];
			unset($def[$key]);
		} else {
			throw new Exception("Unknown default: " . $key);
		}
	}
	foreach ($block["additions"] ?? [] as $key => $value) {
		if (isset($def[$key])) {
			throw new Exception("Duplicate default: " . $key);
		}
		$def[$key] = $value;
	}
	foreach ($block["remaps"] ?? [] as $key => $value) {
		if (isset($def[$key])) {
			$def[$key] = $value[$def[$key]];
		} else {
			throw new Exception("Unknown default: " . $key);
		}
	}

	$all = [];
	if (isset($block["identifier"])) {
		$find = static function ($data, $keys, $add) use (&$all, &$find, $block) {
			if ($keys === []) {
				if ($data === null) {
					$data = $block["mapping"]["def"];
				}
				$all[] = $data;
				return;
			}
			$key = array_shift($keys);
			foreach ($block["values"][$key] as $value) {
				$a = [];
				foreach ($add as $k => $v) {
					$a[$k] = $v;
				}
				$a[$key] = $value;
				$find($data === null ? null : $data[$value] ?? $data["def"] ?? null, $keys, $a);
			}
		};
		$find($block["mapping"], $block["identifier"], []);
	} else {
		$all = [[]];
	}

	foreach ($all as $a) {
		$d = [];
		foreach ($def as $key => $value) {
			$d[$key] = $value;
		}
		foreach ($a["additions"] ?? [] as $key => $value) {
			if (isset($d[$key])) {
				throw new Exception("Duplicate default: " . $key);
			}
			$d[$key] = $value;
		}
		foreach ($a["remaps"] ?? [] as $key => $value) {
			if (isset($d[$key])) {
				$d[$key] = $value[$d[$key]];
			} else {
				throw new Exception("Unknown default: " . $key);
			}
		}
		foreach ($a["removals"] ?? [] as $key) {
			unset($d[$key]);
		}
		foreach ($a["renames"] ?? [] as $key => $value) {
			if (isset($d[$key])) {
				if (isset($d[$value])) {
					throw new Exception("Duplicate default: " . $value);
				}
				$d[$value] = $d[$key];
				unset($d[$key]);
			} else {
				throw new Exception("Unknown default: " . $key);
			}
		}
		foreach ($d as $key => $value) {
			if (isset($bedrockDefaults[$a["name"] ?? $block["name"] ?? $name][$key])) {
				if ($bedrockDefaults[$a["name"] ?? $block["name"] ?? $name][$key] !== $value) {
					$bedrockDefaults[$a["name"] ?? $block["name"] ?? $name][$key] .= " | " . $value;
					continue;
				}
			}
			$bedrockDefaults[$a["name"] ?? $block["name"] ?? $name][$key] = $value;
		}
	}
}
foreach ($bedrockDefaults as $name => $block) {
	foreach ($block as $key => $value) {
		if (strpos($value, " | ") !== false) {
			$possible = explode(" | ", $value);
			if (isset($customData["beDefaults"][$key]) && in_array($customData["beDefaults"][$key], $possible)) {
				$bedrockDefaults[$name][$key] = $customData["beDefaults"][$key];
			} else if (isset($customData["beDefaults"][$name][$key]) && in_array($customData["beDefaults"][$name][$key], $possible)) {
				$bedrockDefaults[$name][$key] = $customData["beDefaults"][$name][$key];
			} else {
				echo "\e[33mAmbiguous default for $name $key: $value\e[39m" . PHP_EOL;
				$bedrockDefaults[$name][$key] = $possible[0];
			}
		}
	}
}
file_put_contents("../bedrock-defaults.json", json_encode($bedrockDefaults, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));


function toBedrock(string $java, $jtb): string|null
{
	preg_match("/^([a-z\d:_]+)(?:\[(.+)])?$/", $java, $matches);
	if (count($matches) === 0) {
		throw new RuntimeException("Invalid java block: $java");
	}
	$javaName = $matches[1];
	$data = $jtb[$javaName] ?? null;
	if ($data === null) {
		echo "\e[33mUnknown java block: $java\e[39m" . PHP_EOL;
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
	return processState($data, $javaName, $states);
}

/**
 * @param mixed $data
 * @param string $javaName
 * @param array $states
 * @return string
 */
function processState(mixed $data, string $javaName, array $states): string
{
	if (isset($data["identifier"])) {
		$keys = [];
		foreach ($data["identifier"] as $key) {
			$keys[] = $states[$key];
		}
		$r = function ($data, array $keys) use (&$r) {
			if ($keys === []) {
				return $data;
			}
			if (!is_array($data)) {
				throw new RuntimeException("Invalid identifier");
			}
			$key = array_shift($keys);
			if (!isset($data[$key]) && !isset($data["def"])) {
				return null;
			}
			return $r($data[$key] ?? $data["def"], $keys);
		};
		$d = $r($data["mapping"], $keys) ?? $data["mapping"]["def"];
	} else {
		$d = [];
	}
	processStates($states, $data);
	processStates($states, $d);
	foreach ($data["internal_tile"] ?? [] as $key) {
		unset($states[$key]);
	}
	return sortState(($d["name"] ?? $data["name"] ?? $javaName) . (count($states) === 0 ? "" : "[" . implode(",", array_map(static function (string $key, string $value) {
				return "$key=$value";
			}, array_keys($states), $states)) . "]"));
}

function processStates(&$states, $data)
{
	foreach ($data["removals"] ?? [] as $state) {
		unset($states[$state]);
	}
	/**
	 * @var string $old
	 * @var string $new
	 */
	foreach ($data["renames"] ?? [] as $old => $new) {
		if (isset($states[$old])) {
			$value = $states[$old];
			unset($states[$old]);
			$states[$new] = $value;
		}
	}
	/**
	 * @var string $state
	 * @var string[] $values
	 */
	foreach ($data["remaps"] ?? [] as $state => $values) {
		if (isset($states[$state])) {
			$states[$state] = $values[$states[$state]] ?? $states[$state];
		}
	}
	foreach ($data["additions"] ?? [] as $state => $value) {
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

//Prepare custom data
unset($jtb["minecraft:mushroom_stem"]["mapping"]["false"]["false"]["false"]); //0 side mushroom block should be converted to mushroom block, not stem

$jtb["minecraft:powder_snow_cauldron"]["remaps"] = $jtb["minecraft:cauldron"]["remaps"]; //filled don't have the mapping for 0
$jtb["minecraft:powder_snow_cauldron"]["defaults"] = $jtb["minecraft:water_cauldron"]["defaults"] = $jtb["minecraft:cauldron"]["defaults"]; //Fix conflicts

$btj = [];
foreach ($jtb as $java => $bedrockData) {
	revertJavaToBedrock($java, $bedrockData, $btj, $customData);
}

//Add extra mappings for red mushroom blocks (same texture, might be used in maps)
$stem = $jtb["minecraft:mushroom_stem"];
$stem["name"] = "minecraft:red_mushroom_block";
revertJavaToBedrock("minecraft:mushroom_stem", $stem, $btj, $customData);

function revertJavaToBedrock($java, $bedrockData, &$btj)
{
	global $customData;
	if (isset($bedrockData["identifier"])) {
		$all = [];
		$find = function ($data, $keys, $add) use (&$all, &$find, $bedrockData) {
			if ($keys === []) {
				if ($data === null) {
					$data = $bedrockData["mapping"]["def"];
				}
				$data["int_add"] = $add;
				$all[] = $data;
				return;
			}
			$key = array_shift($keys);
			foreach ($bedrockData["values"][$key] as $value) {
				$a = [];
				foreach ($add as $k => $v) {
					$a[$k] = $v;
				}
				$a[$key] = $value;
				$find($data === null ? null : $data[$value] ?? $data["def"] ?? null, $keys, $a);
			}
		};
		$find($bedrockData["mapping"], $bedrockData["identifier"], []);

		foreach ($all as $d) {
			$data = [];
			if (isset($bedrockData["name"])) {
				$data["name"] = $java;
			} elseif (isset($d["name"])) {
				$data["name"] = $java;
			}
			if (isset($bedrockData["defaults"])) {
				$data["defaults"] = $bedrockData["defaults"];
			}

			flipStateTranslation($data, $bedrockData);
			flipStateTranslation($data, $d);
			foreach ($d["int_add"] as $key => $value) {
				if (!isset($data["additions"][$key])) {
					continue;
				}
				$data["additions"][$key] = $value;
			}

			if (isset($btj[$d["name"] ?? $bedrockData["name"] ?? $java])) {
				$btj[$d["name"] ?? $bedrockData["name"] ?? $java] = merge($btj[$d["name"] ?? $bedrockData["name"] ?? $java], $data, $d["name"] ?? $bedrockData["name"] ?? $java);
				continue;
			}
			$btj[$d["name"] ?? $bedrockData["name"] ?? $java] = $data;
		}
	} else {
		$data = [];
		if (isset($bedrockData["name"])) {
			$data["name"] = $java;
		}
		if (isset($bedrockData["defaults"])) {
			$data["defaults"] = $bedrockData["defaults"];
		}

		flipStateTranslation($data, $bedrockData);

		if (isset($btj[$bedrockData["name"] ?? $java])) {
			$btj[$bedrockData["name"] ?? $java] = merge($btj[$bedrockData["name"] ?? $java], $data, $bedrockData["name"] ?? $java);
			return;
		}
		$btj[$bedrockData["name"] ?? $java] = $data;
	}
}

function merge(mixed $a, mixed $b, string $bedrock): mixed
{
	global $customData;

	$write = function (&$current, $path, $value) use ($bedrock, &$write, $customData) {
		if (count($path) === 1) {
			if (isset($current[$path[0] ?? "def"])) {
				if (!isset($customData["overrides"][$bedrock]) || !in_array($path[0], $customData["overrides"][$bedrock])) {
					echo "Implicit override of $path[0] in $bedrock\n";
					return;
				}

				if (!in_array($bedrock, $customData["overrides"]["__allow"])) {
					return;
				}
			}
			$current[$path[0] ?? "def"] = $value;
			return;
		}
		if (!isset($current[$path[0]])) {
			$current[$path[0]] = [];
		}
		$write($current[$path[0]], array_slice($path, 1), $value);
	};
	if (isset($a["identifier"])) {
		foreach ($b["additions"] ?? [] as $key => $value) {
			if (isset($a["additions"][$key]) && $a["additions"][$key] === $value) {
				unset($b["additions"][$key]);
			}
		}
		foreach ($b["renames"] ?? [] as $key => $value) {
			if (isset($a["renames"][$key]) && $a["renames"][$key] === $value) {
				unset($b["renames"][$key]);
			}
		}
		foreach ($b["remaps"] ?? [] as $key => $value) {
			if (isset($a["remaps"][$key]) && $a["remaps"][$key] === $value) {
				unset($b["remaps"][$key]);
			}
		}
		$keys = [];
		foreach ($a["identifier"] as $id) {
			$keys[] = $b["removals"][$id];
		}
		foreach ($a["removals"] ?? [] as $key => $value) {
			unset($b["removals"][$key]);
		}
		$obj = [];
		if ((!isset($res["name"]) && isset($b["name"]) && $b["name"] !== $bedrock) || (isset($res["name"]) && isset($b["name"]) && $b["name"] !== $res["name"])) {
			$obj["name"] = $b["name"];
		}
		if (($b["additions"] ?? []) !== []) {
			$obj["additions"] = $b["additions"];
		}
		if (($b["renames"] ?? []) !== []) {
			$obj["renames"] = $b["renames"];
		}
		if (($b["remaps"] ?? []) !== []) {
			$obj["remaps"] = $b["remaps"];
		}
		if (($b["removals"] ?? []) !== []) {
			$obj["removals"] = $b["removals"];
		}
		$write($a["mapping"], $keys, $obj);
		foreach ($b["defaults"] ?? [] as $key => $value) {
			if (!isset($a["defaults"][$key])) {
				$a["defaults"][$key] = $value;
			} else if ($a["defaults"][$key] !== $value) {
				echo "Failed to merge defaults: " . json_encode($a) . PHP_EOL . str_repeat(" ", 20) . "and " . json_encode($b) . PHP_EOL;
				return $a;
			}
		}
		return $a;
	}

	if (in_array($b["name"] ?? $bedrock, $customData["btj_ignore"][$bedrock] ?? [])) {
		return $a;
	}
	if (in_array($a["name"] ?? $bedrock, $customData["btj_ignore"][$bedrock] ?? [])) {
		return $b;
	}

	$res = [];

	$nameA = $a["name"] ?? $bedrock;
	$nameB = $b["name"] ?? $bedrock;
	$res["additions"] = [];
	foreach ($a["additions"] ?? [] as $key => $value) {
		if ($b["additions"][$key] === $value) {
			$res["additions"][$key] = $value;
			unset($a["additions"][$key], $b["additions"][$key]);
		}
	}
	if (count($a["additions"] ?? []) === 0) unset($a["additions"]);
	if (count($b["additions"] ?? []) === 0) unset($b["additions"]);

	$res["renames"] = [];
	foreach ($a["renames"] ?? [] as $key => $value) {
		if (isset($b["renames"][$key]) && $b["renames"][$key] === $value) {
			$res["renames"][$key] = $value;
			unset($a["renames"][$key], $b["renames"][$key]);
		}
	}
	if (count($a["renames"] ?? []) === 0) unset($a["renames"]);
	if (count($b["renames"] ?? []) === 0) unset($b["renames"]);

	$res["remaps"] = [];
	foreach ($a["remaps"] ?? [] as $key => $value) {
		if (isset($b["remaps"][$key]) && $b["remaps"][$key] === $value) {
			$res["remaps"][$key] = $value;
			unset($a["remaps"][$key], $b["remaps"][$key]);
		}
	}
	if (count($a["remaps"] ?? []) === 0) unset($a["remaps"]);
	if (count($b["remaps"] ?? []) === 0) unset($b["remaps"]);

	$res["removals"] = [];
	$potValues = [];
	foreach ($a["removals"] ?? [] as $key => $value) {
		$potValues[$key] = [$value, null];
	}
	foreach ($b["removals"] ?? [] as $key => $value) {
		if (isset($potValues[$key])) {
			$potValues[$key][1] = $value;
		} else {
			$potValues[$key] = [null, $value];
		}
	}
	foreach ($a["removals"] ?? [] as $key => $value) {
		if (isset($b["removals"][$key])) {
			$res["removals"][$key] = $value;
			unset($a["removals"][$key], $b["removals"][$key]);
		}
	}
	if (count($a["removals"] ?? []) === 0) unset($a["removals"]);
	if (count($b["removals"] ?? []) === 0) unset($b["removals"]);

	if (isset($a["name"]) && isset($b["name"]) && $a["name"] === $b["name"]) {
		$res["name"] = $a["name"];
		unset($a["name"], $b["name"]);
	}

	foreach ($b["defaults"] ?? [] as $key => $value) {
		$res["defaults"][$key] = $value;
	}
	foreach ($b["defaults"] ?? [] as $key => $value) {
		if (!isset($a["defaults"][$key])) {
			$res["defaults"][$key] = $value;
		} else if ($a["defaults"][$key] !== $value) {
			echo "Failed to merge defaults: " . json_encode($a) . PHP_EOL . str_repeat(" ", 20) . "and " . json_encode($b) . PHP_EOL;
			return $a;
		}
	}
	unset($a["defaults"], $b["defaults"]);

	if ($a === [] && $b === []) {
		return $res;
	}

	if (isset($customData["btj_multi"][$bedrock])) {
		if (is_array($customData["btj_multi"][$bedrock])) {
			$res["identifier"] = $customData["btj_multi"][$bedrock];
		} else {
			$res["identifier"] = [$customData["btj_multi"][$bedrock]];
		}
		foreach ($res["identifier"] as $key) {
			unset($a["removals"][$key], $b["removals"][$key]);
		}
	} elseif (count($res["removals"] ?? []) === 1 && count($a["removals"] ?? []) === 0 && count($b["removals"] ?? []) === 0) {
		$res["identifier"] = [array_key_first($res["removals"])];
	} else {
		echo json_encode($potValues) . PHP_EOL;
		throw new Exception("Failed to find multi name for $bedrock ($nameA, $nameB)" . json_encode($res) . json_encode($a) . json_encode($b));
	}
	$mapping = [];
	$objA = [];
	$objB = [];
	if (!isset($res["name"])) {
		$objA["name"] = $nameA;
		$objB["name"] = $nameB;
	}
	if (($a["additions"] ?? []) !== []) {
		$objA["additions"] = $a["additions"];
	}
	if (($b["additions"] ?? []) !== []) {
		$objB["additions"] = $b["additions"];
	}
	if (($a["removals"] ?? []) !== []) {
		$objA["removals"] = $a["removals"];
	}
	if (($b["removals"] ?? []) !== []) {
		$objB["removals"] = $b["removals"];
	}
	if (($a["renames"] ?? []) !== []) {
		$objA["renames"] = $a["renames"];
	}
	if (($b["renames"] ?? []) !== []) {
		$objB["renames"] = $b["renames"];
	}
	if (($a["remaps"] ?? []) !== []) {
		$objA["remaps"] = $a["remaps"];
	}
	if (($b["remaps"] ?? []) !== []) {
		$objB["remaps"] = $b["remaps"];
	}
	$write($mapping, array_map(fn($a) => $potValues[$a][0], $res["identifier"]), $objA);
	$write($mapping, array_map(fn($a) => $potValues[$a][1], $res["identifier"]), $objB);
	$res["mapping"] = $mapping;
	if (isset($a["internal_tile"])) {
		foreach ($a["internal_tile"] as $key) {
			$res["internal_tile"][$key] = $customData["btj_tile_default"][$bedrock][$key];
		}
	}
	return $res;
}

function flipStateTranslation(&$state, $bedrockData): void
{
	if (isset($bedrockData["additions"])) {
		$state["removals"] = array_merge($state["removals"] ?? [], $bedrockData["additions"]);
	}
	if (isset($bedrockData["internal_tile"])) {
		$state["internal_tile"] = array_merge($state["internal_tile"] ?? [], $bedrockData["internal_tile"]);
	}
	if (isset($bedrockData["renames"])) {
		$state["renames"] = array_merge($state["renames"] ?? [], array_flip($bedrockData["renames"]));
	}
	if (isset($bedrockData["remaps"])) {
		$state["remaps"] = $state["remaps"] ?? [];
		foreach ($bedrockData["remaps"] as $key => $value) {
			$state["remaps"][$state["renames"][$key] ?? $key] = array_flip($value);
		}
	}
	if (isset($bedrockData["removals"])) {
		$state["additions"] = $state["additions"] ?? [];
		foreach ($bedrockData["removals"] as $key) {
			$state["additions"][$key] = $bedrockData["defaults"][$key];
		}
	}
}

$btj["minecraft:invisible_bedrock"] = ["name" => "minecraft:barrier"];

function switchRemovals($a): mixed
{
	if (isset($a["removals"])) {
		$a["removals"] = array_keys($a["removals"]);
	}
	foreach ($a as $key => $c) {
		if (is_array($c)) {
			$a[$key] = switchRemovals($c);
		}
	}
	return $a;
}

foreach ($btj as $name => $block) {
	$block = switchRemovals($block);
	if (($block["defaults"] ?? []) === []) unset($block["defaults"]);
	foreach ($block["renames"] ?? [] as $key => $value) {
		if ($key === $value) unset($block["renames"][$key]);
	}
	if (($block["renames"] ?? []) === []) unset($block["renames"]);

	$ordered = [];
	foreach ($oder as $key) {
		if (isset($block[$key])) {
			$ordered[$key] = $block[$key];
			unset($block[$key]);
		}
	}
	foreach ($block as $key => $value) {
		throw new Exception("Unknown key: " . $key);
	}
	$btj[$name] = $ordered;
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

foreach (scandir("item-patches") as $patch) {
	if ($patch === "." || $patch === "..") {
		continue;
	}
	$patchData = json_decode(file_get_contents("item-patches/$patch"), true, 512, JSON_THROW_ON_ERROR);
	foreach ($patchData as $java => $bedrock) {
		$pre = $items;
		$items[$java] = $bedrock;
		if ($pre === $items) {
			echo "\e[31mFailed to apply item patch $patch ($java -> " . json_encode($bedrock) . ")\e[39m" . PHP_EOL;
		}
	}
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