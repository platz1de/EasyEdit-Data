Data files used by [EasyEdit](https://github.com/platz1de/EasyEdit)

### block-conversion.json

Conversion table to convert numeric java block ids to bedrock ids (for game versions previous to 1.13)

- Blocks listed under "replace" can be replaced 1:1 without changing meta values, these blocks have just a different id
- Blocks listed under "both" need to change their block id and meta values according to given java values.
Meta values can be replaced with "*", meaning all possible meta values (0-15)

Additionally, blocks listed under "to-bedrock" do not have a corresponding bedrock block and will be replaced,
blocks listed under "to-java" do not have a corresponding java block

Primary data source:

- [Minecraft Wiki](https://minecraft.fandom.com/)
    - [Minecraft Java Data Values](https://minecraft.fandom.com/wiki/Java_Edition_data_values/Pre-flattening)