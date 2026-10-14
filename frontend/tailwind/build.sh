#!/usr/bin/env bash
# Builds frontend/assets/css/app.css with the Tailwind v4 standalone CLI (no Node needed).
#   ./frontend/tailwind/build.sh          one-off minified build
#   ./frontend/tailwind/build.sh --watch  rebuild on change while developing
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BIN="$DIR/tailwindcss"
VERSION="${TAILWIND_VERSION:-v4.3.3}"

if [ ! -x "$BIN" ]; then
    case "$(uname -s)-$(uname -m)" in
        Darwin-arm64)  asset=tailwindcss-macos-arm64 ;;
        Darwin-x86_64) asset=tailwindcss-macos-x64 ;;
        Linux-aarch64|Linux-arm64) asset=tailwindcss-linux-arm64 ;;
        Linux-x86_64)  asset=tailwindcss-linux-x64 ;;
        MINGW*|MSYS*|CYGWIN*) asset=tailwindcss-windows-x64.exe ;;
        *) echo "Unsupported platform: $(uname -s)-$(uname -m)" >&2; exit 1 ;;
    esac
    echo "Downloading Tailwind CSS $VERSION ($asset)..."
    curl -fsSL -o "$BIN" "https://github.com/tailwindlabs/tailwindcss/releases/download/$VERSION/$asset"
    chmod +x "$BIN"
fi

cd "$DIR/../.."
if [ "${1:-}" = "--watch" ]; then
    exec "$BIN" -i frontend/tailwind/input.css -o frontend/assets/css/app.css --watch
fi
exec "$BIN" -i frontend/tailwind/input.css -o frontend/assets/css/app.css --minify
