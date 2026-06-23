import * as C from "./constants.js";

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
  const initBar = document.getElementById("init-progress-bar");
  const initBarFill = document.getElementById("init-progress-bar-fill");
  const initStatus = document.getElementById("init-status-text");

  const listingPage = document.getElementById("listing-page");
  const listingPathText = document.getElementById("listing-path");
  const listingFilesView = document.getElementById("listing-files-view");
  const listingFileTemplate = document.getElementById("listing-file-template");
  listingFileTemplate.remove();

  const infoView = document.getElementById("listing-info-view");
  const infoViewFile = document.getElementById("info-view-file");
  const infoViewIcon = document.getElementById("info-view-icon");
  const infoViewFilename = document.getElementById("info-view-filename");
  const infoViewStart = document.getElementById("info-start");
  const infoViewExtracting = document.getElementById("info-extracting");
  const extractingBar = document.getElementById("extracting-progress-bar");
  const extractingBarFill = document.getElementById("extracting-progress-bar-fill");
  const extractingStatus = document.getElementById("extracting-status-text");

  const ipswId = window.location.pathname.split("/")[1];
  const ws = new WebSocket(`wss://${window.location.host}/${ipswId}/ws`);
  let browsePath = "/" + window.location.pathname.split("/").slice(3).join("/");
  let initializing = true;
  let selectedFile = null;
  let selectedFileParentPath = null;
  let extractingDmg = false;
  let pushStateOnNextListing = false;
  let disconnected = false;

  function sendCommand(command, args) {
    ws.send(JSON.stringify({"command": command, ...args}));
  }

  function navigateTo(path, pushState = true) {
    if (path.at(0) != "/")
      path = "/" + path;

    browsePath = path;

    pushStateOnNextListing = pushState;
    sendCommand("listing", {"location": path});
  }

  function hideInfoViews() {
    for (let i = 0; i < infoView.children.length; i++) {
      let child = infoView.children[i];
      if (child.id != "info-view-file") child.classList.add("hidden");
    }
  }

  ws.addEventListener("message", () => {
    let data = JSON.parse(event.data);

    if (initializing) {
      function initInitPage() {
        if (!initPage.classList.contains("invisible")) return;
        initPage.classList.remove("invisible");
        initBarFill.style.width = "0%";
      }

      if (data.status == "done") {
        initializing = false;
        initBarFill.style.width = "100%";
        initStatus.innerText = "Ready";
        setTimeout(() => {
          initPage.classList.add("invisible");
          listingPage.classList.remove("invisible");
          navigateTo(browsePath, false);
        }, initPage.classList.contains("invisible") ? 0 : 1000);
      } else if (data.status == "error") {
        initInitPage();
        initStatus.innerText = `Error: ${data.message}`;
        initBar.classList.add("hidden");
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

    if (extractingDmg) {
      if (data.status == "listing") {
        extractingDmg = false;
        extractingStatus.innerText = "Done";
        extractingBarFill.style.width = "100%";
      } else if (data.status == "error") {
        extractingStatus.innerText = `Error: ${data.message}`;
        extractingBar.classList.add("hidden");
        extractingDmg = false;
      } else {
        percent = 0;

        let bytesDone;
        let bytesTotal;
        if (data.status == "decrypting") {
          bytesDone = data.bytes_decrypted;
          bytesTotal = data.bytes_total;
          percent = data.bytes_decrypted / data.bytes_total * 100;

          bytesDone = bytesToUnitsString(bytesDone);
          bytesTotal = bytesToUnitsString(bytesTotal);
          if (bytesDone.split(" ")[1] == bytesTotal.split(" ")[1]) bytesDone = bytesDone.split(" ")[0];
          extractingStatus.innerText = `Decrypting ${extractingDmg}... (${bytesDone}/${bytesTotal})`;
        } else {
          percent = data.percent_completed;
          extractingStatus.innerText = `Extracting ${extractingDmg}... (${percent}%)`;
        }

        if ("steps_done" in data && "steps_total" in data) {
          percent = percent / data.steps_total;
          percent += 100 / data.steps_total * data.steps_done;
        }

        percent = Math.round(percent);
        extractingBarFill.style.width = `${percent}%`;
      }
    }

    if (data.status == "listing") {
      if (pushStateOnNextListing)
        history.pushState({"path": browsePath}, "", removeTrailingSlash(`${window.location.origin}/${ipswId}/browse${browsePath}`));
        pushStateOnNextListing = false;

      listingFilesView.replaceChildren(listingPathText);
      listingPathText.classList.remove("invisible");
      listingPathText.innerHTML = browsePath.replaceAll("/", "<wbr>/");

      if (browsePath.split("/")[1] != "") {
        let parentEntry = listingFileTemplate.cloneNode(true);

        parentEntry.querySelector('[data-field="filename"]').innerText = "Parent directory";
        parentEntry.querySelector('[data-field="tag"]').classList.add("hidden");
        parentEntry.querySelector('[data-field="dir-arrow"]').classList.remove("hidden");
        parentEntry.querySelector('[data-field="icon"]').src = `${C.ASSETS_DIR}/parent-dir.svg`;
        parentEntry.querySelector('[data-field="icon"]').alt = "Up";

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
          if (tag in C.TAG_FRIENDLY_NAMES) {
            clone.querySelector('[data-field="tag"]').innerText = `(${C.TAG_FRIENDLY_NAMES[tag]})`;
            clone.querySelector('[data-field="tag"]').classList.remove("hidden");
          }
        }

        let extension = filename.split(".").pop();
        if (info.is_dir || C.DIR_LIKE_FILES.includes(extension)) 
          clone.querySelector('[data-field="dir-arrow"]').classList.remove("hidden");

        if (info.is_dir) {
          clone.querySelector('[data-field="icon"]').src = `${C.ASSETS_DIR}/dir.svg`;
          clone.querySelector('[data-field="icon"]').alt = "Directory";
        } else if (tag in C.TAG_ICONS) {
          clone.querySelector('[data-field="icon"]').src = `${C.ASSETS_DIR}/${C.TAG_ICONS[tag]}.svg`;
          clone.querySelector('[data-field="icon"]').alt = C.TAG_ICONS[tag];
        } else if (extension in C.EXTENSION_ICONS) {
          clone.querySelector('[data-field="icon"]').src = `${C.ASSETS_DIR}/${C.EXTENSION_ICONS[extension]}.svg`;
          clone.querySelector('[data-field="icon"]').alt = C.EXTENSION_ICONS[extension];
        }

        let targetPath = removeTrailingSlash(browsePath) + "/" + filename;
        clone.onclick = () => {
          if (info.is_dir) {
            navigateTo(targetPath);
          } else if (!info.extracted) {
            if (selectedFile) selectedFile.classList.remove("bg-slate-200", "dark:bg-zinc-700");
            selectedFile = clone;
            selectedFileParentPath = browsePath;
            clone.classList.add("bg-slate-200", "dark:bg-zinc-700");

            infoViewFilename.innerText = filename;
            infoViewIcon.src = clone.querySelector('[data-field="icon"]').src;
            infoViewIcon.alt = clone.querySelector('[data-field="icon"]').alt;
            infoViewFile.classList.remove("hidden");
            infoViewStart.classList.add("hidden");
          }

          if (C.DIR_LIKE_FILES.includes(extension) && !extractingDmg) {
            extractingDmg = !info.extracted ? filename : false;
            navigateTo(targetPath);
            if (extractingDmg) {
              hideInfoViews();
              clone.querySelector('[data-field="dir-arrow"]').src = `${C.ASSETS_DIR}/loader.svg`;
              clone.querySelector('[data-field="dir-arrow"]').classList.add("animate-spin");
              extractingBarFill.style.width = "0%";
              extractingStatus.innerText = "Waiting...";
              infoViewExtracting.classList.remove("hidden");
            }
          }
        };

        if (selectedFile && selectedFileParentPath == browsePath && filename == selectedFile.getAttribute("data-filename")) {
          selectedFile = clone;
          clone.classList.add("bg-slate-200", "dark:bg-zinc-700");
        }
        if (info.no_key) clone.querySelector('[data-field="no-key"]').classList.remove("hidden");
        clone.removeAttribute("id");
        clone.setAttribute("data-filename", filename);
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
    setTimeout(() => {
      sendCommand("ping");
    }, C.HEARTBEAT_INTERVAL);
  });
  ws.addEventListener("error", (event) => {
    listingPage.classList.add("invisible");
    initPage.classList.remove("invisible");
    initBar.classList.add("hidden");
    initStatus.innerText = "Error: Failed to connect to server";
    disconnected = true;
  });
  ws.addEventListener("close", () => {
    if (disconnected) return;
    listingPage.classList.add("invisible");
    initPage.classList.remove("invisible");
    initBar.classList.add("hidden");
    initStatus.innerText = "Disconnected from server, please refresh";
  });
};