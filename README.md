Data files used by [EasyEdit](https://github.com/platz1de/EasyEdit)

### block-conversion.json

Conversion table to convert numeric java block ids to bedrock ids (for game versions previous to 1.13)

- Blocks listed under "replace" can be replaced 1:1 without changing meta values, these blocks have just a different id
- Blocks listed under "translate" need to only change their meta values
- Blocks listed under "complex" need to change their block id and meta values according to given java meta values

The priority of listed types is in reverse order ("complex" > "translate" > "replace"),
"translate" and "complex" should never contain the same block id and meta values though.

Additionally, blocks listed under "to-bedrock" do not have a corresponding bedrock block and will be replaced,
blocks listed under "to-java" do not have a corresponding java block

Primary data source:

- [Minecraft Wiki](https://minecraft.fandom.com/)
    - [Minecraft Java Data Values](https://minecraft.fandom.com/wiki/Java_Edition_data_values/Pre-flattening)