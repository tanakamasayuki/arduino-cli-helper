rm arduino-cli
wget https://downloads.arduino.cc/arduino-cli/arduino-cli_latest_Linux_64bit.tar.gz
tar zxvf arduino-cli_latest_Linux_64bit.tar.gz
rm arduino-cli_latest_Linux_64bit.tar.gz LICENSE.txt

./arduino-cli config add board_manager.additional_urls https://espressif.github.io/arduino-esp32/package_esp32_index.json
./arduino-cli config add board_manager.additional_urls https://m5stack.oss-cn-shenzhen.aliyuncs.com/resource/arduino/package_m5stack_index.json

./arduino-cli core update-index
#./arduino-cli core install esp32:esp32
#./arduino-cli core install m5stack:esp32
./arduino-cli core upgrade

./arduino-cli board listall

php run.php
php libraries.php

