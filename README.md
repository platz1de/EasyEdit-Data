Data files used by [EasyEdit](https://github.com/platz1de/EasyEdit)

### block-conversion.json

Conversion table to convert numeric java block ids to bedrock ids

- Blocks listed under "replace" can be replaced 1:1 without changing meta values, these blocks have just a different id
- Blocks listed under "translate" need to change their meta values

If listed under both arrays, the block changes its id and meta values

Primary data source:
- [Minecraft Wiki](https://minecraft.fandom.com/)
  - [Minecraft Java Data Values](https://minecraft.fandom.com/wiki/Java_Edition_data_values/Pre-flattening)