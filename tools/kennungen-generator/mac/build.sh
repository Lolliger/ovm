#!/bin/bash
# Builds "OMUN Kennungen.app" (for Apple-silicon and Intel Macs) into ../dist/.
# Needs the Xcode command line tools:  xcode-select --install
set -euo pipefail
cd "$(dirname "$0")"
APP="../dist/OMUN Kennungen.app"
rm -rf "$APP" build
mkdir -p build "$APP/Contents/MacOS" "$APP/Contents/Resources"

for arch in arm64 x86_64; do
  swiftc -O -target "$arch-apple-macos12.0" main.swift List.swift -o "build/omun-$arch"
done
lipo -create build/omun-arm64 build/omun-x86_64 -output "$APP/Contents/MacOS/OMUN Kennungen"

cp ../app/index.html "$APP/Contents/Resources/index.html"

# App icon from the OMUN emblem
ICON=../../../assets/img/apple-touch-icon.png
if [ -f "$ICON" ]; then
  mkdir -p build/icon.iconset
  for s in 16 32 128 256 512; do
    sips -z $s $s "$ICON" --out "build/icon.iconset/icon_${s}x${s}.png" >/dev/null
    sips -z $((s * 2)) $((s * 2)) "$ICON" --out "build/icon.iconset/icon_${s}x${s}@2x.png" >/dev/null
  done
  iconutil -c icns build/icon.iconset -o "$APP/Contents/Resources/AppIcon.icns"
fi

cat > "$APP/Contents/Info.plist" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>CFBundleName</key><string>OMUN Kennungen</string>
  <key>CFBundleDisplayName</key><string>OMUN Kennungen</string>
  <key>CFBundleIdentifier</key><string>eu.omun.kennungen</string>
  <key>CFBundleExecutable</key><string>OMUN Kennungen</string>
  <key>CFBundleIconFile</key><string>AppIcon</string>
  <key>CFBundlePackageType</key><string>APPL</string>
  <key>CFBundleShortVersionString</key><string>$(cat ../../../VERSION 2>/dev/null || echo 1.0)</string>
  <key>CFBundleVersion</key><string>1</string>
  <key>LSMinimumSystemVersion</key><string>12.0</string>
  <key>NSHighResolutionCapable</key><true/>
  <key>NSDesktopFolderUsageDescription</key><string>Die Liste der vergebenen Kennungen (OMUN-Kennungen.csv) liegt auf dem Schreibtisch.</string>
  <key>NSDocumentsFolderUsageDescription</key><string>Die Liste der vergebenen Kennungen (OMUN-Kennungen.csv) liegt in einem Ordner, den ihr im Programm gewählt habt.</string>
  <key>NSDownloadsFolderUsageDescription</key><string>Die Liste der vergebenen Kennungen (OMUN-Kennungen.csv) liegt in einem Ordner, den ihr im Programm gewählt habt.</string>
</dict></plist>
PLIST

xattr -cr "$APP"
codesign --force --deep -s - "$APP"   # ad-hoc signature, needed on Apple-silicon Macs
rm -rf build
( cd ../dist && rm -f "OMUN-Kennungen-Mac.zip" && ditto -c -k --keepParent "OMUN Kennungen.app" "OMUN-Kennungen-Mac.zip" )
echo "Fertig: $APP"
