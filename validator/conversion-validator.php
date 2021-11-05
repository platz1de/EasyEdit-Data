<?php

$data = json_decode(file_get_contents("../block-conversion.json"), true, 512, JSON_THROW_ON_ERROR);
$replaceData = $data["replace"];
$complexData = $data["complex"];
$toBedrock = $data["to-bedrock"];
$toJava = $data["to-java"];

$construct = [];
foreach ($replaceData as $javaId => $bedrockId) {
	for ($i = 0; $i <= 15; $i++) {
		$construct["$javaId:$i"] = "$bedrockId:$i";
	}
}
foreach ($complexData as $javaId => $metaData) {
	foreach ($metaData as $javaMeta => $bedrockData) {
		if (isset($construct["$javaId:$javaMeta"])) {
			echo "Warning: Duplicate translation for $javaId:$javaMeta found" . PHP_EOL;
		}
		if (in_array("$bedrockData[0]:$bedrockData[1]", $construct, true)) {
			echo "Warning: Duplicate reversion for $bedrockData[0]:$bedrockData[1] found" . PHP_EOL;
		}
		$construct["$javaId:$javaMeta"] = "$bedrockData[0]:$bedrockData[1]";
	}
}

$toBedrockData = [];
$toJavaData = [];
foreach ($toBedrock as $javaId => $bedrockId) {
	for ($i = 0; $i <= 15; $i++) {
		$toBedrockData["$javaId:$i"] = "$bedrockId:$i";
	}
}
foreach ($toJava as $bedrockId => $javaId) {
	for ($i = 0; $i <= 15; $i++) {
		$toJavaData["$bedrockId:$i"] = "$javaId:$i";
	}
}

$invalid = array_diff(array_keys($construct), $construct);
foreach ($invalid as $id) {
	if (isset($construct[$id])) {
		if (!isset($toJavaData[$id])) {
			echo "Warning: No other use of java $id found" . PHP_EOL;
		}
	} else if(!isset($toBedrockData[$id])) {
		echo "Warning: No other use of bedrock $id found" . PHP_EOL;
	}
}