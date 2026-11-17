# unraid-NV-status

**English** | [简体中文](README.zh-CN.md)

Real-time NVIDIA GPU monitoring tile for the Unraid dashboard — utilization, temperature,
fan, VRAM, ECC, PCIe link, encoder/decoder, power, clocks, and per-process VRAM usage
with the owning container or VM resolved.

No dependencies beyond the `nvidia-driver` plugin (which provides `nvidia-smi`).
Everything runs on the host: a PHP CLI sampler, an nchan push channel, and a dashboard tile.

![Dashboard tile](docs/tile.png)

---

## Features

- **Native-looking dashboard tile** — same typography, row height and progress-bar style as
  Unraid's built-in Processor / Memory tiles, so it doesn't stand out as a foreign widget
- **Three primary gauges on their own rows** — GPU load, temperature, VRAM, each with a
  colour-coded bar (blue → orange → red as it approaches the limit)
- **History chart** with GPU / VRAM / temperature overlaid, a legend, and a dynamic
  temperature scale so an idle card's curve isn't pinned flat to the bottom
- **Process table** — index, owning container/VM, VRAM, and command line, sorted by VRAM:
  - containers resolved from `/proc/<pid>/cgroup` (`0::/docker/<id>`) cross-referenced with
    `docker ps --no-trunc`
  - VMs resolved by walking the parent chain to `qemu-system` and reading `-name guest=<name>`
  - anything unmatched is labelled as the host
- **Refresh 0.5 s – 10 s** and **history window 1 – 60 min**, both switchable directly on the
  tile (remembered per browser); defaults are set in Settings
- **Two-tier sampling** so sub-second refresh costs almost nothing:
  - fast lane — a single long-lived `nvidia-smi --query-gpu=… -lms N` stream (~1 % CPU at 0.5 s)
  - slow lane — `nvidia-smi -q -x` every N seconds for ECC, PCIe replay, and the process list
- **nchan push** (`/sub/nvgpu`) for sub-second latency, with **automatic fallback to HTTP
  polling** — the tile never goes blank
- **Alert logic that avoids the usual false positives**:
  - PCIe "downgrade" is only reported while the GPU is actually busy (an idle card dropping
    to Gen1 is by design)
  - ECC counters render as `–` rather than `0` on non-ECC cards, so you never see phantom errors
- Multi-GPU aware data model (`gpus[]`), tolerant of fields that consumer cards lack

---

## Install

### From Community Applications (once listed)

Apps → search **NVIDIA GPU Status** → Install.

The listing is pending review; until it appears, use the manual method below.

### Manual (works right now)

Plugins → **Install Plugin** → paste:

```
https://raw.githubusercontent.com/xztjhs/unraid-NV-status/main/plugin/nvidia-gpu-status.plg
```

After install, open the Dashboard. If the tile doesn't appear, click the wrench icon
(**Tile Management**) and tick **NVIDIA GPU Status**, then Apply — that's normal Unraid
behaviour for a newly installed tile.

Settings live under **Settings → NVIDIA GPU Status**.

### Requirements

| Item | Requirement |
|---|---|
| Unraid | 7.0+ |
| Driver plugin | `nvidia-driver` (provides `/usr/bin/nvidia-smi`) |
| GPU | any NVIDIA card visible to `nvidia-smi` |

When `nvidia-smi` is missing the tile renders a hint instead of failing, and the sampler
refuses to start rather than filling the log with errors.

---

## Configuration

Everything is stored in `/boot/config/plugins/nvidia-gpu-status/nvidia-gpu-status.cfg`
and survives reboots and plugin upgrades.

| Key | Default | Meaning |
|---|---|---|
| `SAMPLE_MS` | `500` | Sampler poll interval (`200`–`2000`). Restarting the sampler applies it. |
| `REFRESH_MS` | `2000` | Default browser refresh (0.5–10 s) |
| `WINDOW_MIN` | `5` | Default history window (1–60 min) |
| `SLOW_SEC` | `10` | Interval for `nvidia-smi -q -x` (ECC, replay, processes) |
| `COLUMN` | `column3` | Dashboard column: `column1` / `column2` / `column3` |
| `TEMP_WARN` | `85` | Temperature warning threshold (°C) |
| `SHOW_PROCESSES` | `yes` | Show the per-process VRAM table |
| `PUSH` | `yes` | Enable nchan push |
| `ENABLED` | `yes` | Master switch (tile + sampler) |

The tile's two dropdowns override `REFRESH_MS` / `WINDOW_MIN` for that browser only
(`localStorage`), so a quick look at a 0.5 s refresh doesn't change anyone else's view.

---

## How it works

```
rc.nvidia-gpu-status  →  include/sampler.php  (daemon)
                           ├── nvidia-smi --query-gpu=<fast> -lms <SAMPLE_MS>   (stream)
                           └── nvidia-smi -q -x                                  (every SLOW_SEC)
                                     ↓ merge + derive health flags
                           /dev/shm/nvidia-gpu-status/latest.json   atomic write
                           /dev/shm/nvidia-gpu-status/hist.jsonl    1 Hz, last 60 min
                                     ↓
                     nchan /pub/nvgpu  ──ws──▶  browser tile
                     api.php (HTTP)    ──────▶  first paint + history + fallback
```

Measured on an RTX A5000 / Unraid 7.3.2 / driver 595.99:

| Operation | Cost |
|---|---|
| `nvidia-smi --query-gpu=<35 fields>` single shot | 88 ms |
| same with `-lms 500` (long-lived) | ~1 % CPU |
| `nvidia-smi -q -x` | 239 ms (every 10 s) |
| `nvidia-smi encodersessions` | **4050 ms** — never used |
| `api.php` (tmpfs read) | sub-millisecond |

Sub-second refreshing via a per-request `nvidia-smi` would cost ~18 % of a core at 0.5 s.
That is why the sampler is a daemon and the browser reads a tmpfs file instead.

Two details worth knowing:

- **The writer is atomic.** `latest.json` is written to a temp file and `rename()`d, so a
  reader can never see a half-written frame.
- **History is server-side decimated** to ≤360 points. A 1-minute window keeps full 1 s
  resolution; a 60-minute window is thinned to ~10 s. The response stays around 30 KB.

---

## Manual operations

```bash
bash /etc/rc.d/rc.nvidia-gpu-status status     # running? age of latest data?
bash /etc/rc.d/rc.nvidia-gpu-status restart    # after editing the cfg by hand
tail -f /var/log/nvidia-gpu-status.log
```

Environment self-check (also a button in Settings):

```bash
curl -s -b <cookie> "http://<tower>/plugins/nvidia-gpu-status/api.php?action=selftest"
```

The daemon re-reads the config every 15 s: changing `SAMPLE_MS` restarts only the fast child,
and setting `ENABLED="no"` makes it exit cleanly (the `.plg` restarts it on the next install).

---

## Build from source

```bash
./build.sh 1.0
# → archive/nvidia-gpu-status-1.0-x86_64-1.txz
# → plugin/nvidia-gpu-status.plg   (md5/sha256 injected)
```

`build.sh` validates the generated `.plg` as XML before finishing. This matters: `.plg` files
are XML, so shell operators inside `<INLINE>` must be written as entities (`&amp;&amp;`,
`&lt;`). A stray `&&` produces a plugin that fails to parse at install time.

Local install loop while developing (much faster than reinstalling via the UI):

```bash
installpkg /tmp/nvidia-gpu-status-1.0-x86_64-1.txz
bash /etc/rc.d/rc.nvidia-gpu-status restart
removepkg nvidia-gpu-status
```

---

## Layout

```
src/usr/local/emhttp/plugins/nvidia-gpu-status/
├── nvidia-gpu-status.Dashboard.page    tile registration (fills $mytiles)
├── nvidia-gpu-status.page              Settings page (Menu="Utilities")
├── api.php                             read-only JSON API
├── include/collect.php                 field parsing, normalisation, health flags
├── include/sampler.php                 daemon: two child processes + stream_select
├── include/i18n.php                    en / zh labels
├── javascript/gpu-status.js            rendering, push + polling, chart, process table
├── sheets/nvidia-gpu-status.Dashboard.css
└── icons/nvidia-gpu-status.png
src/etc/rc.d/rc.nvidia-gpu-status       start|stop|restart|status
```

### Gotchas that cost real time

- **Never nest `<table>`/`<tbody>` inside a tile.** Unraid's `addProperties()` calls
  `attr('title').md5()` on every `tbody` under `div.frame`. An inner one without a `title`
  throws, and the exception silently kills tile initialisation for the *entire* dashboard —
  every tile disappears, not just yours. Use `<div>`s.
- **`Menu="Utilities"` is what puts a page in Settings**, not `Menu="Settings"`. The page file
  name becomes the URL: `nvidia-gpu-status.page` → `/Settings/nvidia-gpu-status`.
- **`/boot` is vfat — there is no execute bit.** Every script must be invoked as
  `bash <script>`; `chmod +x` is a no-op there.
- **POSTs need `csrf_token`.** `local_prepend.php` rejects any POST without it, including
  your own AJAX endpoints.
- **`pkill -f <script>` matches your own SSH command** and kills the connection. Use
  `pgrep -af '[p]attern'` or kill by PID.
- Fields that may not exist (`ecc.errors.*` on non-ECC cards, `temperature.memory`,
  `fan.speed` on passively cooled cards) all normalise to `null` and render as `–`.
  The plugin never confuses "unsupported" with "zero".

---

## Versioning

Releases are tagged `vX.Y`. The `version` attribute in the `.plg`, the txz filename, and
`/boot/config/plugins/nvidia-gpu-status/version` must agree — otherwise Unraid's update
check can't tell that a newer build exists.

## License

MIT
