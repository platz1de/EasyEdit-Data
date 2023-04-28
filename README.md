Data files used by [EasyEdit](https://github.com/platz1de/EasyEdit)

## Mappings to convert between minecraft bedrock and java

Data sources:

- [Minecraft Wiki](https://minecraft.fandom.com/)
- [PrismarineJS](https://github.com/PrismarineJS/minecraft-data/)
- [GeyserMC](https://github.com/GeyserMC/mappings/)

| Mapping                    | Usage                                   | Format                                |
|----------------------------|-----------------------------------------|---------------------------------------|
| legacy-conversion-map.json | Legacy java numeric ID to bedrock state | javaID -> bedrockState                |
| java-to-bedrock.json       | Current java to bedrock states          | [see below](#State-conversion-format) |
| bedrock-to-java.json       | Bedrock state to java state             | [see below](#State-conversion-format) |
| rotation-data.json         | Clockwise bedrock state rotation        | bedrockState -> rotatedState          |
| flip-data.json             | Flip bedrock state on axis              | axi: bedrockState -> flippedState     |

## State conversion format

Every entry deals with a single java state. The key is the java state, the value is a translation mapping:

| Key           | Value                                 | Description                       | Default (if not set) |
|---------------|---------------------------------------|-----------------------------------|----------------------|
| name          | string                                | The bedrock state name            | java state name      |
| additions     | object: bedrockState -> bedrockValue  | Adds additional bedrock states    | no additions         |
| removals      | array of javaStates                   | Removes java states               | no removals          |
| renames       | object: javaState -> bedrockState     | Renames the java state            | no renames           |
| remaps        | object: bedrockState -> value mapping | Remaps state values               | no remaps            |
| values        | object: stateName -> array of values  | List of all possible state values | no state data        |
| defaults      | object: stateName -> value            | The default java states           | empty state data     |
| internal_tile | object                                | data saved in block entity        | no extra data        |

If no 1:1 mapping is possible, the java state will be split as follows:

| Key        | Value               | Description                                               |
|------------|---------------------|-----------------------------------------------------------|
| identifier | array of javaStates | states which decide the format of the final bedrock state |
| mapping    | object              | mapping of the java states to the bedrock state           |

The mapping always has the same depth as the count of identifiers, each identifiers value is used as key for the next mapping. The resulting value follows the same rules as the normal mapping (excluding values and defaults).
Multiple mappings with the same values may be bundled with the key "def", if no other mapping matches, the default mapping will be used.