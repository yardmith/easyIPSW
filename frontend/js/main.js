const ASSETS_DIR = "/assets";
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
  "heic": "image",
  "heif": "image",
  "tif": "image",
  "tiff": "image",
  "svg": "image",
  "eps": "image",

  "dmg": "dmg",
  "aea": "dmg",

  "plist": "list",
  "json": "list",
  "xml": "list",
  "strings": "list",

  "txt": "text"
};
const TAG_ICONS = {
  "ibootim": "image",
  "applelogo": "applelogo",
  "iboot": "iboot",
  "llb": "iboot",
  "ibss": "iboot",
  "ibec": "iboot"
};

function bytesToUnitsString(bytes) {
  if (!bytes && bytes !== 0) return "Unknown";

  let units = ["Bytes", "KiB", "MiB", "GiB"];
  let unit = 0;
  let amount = bytes;

  while (true) {
    if (amount < 1024) {
      break;
    }
    unit += 1;
    amount /= 1024;
  }

  return `${Math.round(amount)} ${units[unit]}`;
}

function removeTrailingSlash(path) {
  while (path.at(-1) == "/") {
    path = path.slice(0, -1);
  }
  return path;
}

window.onload = () => {
  const initPage = document.getElementById("init-page");
  const initBarFill = document.getElementById("init-progress-bar-fill");
  const initStatus = document.getElementById("init-status-text");
  let initPageInitialized = false;
  function initInitPage() {
    if (initPageInitialized) return;
    initPage.classList.remove("invisible");
    initBarFill.style.width = "0%";
    initPageInitialized = true;
  }

  const listingPage = document.getElementById("listing-page");
  const listingPathText = document.getElementById("listing-path");
  const listingFilesView = document.getElementById("listing-files-view");
  const listingFileTemplate = document.getElementById("listing-file-template");
  listingFileTemplate.remove();

  const ipswId = window.location.pathname.split("/")[1];
  const ws = new WebSocket(`wss://${window.location.host}/${ipswId}/ws`);
  let browsePath = "/" + window.location.pathname.split("/").slice(3).join("/");
  let initializing = true;

  function sendCommand(command, args) {
    ws.send(JSON.stringify({"command": command, ...args}));
  }

  function navigateTo(path, pushState = true) {
    if (path != browsePath && pushState)
      history.pushState({"path": path}, "", removeTrailingSlash(`${window.location.origin}/${ipswId}/browse${path}`));

    if (path.at(0) != "/")
      path = "/" + path;

    browsePath = path;
    sendCommand("listing", {"location": path});
  }

  ws.addEventListener("message", () => {
    let data = JSON.parse(event.data);

    if (initializing) {
      if (data.status == "done") {
        initializing = false;
        initBarFill.style.width = "100%";
        initStatus.innerText = "Ready";
        setTimeout(() => {
          if (initPageInitialized) initPage.classList.add("invisible");
          listingPage.classList.remove("invisible");
          navigateTo(browsePath);
        }, initPageInitialized ? 1000 : 0);
      } else if (data.status == "error") {
        initInitPage();
        initStatus.innerText = `Error: ${data.message}`;
        ws.close();
      } else {
        initInitPage();
        percent = 0;

        let bytesDone;
        let bytesTotal;
        let statusText;
        if (data.status == "downloading") {
          bytesDone = data.bytes_downloaded;
          bytesTotal = data.bytes_total;
          percent = data.bytes_downloaded / data.bytes_total * 100;
          statusText = "Downloading IPSW...";
        } else {
          bytesDone = data.bytes_extracted;
          bytesTotal = data.bytes_total;
          percent = data.bytes_extracted / data.bytes_total * 100;
          statusText = "Extracting IPSW...";
        }
        bytesDone = bytesToUnitsString(bytesDone);
        bytesTotal = bytesToUnitsString(bytesTotal);
        if (bytesDone.split(" ")[1] == bytesTotal.split(" ")[1]) bytesDone = bytesDone.split(" ")[0];
        initStatus.innerText = `${statusText} (${bytesDone}/${bytesTotal})`;

        if ("steps_done" in data && "steps_total" in data) {
          percent = percent / data.steps_total;
          percent += 100 / data.steps_total * data.steps_done;
        }

        percent = Math.round(percent);
        initBarFill.style.width = `${percent}%`;
      }
    }

    if (data.status == "listing") {
      listingFilesView.replaceChildren(listingPathText);
      listingPathText.classList.remove("invisible");
      listingPathText.innerText = browsePath;

      if (browsePath.split("/")[1] != "") {
        let parentEntry = listingFileTemplate.cloneNode(true);

        parentEntry.querySelector('[data-field="filename"]').innerText = "Parent directory";
        parentEntry.querySelector('[data-field="tag"]').classList.add("hidden");
        parentEntry.querySelector('[data-field="dir-arrow"]').classList.remove("hidden");
        parentEntry.querySelector('[data-field="icon"]').src = `${ASSETS_DIR}/parent-dir.svg`;

        parentEntry.onclick = () => {
          navigateTo(browsePath.split("/").slice(0, -1).join("/"));
        };

        parentEntry.classList.remove("hidden");
        listingFilesView.appendChild(parentEntry);
      }

      let listing = Object.entries(data.listing);
      let lastDirPos = -1;
      listing.forEach((file, index) => {
        if (file[1].is_dir) {
          lastDirPos++;
          listing.splice(index, 1);
          listing.splice(lastDirPos, 0, file);
        }
      });

      for (const [filename, info] of listing) {
        let clone = listingFileTemplate.cloneNode(true);
        clone.querySelector('[data-field="filename"]').innerText = filename;

        let tag = null;
        if ("tag" in info) {
          tag = info.tag.split("|")[0];
          if (tag in TAG_FRIENDLY_NAMES) {
            clone.querySelector('[data-field="tag"]').innerText = `(${TAG_FRIENDLY_NAMES[tag]})`;
            clone.querySelector('[data-field="tag"]').classList.remove("hidden");
          }
        }

        let extension = filename.split(".").pop();
        if (info.is_dir || extension == "dmg" || extension == "aea") 
          clone.querySelector('[data-field="dir-arrow"]').classList.remove("hidden");

        if (info.is_dir) {
          clone.querySelector('[data-field="icon"]').src = `${ASSETS_DIR}/dir.svg`;
        } else if (tag in TAG_ICONS) {
          clone.querySelector('[data-field="icon"]').src = `${ASSETS_DIR}/${TAG_ICONS[tag]}.svg`;
        } else if (extension in EXTENSION_ICONS) {
          clone.querySelector('[data-field="icon"]').src = `${ASSETS_DIR}/${EXTENSION_ICONS[extension]}.svg`;
        }

        clone.onclick = () => {
          if (info.is_dir) {
            navigateTo(removeTrailingSlash(browsePath) + "/" + filename);
          }
        };

        clone.classList.remove("hidden");
        listingFilesView.appendChild(clone);
      }
    }
  });

  window.onpopstate = (event) => {
    if (event.state && event.state.path) {
      navigateTo(event.state.path, false);
    } else {
      navigateTo("/", false);
    }
  };

  ws.addEventListener("open", (event) => {
    if (initializing) sendCommand("cache");
  });
};