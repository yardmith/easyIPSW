let heartbeat;
let initializing = true;
let selectedFile;
let selectedFileParentPath;
let extractingDmg = false;
let decryptingDmg = false;
let disconnected = false;
let extractedDmgs = [];
let dmgInfo = [];

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

  return `${Math.round(amount * 10) / 10} ${units[unit]}`;
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

function getIconNameForFile(filename, tag = null) {
  let extension = filename.split(".").pop();

  if (tag in TAG_ICONS) {
    return TAG_ICONS[tag];
  } else if (extension in EXTENSION_ICONS) {
    return EXTENSION_ICONS[extension];
  }

  return "file";
}

window.onload = () => {
  let browsePath = removeTrailingSlash("/" + window.location.pathname.split("/").slice(3).join("/"));

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
  const infoViewFileStats = document.getElementById("info-view-file-stats");
  const infoViewIcon = document.getElementById("info-view-stats-icon");
  const infoViewFilename = document.getElementById("info-view-stats-filename");
  const infoViewTag = document.getElementById("info-view-stats-tag");
  const infoViewSize = document.getElementById("info-view-stats-size");
  const infoViewDownloadLink = document.getElementById("info-view-stats-download");
  const infoViewStart = document.getElementById("info-start");
  const infoViewExtracting = document.getElementById("info-extracting");
  const extractingBar = document.getElementById("extracting-progress-bar");
  const extractingBarFill = document.getElementById("extracting-progress-bar-fill");
  const extractingStatus = document.getElementById("extracting-status-text");

  const contextMenu = document.getElementById("context-menu");
  const contextMenuFilename = document.getElementById("context-menu-filename");
  const contextMenuInfo = document.getElementById("context-menu-info");
  const contextMenuDownload = document.getElementById("context-menu-download");
  const contextMenuDownloadRaw = document.getElementById("context-menu-download-raw");
  const contextMenuDownloadXml = document.getElementById("context-menu-download-xml");
  const contextMenuDownloadJson = document.getElementById("context-menu-download-json");
  const contextMenuDownloadDecrypted = document.getElementById("context-menu-download-decrypted");

  const isMouse = window.matchMedia("(pointer: fine)").matches;
  const ipswId = window.location.pathname.split("/")[1];
  const wsProtocol = window.location.protocol == "https:" ? "wss" : "ws";
  const ws = new WebSocket(`${wsProtocol}://${window.location.host}/${ipswId}/ws`);

  function getRawUrl(filename, path = browsePath) {
    if (path == "/") path = "";
    return `/${ipswId}/raw${path}/${filename}`;
  }

  function hideInfoViews() {
    for (let i = 0; i < infoView.children.length; i++) {
      let child = infoView.children[i];
      if (child.id != infoViewFileStats.id) child.classList.add("hidden");
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

  function setInfoViewFileStats(filename, size, tag = null) {
    infoViewFilename.innerText = filename;
    
    if (tag && TAG_FRIENDLY_NAMES[tag]) {
      infoViewTag.innerText = `(${TAG_FRIENDLY_NAMES[tag]})`;
      infoViewTag.classList.remove("hidden");
    } else {
      infoViewTag.classList.add("hidden");
    }

    infoViewSize.innerText = bytesToUnitsString(size);

    let icon = getIconNameForFile(filename, tag);
    infoViewIcon.src = `${ASSETS_DIR}/${icon}.svg`;
    infoViewIcon.alt = icon;

    infoViewDownloadLink.href = getRawUrl(filename);

    infoViewFileStats.classList.remove("hidden");
  }

  function sendCommand(command, args) {
    ws.send(JSON.stringify({"command": command, ...args}));
  }

  function navigateTo(path, pushState = true) {
    if (path.at(0) != "/")
      path = "/" + path;

    let dmgToExtract = pathNeedsDmgExtraction(path);
    if (dmgToExtract && !extractingDmg) {
      extractingDmg = dmgToExtract;
      setInfoViewFileStats(dmgToExtract, dmgInfo[dmgToExtract].size, dmgInfo[dmgToExtract].tag);
      changeInfoView(infoViewExtracting);
      extractingStatus.innerText = "Waiting...";
      extractingBarFill.style.width = "0%";
      listingPathText.innerHTML += LISTING_PATH_EXTRACTING_TEXT;
    }

    browsePath = removeTrailingSlash(path);
    listingFilesView.replaceChildren();
    listingPathText.classList.remove("invisible");
    listingPathText.innerHTML = browsePath == "" ? "/" : browsePath.replaceAll("/", "<wbr>/");
    if (pushState) history.pushState({"path": path}, "", removeTrailingSlash(`${window.location.origin}/${ipswId}/browse${path}`));

    sendCommand("listing", {"location": path});
  }

  function dismissContextMenu() {
    contextMenu.classList.remove("opacity-100", "visible");
    contextMenu.classList.add("opacity-0");
    contextMenu.style.left = "0";
    contextMenu.style.top = "0";
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
          if (pathNeedsDmgExtraction(browsePath))
            sendCommand("dmginfo", {"path": browsePath});
          else
            navigateTo(browsePath, false);
        }, initPage.classList.contains("invisible") ? 0 : 1000);
      } else if (data.status == "error") {
        initInitPage();
        initStatus.innerText = `Error: ${data.message}`;
        initBar.classList.add("hidden");
        ws.close();
      } else if (data.status == "downloading" || data.status == "extracting") {
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
        extractedDmgs.push(extractingDmg);
        extractingDmg = false;
        extractingStatus.innerText = "Done";
        extractingBarFill.style.width = "100%";

        if (decryptingDmg) {
          window.location.href = decryptingDmg;
          decryptingDmg = false;
        }
      } else if (data.status == "error") {
        extractingStatus.innerText = `Error: ${data.message}`;
        extractingBar.classList.add("hidden");
        extractingDmg = false;
      } else if (data.status == "decrypting" || data.status == "extracting") {
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
      listingPathText.innerHTML = listingPathText.innerHTML.replaceAll(LISTING_PATH_EXTRACTING_TEXT, "");

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
          clone.querySelector('[data-field="dir-arrow"]').src = `${ASSETS_DIR}/loader.svg`;
          clone.querySelector('[data-field="dir-arrow"]').classList.add("animate-spin");
        }

        if (info.is_dir) {
          clone.querySelector('[data-field="icon"]').src = `${ASSETS_DIR}/dir.svg`;
          clone.querySelector('[data-field="icon"]').alt = "Directory";
        } else {
          let icon = getIconNameForFile(filename, tag);
          clone.querySelector('[data-field="icon"]').src = `${ASSETS_DIR}/${icon}.svg`;
          clone.querySelector('[data-field="icon"]').alt = icon;
        }

        let targetPath = removeTrailingSlash(browsePath) + "/" + filename;
        clone.onclick = () => {
          let is_dir_like = DIR_LIKE_FILES.includes(extension);

          if ((info.is_dir || is_dir_like) && !(extractingDmg && is_dir_like && filename != extractingDmg)) {
            navigateTo(targetPath);
          }

          if (!info.is_dir && !is_dir_like) {
            setSelectedFile(clone);
            setInfoViewFileStats(filename, info.size, tag);
            // showFilePreview(filename);
          }
        };

        let openContextMenu = (event) => {
          contextMenuFilename.innerText = filename;

          if (info.is_dir) {
            contextMenuInfo.classList.add("hidden");
          } else {
            contextMenuInfo.classList.remove("hidden");
            contextMenuInfo.onclick = () => {
              dismissContextMenu();
              setSelectedFile(clone);
              setInfoViewFileStats(filename, info.size, tag);
              // showFilePreview(filename);
            };
          }

          contextMenuDownload.href = getRawUrl(filename);
          contextMenuDownload.onclick = dismissContextMenu;
          contextMenuDownloadRaw.onclick = dismissContextMenu;
          contextMenuDownloadXml.onclick = dismissContextMenu;
          contextMenuDownloadJson.onclick = dismissContextMenu;

          if (extension == "png") {
            contextMenuDownload.href = getRawUrl(filename) + "?defry";
            contextMenuDownloadRaw.classList.remove("hidden");
            contextMenuDownloadRaw.href = getRawUrl(filename);
          } else if (info.plist_type) {
            if (info.plist_type != "xml") {
              contextMenuDownloadXml.href = getRawUrl(filename) + "?xml";
              contextMenuDownloadXml.classList.remove("hidden");
            }
            contextMenuDownloadJson.href = getRawUrl(filename) + "?json";
            contextMenuDownloadJson.classList.remove("hidden");
          } else {
            contextMenuDownloadRaw.classList.add("hidden");
            contextMenuDownloadXml.classList.add("hidden");
            contextMenuDownloadJson.classList.add("hidden");
          }

          if (info.has_key === true) {
            contextMenuDownloadDecrypted.onclick = () => {
              if (DIR_LIKE_FILES.includes(extension)) {
                extractingDmg = filename;
                decryptingDmg = getRawUrl(filename) + "?decrypt";
                setInfoViewFileStats(filename, dmgInfo[filename].size, dmgInfo[filename].tag);
                changeInfoView(infoViewExtracting);
                extractingStatus.innerText = "Waiting...";
                extractingBarFill.style.width = "0%";
                
                sendCommand("decryptdmg", {"path": `${browsePath}/${filename}`});
              } else {
                window.location.href = getRawUrl(filename) + "?decrypt";
              }

              dismissContextMenu();
            };
            contextMenuDownloadDecrypted.classList.remove("hidden");
          } else {
            contextMenuDownloadDecrypted.classList.add("hidden");
          }

          let newY = event.clientY;
          let newX = event.clientX;
          if (newY + contextMenu.offsetHeight > window.innerHeight) newY = window.innerHeight - contextMenu.offsetHeight;
          if (newX + contextMenu.offsetWidth > window.innerWidth) newX = window.innerWidth - contextMenu.offsetWidth;

          contextMenu.classList.remove("opacity-0");
          contextMenu.classList.add("opacity-100", "visible");
          contextMenu.style.left = `${newX}px`;
          contextMenu.style.top = `${newY}px`;
        };

        clone.oncontextmenu = (event) => {
          event.preventDefault();
          if (!isMouse) return;
          openContextMenu(event);
        };

        let holdTimeout = null;
        clone.onpointerdown = (event) => {
          if (isMouse) return;
          holdTimeout = setTimeout(() => {
            openContextMenu(event);
          }, MOBILE_CONTEXT_MENU_HOLD_SECONDS * 1000);
        };
        clone.onpointerup = () => {
          if (isMouse) return;
          clearTimeout(holdTimeout);
        };
        clone.onpointerleave = () => {
          if (isMouse) return;
          clearTimeout(holdTimeout);
        };

        if (selectedFile && selectedFileParentPath == browsePath && filename == selectedFile.getAttribute("data-filename")) {
          selectedFile = clone;
          clone.classList.add("bg-slate-200", "dark:bg-zinc-700");
        }
        if (info.has_key === false) clone.querySelector('[data-field="no-key"]').classList.remove("hidden");
        clone.removeAttribute("id");
        clone.setAttribute("data-filename", filename);
        clone.classList.remove("hidden");
        if (filename.includes(".dmg"))
          dmgInfo[filename] = {
            "size": info.size,
            "tag": tag
          };
        if (info.extracted && !extractedDmgs.includes(filename)) extractedDmgs.push(filename);
        listingFilesView.appendChild(clone);
      }
    } else if (data.status == "dmginfo") {
      let filename = pathNeedsDmgExtraction(data.path);
      if (data.extracted) extractedDmgs.push(filename);
      dmgInfo[filename] = {
        "size": data.size,
        "tag": data.tag.split("|")[0]
      };
      navigateTo(data.path, false);
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

  document.onclick = (event) => {
    if (!contextMenu.contains(event.target)) dismissContextMenu();
  };
};