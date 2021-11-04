Data files used by [EasyEdit](https://github.com/platz1de/EasyEdit)

### block-conversion.json

Conversion table to convert numeric java block ids to bedrock ids

- Blocks listed under "replace" can be replaced 1:1 without changing meta values, these blocks have just a different id
- Blocks listed under "translate" need to only change their meta values
- Blocks listed under "complex" need to change their block id and meta values according to given java meta values

Primary data source:

- [Minecraft Wiki](https://minecraft.fandom.com/)
    - [Minecraft Java Data Values](https://minecraft.fandom.com/wiki/Java_Edition_data_values/Pre-flattening)