const ASSETS_DIR = "/assets";
const HEARTBEAT_INTERVAL = 30000;
const MOBILE_CONTEXT_MENU_HOLD_SECONDS = 0.5;

const LISTING_PATH_EXTRACTING_TEXT = " - <wbr>Extracting...";

const TAG_FRIENDLY_NAMES = {
  "applelogo": "Boot logo",
  "devicetree": "Device tree",
  "kernelcache": "Kernelcache",
  "rootfs": "Root filesystem",
  "ramdisk_restore": "Restore ramdisk",
  "ramdisk_update": "Update ramdisk",
  "llb": "LLB",
  "ibec": "iBEC",
  "ibss": "iBSS",
  "iboot": "iBoot"
};
const EXTENSION_ICONS = {
  "png": "image",
  "jpg": "image",
  "jpeg": "image",
  "gif": "image",
  "webp": "image",
  "svg": "image",

  "dmg": "dmg",
  "aea": "dmg",

  "plist": "list",
  "json": "list",
  "xml": "list",
  "strings": "list",

  "txt": "text",

  "mp3": "audio",
  "wav": "audio",
  "m4a": "audio",
  "m4r": "audio",
  "ogg": "audio",
  "flac": "audio",
  "aiff": "audio",
  "caf": "audio",
  "opus": "audio"
};
const TAG_ICONS = {
  "ibootim": "image",
  "applelogo": "applelogo",
  "iboot": "iboot",
  "llb": "iboot",
  "ibss": "iboot",
  "ibec": "iboot"
};
const DIR_LIKE_FILES = ["dmg", "aea"];