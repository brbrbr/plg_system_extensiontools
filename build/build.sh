#!/bin/bash
EXTENSION_ZIP_FILENAME="build/plg_system_extensiontools.zip"
EXTENSION_ELEMENT="extensiontools"
PATTERN="5\.1\.[0-9]\+"
VERSION="5.1.4"
if [ ! -f "$EXTENSION_ELEMENT.xml" ]; then cd ..; fi
if [ -f "$EXTENSION_ZIP_FILENAME" ]; then rm $EXTENSION_ZIP_FILENAME; fi

sed -i -e "s/$PATTERN/$VERSION/g" $EXTENSION_ELEMENT.xml
sed -i -e "s/$PATTERN/$VERSION/g" 51.sh

zip -r $EXTENSION_ZIP_FILENAME language/ "$EXTENSION_ELEMENT.xml"  services/ forms/ src/ script.php --quiet

SHA512=$(sha512sum $EXTENSION_ZIP_FILENAME | awk '{print $1}')
sed -i -e "s/\(<sha512>\).*\(<\/sha512>\)/<sha512>$SHA512<\/sha512>/g"  \
 -e "s/$PATTERN/$VERSION/g" \
  update.xml
echo 'package and update server ready'
