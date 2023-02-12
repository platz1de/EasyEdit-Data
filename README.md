Data files used by [EasyEdit](https://github.com/platz1de/EasyEdit)

## Mappings to convert between minecraft bedrock and java

Data sources:

- [Minecraft Wiki](https://minecraft.fandom.com/)
- [PrismarineJS](https://github.com/PrismarineJS/minecraft-data/)
- [GeyserMC](https://github.com/GeyserMC/mappings/)

| Mapping                    | Usage                                          | Format                                |
|----------------------------|------------------------------------------------|---------------------------------------|
| legacy-conversion-map.json | Legacy java numeric ID to bedrock state        | javaID -> bedrockState                |
| java-to-bedrock.json       | Current java to bedrock states                 | javaState -> bedrockState             |
| bedrock-to-java.json       | Bedrock state to java state                    | bedrockState -> javaState             |
| rotation-data.json         | Clockwise bedrock state rotation               | bedrockState -> rotatedState          |
| flip-data.json             | Flip bedrock state on axis                     | axi: bedrockState -> flippedState     |