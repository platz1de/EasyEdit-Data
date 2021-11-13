<?php

/**
 * Welcome to the properly worst script in history
 */

use pocketmine\network\mcpe\convert\GlobalItemTypeDictionary;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;

require_once("phar://PocketMine-MP.phar/vendor/autoload.php");

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

	$bedrockData[$fullName . "]"] = $ids[$id] . ":" . $meta;
}

$bedrockMapping = [];
$javaMapping = [];
$javaToBedrock = json_decode(getData("https://raw.githubusercontent.com/PrismarineJS/minecraft-data/master/data/bedrock/1.17.10/blocksJ2B.json"), true, 512, JSON_THROW_ON_ERROR);
$bedrockToJava = json_decode(getData("https://raw.githubusercontent.com/PrismarineJS/minecraft-data/master/data/bedrock/1.17.10/blocksB2J.json"), true, 512, JSON_THROW_ON_ERROR);
$missingBedrock = [];
$missingJava = [];

$rewrites = [
	"true" => 1, "false" => 0, //Bedrock uses integers for booleans
	"wall_post_bit=1" => "wall_post_bit=0", //walls are handled really weirdly
	"wall_connection_type_east=short" => "wall_connection_type_east=none", "wall_connection_type_east=tall" => "wall_connection_type_east=none",
	"wall_connection_type_north=short" => "wall_connection_type_north=none", "wall_connection_type_north=tall" => "wall_connection_type_north=none",
	"wall_connection_type_south=short" => "wall_connection_type_south=none", "wall_connection_type_south=tall" => "wall_connection_type_south=none",
	"wall_connection_type_west=short" => "wall_connection_type_west=none", "wall_connection_type_west=tall" => "wall_connection_type_west=none"
];

foreach ($javaToBedrock as $java => $bedrock) {
	$bedrock = str_replace(array_keys($rewrites), array_values($rewrites), $bedrock);
	if (isset($bedrockData[$bedrock])) {
		if (str_ends_with($java, "[]")) {
			$bedrockMapping[substr($java, 0, -2)] = $bedrockData[$bedrock];
		}
		$bedrockMapping[$java] = $bedrockData[$bedrock];
	} else {
		$missingJava[$java] = $bedrock;
	}
}
foreach ($bedrockToJava as $bedrock => $java) {
	$bedrock = str_replace(array_keys($rewrites), array_values($rewrites), $bedrock);
	if (isset($bedrockData[$bedrock])) {
		$javaMapping[$bedrockData[$bedrock]] = $java;
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