rm arduino-cli
wget https://downloads.arduino.cc/arduino-cli/arduino-cli_latest_Linux_64bit.tar.gz
tar zxvf arduino-cli_latest_Linux_64bit.tar.gz
rm arduino-cli_latest_Linux_64bit.tar.gz LICENSE.txt

./arduino-cli config add board_manager.additional_urls https://espressif.github.io/arduino-esp32/package_esp32_index.json
./arduino-cli config add board_manager.additional_urls https://m5stack.oss-cn-shenzhen.aliyuncs.com/resource/arduino/package_m5stack_index.json
./arduino-cli config add board_manager.additional_urls https://arduino.esp8266.com/stable/package_esp8266com_index.json
./arduino-cli config add board_manager.additional_urls https://files.seeedstudio.com/arduino/package_seeeduino_boards_index.json
./arduino-cli config add board_manager.additional_urls https://github.com/sonydevworld/spresense-arduino-compatible/releases/download/generic/package_spresense_index.json
./arduino-cli config add board_manager.additional_urls https://github.com/stm32duino/BoardManagerFiles/raw/main/package_stmicroelectronics_index.json

./arduino-cli core update-index
#./arduino-cli core install arduino:avr
#./arduino-cli core install arduino:renesas_uno
#./arduino-cli core install esp32:esp32
#./arduino-cli core install m5stack:esp32
#./arduino-cli core install esp8266:esp8266
#./arduino-cli core install SPRESENSE:spresense
#./arduino-cli core install Seeeduino:imxrt
#./arduino-cli core install Seeeduino:mbed
#./arduino-cli core install Seeeduino:nrf52
#./arduino-cli core install Seeeduino:renesas_uno
#./arduino-cli core install Seeeduino:samd
#./arduino-cli core install Seeeduino:stm32
#./arduino-cli core install STMicroelectronics:stm32

./arduino-cli core upgrade

./arduino-cli board listall

php run.php
php libraries.php

