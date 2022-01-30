Data files used by [EasyEdit](https://github.com/platz1de/EasyEdit)

## Conversion maps

Conversion tables to convert numeric 1.12 java block ids to current bedrock ids (and in reverse)

Primary data source:

- [WorldEdit](https://github.com/EngineHub/WorldEdit/tree/master/worldedit-core/src/main/resources/com/sk89q/worldedit/world/registry)
- [Minecraft Wiki](https://minecraft.fandom.com/)
    - [Minecraft Java Data Values](https://minecraft.fandom.com/wiki/Java_Edition_data_values/Pre-flattening)

Special blocks:

- Item frames (bedrock 199) are represented as block entities in java

#### bedrock-conversion-map.json

Preprocessed java block to bedrock block conversion map

## Palette Maps

Conversion tables to convert java block states to legacy bedrock ids (and in reverse)

Primary data source:

- [BedrockData](https://github.com/pmmp/BedrockData/)
- [PrismarineJS](https://github.com/PrismarineJS/minecraft-data/)

#### bedrock-palette.json

Preprocessed java block state to bedrock block id map

#### java_palette.json

Preprocessed bedrock block id to java block state map

#### rotation-data.json

Table to rotate bedrock block ids clockwise by 90 degrees on y-axis

#### flip-data.json

Table to flip bedrock block ids on a given axis

### tile-data-states.json

Map of special block states to tile data values, magic keys:

- chest_relation: direction of connected chest