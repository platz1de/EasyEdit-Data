Data files used by [EasyEdit](https://github.com/platz1de/EasyEdit)

### block-conversion.json

Conversion table to convert numeric 1.12 java block ids to current bedrock ids (and in reverse)

- Blocks listed under "replace" can be replaced 1:1 without changing meta values, these blocks have just a different id
- Blocks listed under "complex" need to change their block id and meta values according to given java values

Additionally, blocks listed under "to-bedrock" do not have a corresponding bedrock block and will be replaced, blocks
listed under "to-java" do not have a corresponding java block

Primary data source:

- [Minecraft Wiki](https://minecraft.fandom.com/)
    - [Minecraft Java Data Values](https://minecraft.fandom.com/wiki/Java_Edition_data_values/Pre-flattening)

Special blocks:
- Item frames (bedrock 199) are represented as block entities in java

Resulting maps:

#### bedrock-conversion-map.json

Preprocessed java block to bedrock block conversion map

#### java-conversion-map.json

Preprocessed bedrock block to java block conversion map