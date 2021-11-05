<?php

$data = json_decode(file_get_contents("block-conversion.json"), true, 512, JSON_THROW_ON_ERROR);
$replaceData = $data["replace"];
$complexData = $data["complex"];
$toBedrockData = $data["to-bedrock"];
$toJavaData = $data["to-java"];

$toBedrock = [];
$toJava = [];
$javaKnownIds = [];
$bedrockKnownIds = [];
foreach ($replaceData as $javaId => $bedrockId) {
	for ($i = 0; $i <= 15; $i++) {
		$toBedrock["$javaId:$i"] = "$bedrockId:$i";
		$toJava["$bedrockId:$i"] = "$javaId:$i";
		$javaKnownIds[] = $javaId;
		$bedrockKnownIds[] = $bedrockId;
	}
}
foreach ($complexData as $javaId => $metaData) {
	foreach ($metaData as $javaMeta => $bedrockData) {
		if (isset($toBedrock["$javaId:$javaMeta"])) {
			echo "Warning: Duplicate bedrock translation for $javaId:$javaMeta found" . PHP_EOL;
		}
		if (isset($toJava["$bedrockData[0]:$bedrockData[1]"])) {
			echo "Warning: Duplicate java translation for $bedrockData[0]:$bedrockData[1] found" . PHP_EOL;
		}
		$toBedrock["$javaId:$javaMeta"] = "$bedrockData[0]:$bedrockData[1]";
		$toJava["$bedrockData[0]:$bedrockData[1]"] = "$javaId:$javaMeta";
		$javaKnownIds[] = $javaId;
		$bedrockKnownIds[] = $bedrockData[0];
	}
}

foreach ($toBedrockData as $javaId => $bedrockId) {
	for ($i = 0; $i <= 15; $i++) {
		if (isset($toBedrock["$javaId:$i"])) {
			echo "Warning: Duplicate bedrock translation for $javaId:$i found" . PHP_EOL;
		}
		$toBedrock["$javaId:$i"] = "$bedrockId:$i";
		$javaKnownIds[] = $javaId;
	}
}
foreach ($toJavaData as $bedrockId => $javaId) {
	for ($i = 0; $i <= 15; $i++) {
		if (isset($toJava["$bedrockId:$i"])) {
			echo "Warning: Duplicate java translation for $bedrockId:$i found" . PHP_EOL;
		}
		$toJava["$bedrockId:$i"] = "$javaId:$i";
		$bedrockKnownIds[] = $bedrockId;
	}
}

file_put_contents("bedrock-conversion-map.json", json_encode($toBedrock, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
file_put_contents("java-conversion-map.json", json_encode($toJava, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

$invalid = array_diff(array_unique($bedrockKnownIds), array_unique($javaKnownIds));
foreach ($invalid as $id) {
	if (in_array($id, $javaKnownIds, true)) {
		if (isset($toBedrock["$id:0"])) {
			echo "Warning: No use for bedrock $id found" . PHP_EOL;
		}
	} elseif ($id < 256 && isset($toJava["$id:0"])) { //These can just be filtered out
		echo "Warning: No use for java $id found" . PHP_EOL;
	}
}