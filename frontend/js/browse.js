let browsePath = "/" + window.location.pathname.split("/").slice(3).join("/");
let heartbeat;
let initializing = true;
let selectedFile;
let selectedFileParentPath;
let extractingDmg = false;
let disconnected = false;
let extractedDmgs = [];

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

function pathNeedsDmgExtraction(path) {
  path = removeTrailingSlash(path);
  if (!path.includes(".dmg")) return false;

  let pathParts = path.split("/");
  for (let i = 0; i < pathParts.length; i++) {
    let part = pathParts[i];
    if (part.includes(".dmg")) {
      if (extractedDmgs.includes(part)) return false;
      return part;
    }
  }

  return false;
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

  function hideInfoViews() {
    for (let i = 0; i < infoView.children.length; i++) {
      let child = infoView.children[i];
      if (child.id != "info-view-file") child.classList.add("hidden");
    }
  }

  function changeInfoView(view) {
    hideInfoViews();
    view.classList.remove("hidden");
  }

  function getListingElementFromFilename(filename) {
    return listingFilesView.querySelector(`[data-filename="${filename}"]`);
  }

  function setSelectedFile(listingElement) {
    if (selectedFile) selectedFile.classList.remove("bg-slate-200", "dark:bg-zinc-700");
    selectedFile = listingElement;
    selectedFileParentPath = browsePath;
    listingElement.classList.add("bg-slate-200", "dark:bg-zinc-700");
  }

  function setInfoViewFileLabel(filename) {
    infoViewFilename.innerText = filename;

    let listingElement = getListingElementFromFilename(filename);
    if (listingElement) {
      infoViewIcon.src = listingElement.querySelector('[data-field="icon"]').src;
      infoViewIcon.alt = listingElement.querySelector('[data-field="icon"]').alt;
    }

    infoViewFile.classList.remove("hidden");
  }

  function sendCommand(command, args) {
    ws.send(JSON.stringify({"command": command, ...args}));
  }

  function navigateTo(path, pushState = true) {
    if (path.at(0) != "/")
      path = "/" + path;

    browsePath = path;
    listingFilesView.replaceChildren(listingPathText);
    listingPathText.classList.remove("invisible");
    listingPathText.innerHTML = browsePath.replaceAll("/", "<wbr>/");
    history.pushState({"path": path}, "", removeTrailingSlash(`${window.location.origin}/${ipswId}/browse${path}`));

    let dmgToExtract = pathNeedsDmgExtraction(path);
    if (dmgToExtract && !extractingDmg) {
      extractingDmg = dmgToExtract;
      setInfoViewFileLabel(dmgToExtract);
      changeInfoView(infoViewExtracting);
      extractingStatus.innerText = "Waiting...";
      extractingBarFill.style.width = "0%";
    }

    sendCommand("listing", {"location": path});
  }

  ws.onmessage = (event) => {
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
      } else if (data.status != "listing") {
        initInitPage();
        let percent = 0;

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
      if (data.status == "done") {
        extractingDmg = false;
        extractingStatus.innerText = "Done";
        extractingBarFill.style.width = "100%";
      } else if (data.status == "error") {
        extractingStatus.innerText = `Error: ${data.message}`;
        extractingBar.classList.add("hidden");
        extractingDmg = false;
      } else {
        let percent = 0;

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
      let listing = Object.entries(data.listing);
      let lastDirPos = -1;
      listing.forEach((file, index) => {
        if (file[1].is_dir) {
          lastDirPos++;
          listing.splice(index, 1);
          listing.splice(lastDirPos, 0, file);
        }
      });

      if (browsePath.split("/")[1] != "") {
        let parentEntry = listingFileTemplate.cloneNode(true);

        parentEntry.querySelector('[data-field="filename"]').innerText = "Parent directory";
        parentEntry.querySelector('[data-field="tag"]').classList.add("hidden");
        parentEntry.querySelector('[data-field="dir-arrow"]').classList.remove("hidden");
        parentEntry.querySelector('[data-field="icon"]').src = `${ASSETS_DIR}/parent-dir.svg`;
        parentEntry.querySelector('[data-field="icon"]').alt = "Up";

        parentEntry.onclick = () => {
          navigateTo(browsePath.split("/").slice(0, -1).join("/"));
        };

        parentEntry.classList.remove("hidden");
        listingFilesView.appendChild(parentEntry);
      }

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
        if (info.is_dir || DIR_LIKE_FILES.includes(extension))
          clone.querySelector('[data-field="dir-arrow"]').classList.remove("hidden");

        if (extractingDmg == filename) {
          clone.querySelector('[data-field="dir-arrow"]').src = "/assets/loader.svg";
          clone.querySelector('[data-field="dir-arrow"]').classList.add("animate-spin");
        }

        if (info.is_dir) {
          clone.querySelector('[data-field="icon"]').src = `${ASSETS_DIR}/dir.svg`;
          clone.querySelector('[data-field="icon"]').alt = "Directory";
        } else if (tag in TAG_ICONS) {
          clone.querySelector('[data-field="icon"]').src = `${ASSETS_DIR}/${TAG_ICONS[tag]}.svg`;
          clone.querySelector('[data-field="icon"]').alt = TAG_ICONS[tag];
        } else if (extension in EXTENSION_ICONS) {
          clone.querySelector('[data-field="icon"]').src = `${ASSETS_DIR}/${EXTENSION_ICONS[extension]}.svg`;
          clone.querySelector('[data-field="icon"]').alt = EXTENSION_ICONS[extension];
        }

        let targetPath = removeTrailingSlash(browsePath) + "/" + filename;
        clone.onclick = () => {
          let is_dir_like = DIR_LIKE_FILES.includes(extension);

          let clickedOtherDmgWhileExtracting = extractingDmg && is_dir_like && filename != extractingDmg;

          if (!info.is_dir && !info.extracted && !clickedOtherDmgWhileExtracting) {
            setSelectedFile(clone);
            setInfoViewFileLabel(filename, tag);
          }

          if ((info.is_dir || is_dir_like) && !clickedOtherDmgWhileExtracting) {
            navigateTo(targetPath);
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
        if (info.extracted && !extractedDmgs.includes(filename)) extractedDmgs.push(filename);
        listingFilesView.appendChild(clone);
      }
    }
  };

  window.onpopstate = (event) => {
    if (event.state && event.state.path) {
      navigateTo(event.state.path, false);
    } else {
      navigateTo("/", false);
    }
  };

  ws.onopen = () => {
    if (initializing) sendCommand("cache");

    heartbeat = setInterval(() => {
      sendCommand("ping");
    }, HEARTBEAT_INTERVAL);
  };

  ws.onerror = () => {
    listingPage.classList.add("invisible");
    initPage.classList.remove("invisible");
    initBar.classList.add("hidden");
    initStatus.innerText = "Error: Failed to connect to server";
    disconnected = true;
  };

  ws.onclose = () => {
    if (disconnected) return;
    clearInterval(heartbeat);
    listingPage.classList.add("invisible");
    initPage.classList.remove("invisible");
    initBar.classList.add("hidden");
    initStatus.innerText = "Disconnected from server, please refresh";
  };
};