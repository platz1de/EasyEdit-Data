Data files used by [EasyEdit](https://github.com/platz1de/EasyEdit)

## Mappings to convert between minecraft bedrock and java

Data sources:

- [Minecraft Wiki](https://minecraft.fandom.com/)
    - [Minecraft Java Data Values](https://minecraft.fandom.com/wiki/Java_Edition_data_values/Pre-flattening)
- [BedrockData](https://github.com/pmmp/BedrockData/)
- [PrismarineJS](https://github.com/PrismarineJS/minecraft-data/)

Note: "ID" refers to numeric blockIDs, while "State" refers to the block's stringy state. <br>
Most mappings do NOT contain values where java and bedrock are the same

| Mapping                     | Usage                                 | Format                                |
|-----------------------------|---------------------------------------|---------------------------------------|
| bedrock-conversion-map.json | Legacy java to bedrock numeric ID     | javaID -> bedrockID                   |
| bedrock_palette.json        | Current java to bedrock numeric ID    | javaState -> bedrockID                |
| java_palette.json           | Bedrock numeric ID to java current    | bedrockID -> javaState                |
| rotation-data.json          | Clockwise bedrock numeric ID rotation | bedrockID -> rotatedID                |   
| flip-data.json              | Flip bedrock numeric ID on axis       | axi: bedrockID -> flippedID           |
| tile-data-states.json       | Java block state to tile property     | type: javaState -> property           |
| java-tile-states.json       | Tile property to java block state     | type: rawState: property -> javaState |

#### Tile properties

| key                | usage                        |
|--------------------|------------------------------|
| chest_relation     | direction of connected chest |
| shulker_box_facing | shulker opening direction    |
| skull_type         | type of skull                |
| skull_rotation     | rotation of skull            |