<?php

$data = json_decode(file_get_contents("block-conversion.json"), true, 512, JSON_THROW_ON_ERROR);
$replaceData = $data["replace"];
$complexData = $data["complex"];
$toBedrockData = $data["to-bedrock"];
$toJavaData = $data["to-java"];

$toBedrock = [];
$toJava = [];
foreach ($replaceData as $javaId => $bedrockId) {
	for ($i = 0; $i <= 15; $i++) {
		$toBedrock["$javaId:$i"] = "$bedrockId:$i";
		$toJava["$bedrockId:$i"] = "$javaId:$i";
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
	}
}

foreach ($toBedrockData as $javaId => $bedrockId) {
	for ($i = 0; $i <= 15; $i++) {
		$toBedrock["$javaId:$i"] = "$bedrockId:$i";
	}
}
foreach ($toJavaData as $bedrockId => $javaId) {
	for ($i = 0; $i <= 15; $i++) {
		$toJava["$bedrockId:$i"] = "$javaId:$i";
	}
}

file_put_contents("bedrock-conversion-map.json", json_encode($toBedrock, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
file_put_contents("java-conversion-map.json", json_encode($toJava, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

$invalid = array_diff(array_keys($toBedrock), array_keys($toJava));
foreach ($invalid as $id) {
	if (isset($toBedrock[$id])) {
		if (!isset($toJavaData[$id])) {
			echo "Warning: No other use of java $id found" . PHP_EOL;
		}
	} else if (!isset($toBedrockData[$id])) {
		echo "Warning: No other use of bedrock $id found" . PHP_EOL;
	}
}