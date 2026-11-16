#!/bin/bash
#
# build.sh :: 打 txz + 生成 .plg（自动注入 MD5/SHA256）
#
# 用法： ./build.sh [版本号]
#   版本号默认 2026.11.16（同日多次构建请手工给 -1/-2 后缀）
#
# 产物：
#   archive/nvidia-gpu-status-<ver>-x86_64-1.txz
#   plugin/nvidia-gpu-status.plg
#
set -euo pipefail

cd "$(dirname "$0")"

PLUGIN="nvidia-gpu-status"
AUTHOR="xztjhs"
REPO="unraid-NV-status"
BRANCH="main"
VER="${1:-2026.11.16}"
PKGVER="${PLUGIN}-${VER}-x86_64-1"
ROOT="$(pwd)"
BUILD="$ROOT/.build"
PKGDIR="$BUILD/$PKGVER"

RAW="https://raw.githubusercontent.com/${AUTHOR}/${REPO}/${BRANCH}"

echo "==> clean"
rm -rf "$BUILD"
mkdir -p "$BUILD" "$ROOT/archive" "$ROOT/plugin"

echo "==> stage package tree"
mkdir -p "$PKGDIR"
# 运行期文件：/usr/local/emhttp/plugins/<name>/
mkdir -p "$PKGDIR/usr/local/emhttp/plugins/$PLUGIN"
cp -a "$ROOT/src/usr/local/emhttp/plugins/$PLUGIN/." "$PKGDIR/usr/local/emhttp/plugins/$PLUGIN/"
# rc 脚本
mkdir -p "$PKGDIR/etc/rc.d"
cp -a "$ROOT/src/etc/rc.d/rc.$PLUGIN" "$PKGDIR/etc/rc.d/"

# Slackware 包元数据（installpkg 必需）
mkdir -p "$PKGDIR/install"
cat > "$PKGDIR/install/slack-desc" <<EOF

$PLUGIN: Unraid NVIDIA GPU status dashboard tile
$PLUGIN:
$PLUGIN: Realtime GPU monitoring for the Unraid dashboard:
$PLUGIN: utilization, temperature, fan, VRAM, ECC, PCIe link,
$PLUGIN: encoder/decoder, power, clocks and per-process VRAM usage.
$PLUGIN:
$PLUGIN: Requires the nvidia-driver plugin (provides nvidia-smi).
$PLUGIN:

EOF

# 权限：脚本 755，其余 644
find "$PKGDIR" -type f ! -name slack-desc -exec chmod 644 {} \;
chmod 755 "$PKGDIR/etc/rc.d/rc.$PLUGIN"
chmod 644 "$PKGDIR/install/slack-desc"

echo "==> tar"
TXZ="$ROOT/archive/$PKGVER.txz"
( cd "$PKGDIR" && tar -cJf "$TXZ" install etc usr )
MD5=$(md5sum "$TXZ" | awk '{print $1}')
SHA=$(sha256sum "$TXZ" | awk '{print $1}')
SIZE=$(stat -c %s "$TXZ")
echo "    $TXZ"
echo "    size=$SIZE md5=$MD5"

echo "==> render .plg"
PLG="$ROOT/plugin/$PLUGIN.plg"
sed -e "s|@VER@|$VER|g" \
    -e "s|@PKGVER@|$PKGVER|g" \
    -e "s|@MD5@|$MD5|g" \
    -e "s|@SHA256@|$SHA|g" \
    -e "s|@RAW@|$RAW|g" \
    -e "s|@AUTHOR@|$AUTHOR|g" \
    -e "s|@PLUGIN@|$PLUGIN|g" \
    "$ROOT/plg/$PLUGIN.plg.in" > "$PLG"

echo "==> verify"
# .plg 是 XML：内联 shell 里的 && / < / > 必须写成实体，否则 installpkg 前就解析失败
python3 - <<'PYEOF'
import sys, xml.dom.minidom as m
try:
    d = m.parse('plugin/nvidia-gpu-status.plg')
    root = d.documentElement
    assert root.getAttribute('name') == 'nvidia-gpu-status', 'name mismatch'
    assert root.getAttribute('version'), 'missing version'
    assert root.getAttribute('pluginURL'), 'missing pluginURL'
    urls = [u.firstChild.data for u in d.getElementsByTagName('URL')]
    assert urls, 'no <URL> element'
    md5s = [e.firstChild.data for e in d.getElementsByTagName('MD5') if e.firstChild]
    assert md5s, 'no <MD5> injected'
    print('    xml ok · version=%s · url=%s' % (root.getAttribute('version'), urls[0]))
except Exception as e:
    print('    XML INVALID: %s' % e)
    sys.exit(1)
PYEOF

echo
echo "done:"
echo "  archive/$(basename "$TXZ")"
echo "  plugin/$PLUGIN.plg"
echo
echo "安装地址："
echo "  ${RAW}/plugin/${PLUGIN}.plg"
