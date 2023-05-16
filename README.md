Data files used by [EasyEdit](https://github.com/platz1de/EasyEdit)

## Mappings to convert between minecraft bedrock and java

Data sources:

- Manual extractions from [Minecraft Wiki](https://minecraft.fandom.com/)
- [PrismarineJS](https://github.com/PrismarineJS/minecraft-data/) (Licenced under MIT)
- [GeyserMC](https://github.com/GeyserMC/mappings/) (Licenced under MIT)
- [BedrockData](https://github.com/pmmp/BedrockData/) (Licenced under CC0)

## Mappings

| Mapping                    | Usage                                           | Format                                |
|----------------------------|-------------------------------------------------|---------------------------------------|
| legacy-conversion-map.json | Legacy java numeric ID to bedrock state         | javaID -> bedrockState                |
| java-to-bedrock.json       | Current java to bedrock states                  | [see below](#State-conversion-format) |
| bedrock-to-java.json       | Bedrock state to java state                     | [see below](#State-conversion-format) |
| manipulation-data.json     | Data for block manipulation                     | [see below](#Manipulation-format)     |
| item-conversion-map.json   | Basic item conversion (e.g. for chest contents) | javaID -> bedrockID + damage value    |
| bedrock-defaults.json      | Default values for bedrock states               | bedrockState -> values                |
| block-tags.json            | A list of block tags (already fully flattened)  | tagName -> states                     |

### Versioning

The [data repo file](dataRepo.json) contains a list of urls to the mappings for every block state version. If a version
is not specified, latest should be used.

## State conversion format

Every entry deals with a single java state. The key is the java state, the value is a translation mapping:

| Key        | Value                                 | Description                                              | Default (if not set) |
|------------|---------------------------------------|----------------------------------------------------------|----------------------|
| name       | string                                | The bedrock state name                                   | java state name      |
| additions  | object: bedrockState -> bedrockValue  | Adds additional bedrock states                           | no additions         |
| removals   | array of javaStates                   | Removes java states                                      | no removals          |
| renames    | object: javaState -> bedrockState     | Renames the java state                                   | no renames           |
| remaps     | object: bedrockState -> value mapping | Remaps state values                                      | no remaps            |
| values     | object: stateName -> array of values  | List of all possible state values                        | no state data        |
| defaults   | object: stateName -> value            | The default java states                                  | empty state data     |
| tile_extra | object                                | data saved in block entity ([details](#Tile-extra-data)) | no extra data        |

If no 1:1 mapping is possible, the java state will be split as follows:

| Key        | Value               | Description                                               |
|------------|---------------------|-----------------------------------------------------------|
| identifier | array of javaStates | states which decide the format of the final bedrock state |
| mapping    | object              | mapping of the java states to the bedrock state           |

The mapping always has the same depth as the count of identifiers, each identifiers value is used as key for the next
mapping. The resulting value follows the same rules as the normal mapping (excluding values and defaults).
Multiple mappings with the same values may be bundled with the key "def", if no other mapping matches, the default
mapping will be used.

### Tile extra data

Bedrock saves some extra data in block entities, which is contained in the block state in java. If a state requires this
extra data, it specified in the `tile_extra` key and needs to be removed after conversion from java to bedrock or added
before conversion from bedrock to java (if this information is unknown, specify the value of the `tile_extra` key).

The following tile extra data is supported:

| Block       | Extra data key | Description                                                   |
|-------------|----------------|---------------------------------------------------------------|
| Banners     | color          | The color of the banner                                       |
| Beds        | color          | The color of the bed                                          |
| Mob heads   | type           | The type of the mob head (id name of mob)                     |
| Mob heads   | attachment     | The attachment of the mob head ("wall" / "floor")             |
| Mob heads   | rot            | The rotation of the mob head (0-15), ONLY if on floor         |
| Flower pot  | type           | Contents of flower pot (bedrock block state), "none" if empty |
| Shulker box | facing         | The direction the shulker box is facing                       |

## Manipulation format

The manipulation data can be used to rotate or mirror blocks. For every block, the following data is saved:

| Key    | Description                        |
|--------|------------------------------------|
| rotate | clockwise rotation (around y-axis) |
| flip-x | mirror along x-axis (y-z-plane)    |
| flip-y | mirror along y-axis (x-z-plane)    |
| flip-z | mirror along z-axis (x-y-plane)    |

The values are arrays of block states, witch appropriate mappings of the values from before tha manipulation to the
values after the manipulation. <br>
Note: These manipulations are not always perfect (e.g. flipping along y-axis for doors or rotation of buttons), as not
all blocks have perfect mirrored variants.